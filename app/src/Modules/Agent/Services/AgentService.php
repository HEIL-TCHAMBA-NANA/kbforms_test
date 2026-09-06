<?php
namespace Modules\Agent\Services;

require_once __DIR__ . '/../../Identity/Libraries/php-jwt/autoload.php';

use Modules\Agent\Models\FormAgentModel;
use Modules\Form\Models\FormModel;
use Modules\Collaboration\Models\CollaborationModel;
use Modules\Mail\MailService;
use Firebase\JWT\JWT;

/**
 * Agents de terrain scopés à une enquête (M4).
 *  - login()               : connexion agent (public), émet un jeton agent.
 *  - create/listForForm/…  : gestion réservée au PROPRIÉTAIRE ou à un
 *    collaborateur "admin" du formulaire (pas "editor" — cf. spec M4).
 */
class AgentService
{
    private FormAgentModel $model;

    // Même secret que AuthService/AuthMiddleware — dupliqué comme le reste du
    // code (pas de constante partagée existante pour ça dans ce dépôt).
    private string $jwtSecret = "kbforms_super_secret_key_2026_with_extra_entropy";

    // Sans caractères ambigus (0/O, 1/I/L) — identifiant et mot de passe sont
    // lus/tapés à la main sur un terrain.
    private const CHARSET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const AGENT_TOKEN_TTL = 30 * 86400; // 30 jours, pas de refresh (cf. spec)

    public function __construct()
    {
        $this->model = new FormAgentModel();
    }

    // ── Connexion agent (POST /agent-login, public) ─────────────────────────
    public function login(string $identifiant, string $password): array
    {
        $identifiant = trim($identifiant);
        $agent = $identifiant !== '' ? $this->model->findByIdentifiant($identifiant) : null;
        if (!$agent || !password_verify($password, $agent['password_hash'])) {
            return ['success' => false, 'error' => 'Identifiant ou mot de passe invalide.'];
        }

        $form = (new FormModel())->getFormById((int) $agent['form_id']);
        if ((bool) $agent['is_revoked'] || !$form || (int) ($form['is_published'] ?? 0) !== 1) {
            // Message volontairement identique, que ce soit l'agent ou toute
            // l'enquête qui est coupée — l'agent n'a pas à le savoir.
            return ['success' => false, 'code' => 'form_unavailable', 'error' => "Cette enquête n'est plus disponible."];
        }

        $this->model->updateLastLogin((int) $agent['id']);

        return [
            'success'      => true,
            'token'        => $this->issueAgentToken((int) $agent['id'], (int) $agent['form_id']),
            'form_id'      => (int) $agent['form_id'],
            'form_title'   => $form['title'] ?? '',
            'display_name' => $agent['display_name'],
        ];
    }

    public function issueAgentToken(int $agentId, int $formId): string
    {
        $payload = [
            'iat'      => time(),
            'exp'      => time() + self::AGENT_TOKEN_TTL,
            'agent_id' => $agentId,
            'form_id'  => $formId,
        ];
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    // ── Création d'un agent (propriétaire/admin) ────────────────────────────
    public function create(int $formId, string $displayName, string $email, int $managerId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $managerId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Réservé au propriétaire ou à un administrateur du formulaire.'];
        }
        $displayName = trim($displayName);
        if ($displayName === '') {
            return ['success' => false, 'error' => 'display_name requis.'];
        }
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'code' => 'invalid_email', 'error' => 'Une adresse e-mail valide est requise : les identifiants y seront envoyés.'];
        }

        $identifiant = $this->generateUniqueIdentifiant();
        $password    = $this->randomString(10);
        $hash        = password_hash($password, PASSWORD_BCRYPT);
        $id = $this->model->create($formId, $identifiant, $hash, $displayName, $email, $managerId);

        $mail = $this->sendCredentialsEmail($email, $displayName, (string) ($form['title'] ?? ''), $identifiant, $password, false);

        // Le mot de passe en clair n'est renvoyé qu'ici — jamais après.
        return [
            'success'      => true,
            'agent_id'     => $id,
            'identifiant'  => $identifiant,
            'password'     => $password,
            'display_name' => $displayName,
            'email'        => $email,
            'email_sent'   => $mail['ok'],
            'email_error'  => $mail['error'],
        ];
    }

    // ── Régénération du mot de passe (propriétaire/admin) ───────────────────
    // Le mot de passe d'origine n'est pas récupérable (haché) : on en génère
    // un nouveau, on l'affiche une fois et on le renvoie par e-mail. L'ancien
    // cesse aussitôt de fonctionner.
    public function regeneratePassword(int $formId, int $agentId, int $userId): array
    {
        [$ok, $err] = $this->guard($formId, $agentId, $userId);
        if (!$ok) {
            return $err;
        }
        $agent = $this->model->findById($agentId);
        if (!$agent) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Agent introuvable.'];
        }

        $password = $this->randomString(10);
        $this->model->updatePasswordHash($agentId, password_hash($password, PASSWORD_BCRYPT));

        $form  = (new FormModel())->getFormById($formId);
        $email = (string) ($agent['email'] ?? '');
        $mail  = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? $this->sendCredentialsEmail($email, (string) $agent['display_name'], (string) ($form['title'] ?? ''), (string) $agent['identifiant'], $password, true)
            : ['ok' => false, 'error' => 'Aucune adresse e-mail enregistrée pour cet agent.'];

        return [
            'success'      => true,
            'agent_id'     => $agentId,
            'identifiant'  => $agent['identifiant'],
            'password'     => $password,
            'display_name' => $agent['display_name'],
            'email'        => $email,
            'email_sent'   => $mail['ok'],
            'email_error'  => $mail['error'] ?? null,
        ];
    }

    public function listForForm(int $formId, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.'];
        }
        if (!$this->canManage($form, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.'];
        }
        return ['success' => true, 'agents' => $this->model->listForForm($formId)];
    }

    public function setRevoked(int $formId, int $agentId, bool $revoked, int $userId): array
    {
        [$ok, $err] = $this->guard($formId, $agentId, $userId);
        if (!$ok) {
            return $err;
        }
        $this->model->setRevoked($agentId, $revoked);
        return ['success' => true, 'agent_id' => $agentId, 'is_revoked' => $revoked];
    }

    public function delete(int $formId, int $agentId, int $userId): array
    {
        [$ok, $err] = $this->guard($formId, $agentId, $userId);
        if (!$ok) {
            return $err;
        }
        return ['success' => true, 'removed' => $this->model->delete($agentId)];
    }

    // ─────────────────────────────────────────────────────────────────────
    /** @return array{0:bool,1:array} */
    private function guard(int $formId, int $agentId, int $userId): array
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return [false, ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable.']];
        }
        if (!$this->canManage($form, $userId)) {
            return [false, ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé.']];
        }
        if (!$this->model->belongsToForm($agentId, $formId)) {
            return [false, ['success' => false, 'code' => 'not_found', 'error' => 'Agent introuvable pour ce formulaire.']];
        }
        return [true, []];
    }

    /** Propriétaire du formulaire ou collaborateur "admin" (pas "editor" — cf. spec M4). */
    private function canManage(array $form, int $userId): bool
    {
        if ((int) ($form['user_id'] ?? 0) === $userId) {
            return true;
        }
        return (new CollaborationModel())->getRole((int) $form['id'], $userId) === 'admin';
    }

    private function generateUniqueIdentifiant(): string
    {
        for ($i = 0; $i < 20; $i++) {
            $candidate = $this->randomString(8);
            if (!$this->model->identifiantExists($candidate)) {
                return $candidate;
            }
        }
        // Collision 20x de suite sur un charset de 32^8 : ne devrait jamais arriver.
        throw new \RuntimeException('Impossible de générer un identifiant agent unique.');
    }

    private function randomString(int $len): string
    {
        $out = '';
        $max = strlen(self::CHARSET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= self::CHARSET[random_int(0, $max)];
        }
        return $out;
    }

    /**
     * Envoie les identifiants à l'agent. Ne jette jamais : renvoie
     * ['ok' => bool, 'error' => ?string] (MailService journalise le détail).
     *
     * @param bool $isReset true = régénération de mot de passe, false = création.
     */
    private function sendCredentialsEmail(string $email, string $displayName, string $formTitle, string $identifiant, string $password, bool $isReset): array
    {
        // Domaines réservés (RFC 2606/6761) — tests, démo : pas d'envoi réel.
        if (preg_match('/\.(local|test|invalid|example)$/i', $email)) {
            return ['ok' => true, 'error' => null];
        }

        $appName = 'KBForms';
        $subject = $isReset
            ? "Nouveau mot de passe — enquête « {$formTitle} »"
            : "Vos accès à l'enquête « {$formTitle} »";

        $intro = $isReset
            ? "Le mot de passe de votre accès enquêteur a été réinitialisé. L'ancien ne fonctionne plus."
            : "Vous avez été ajouté(e) comme enquêteur de terrain sur l'enquête « {$formTitle} ».";

        $text = "Bonjour {$displayName},\n\n"
            . "{$intro}\n\n"
            . "Identifiant : {$identifiant}\n"
            . "Mot de passe : {$password}\n\n"
            . "Ouvrez l'application {$appName} sur votre téléphone, choisissez « Connexion enquêteur » "
            . "et saisissez ces identifiants. Ils ne donnent accès qu'à cette enquête.\n\n"
            . "Conservez ce message : le mot de passe ne pourra pas être réaffiché.\n";

        $esc  = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $html = "<p>Bonjour " . $esc($displayName) . ",</p>"
            . "<p>" . $esc($intro) . "</p>"
            . "<table cellpadding=\"6\" style=\"border-collapse:collapse;font-family:monospace;font-size:15px\">"
            . "<tr><td style=\"color:#666\">Identifiant</td><td><strong>" . $esc($identifiant) . "</strong></td></tr>"
            . "<tr><td style=\"color:#666\">Mot de passe</td><td><strong>" . $esc($password) . "</strong></td></tr>"
            . "</table>"
            . "<p>Ouvrez l'application <strong>" . $appName . "</strong> sur votre téléphone, choisissez "
            . "« Connexion enquêteur » et saisissez ces identifiants. Ils ne donnent accès qu'à cette enquête.</p>"
            . "<p style=\"color:#888;font-size:13px\">Conservez ce message : le mot de passe ne pourra pas être réaffiché.</p>";

        $res = (new MailService())->send($email, $subject, $text, $html);
        return ['ok' => (bool) ($res['ok'] ?? false), 'error' => $res['error'] ?? null];
    }
}
