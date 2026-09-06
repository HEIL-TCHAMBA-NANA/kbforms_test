<?php
namespace Modules\Identity\Services;

require_once __DIR__ . '/../Libraries/php-jwt/autoload.php';

use Modules\Identity\Entities\User;
use Modules\Identity\Models\UserModel;
use Modules\Identity\Models\PasswordResetModel;
use Modules\Identity\Models\RefreshTokenModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService {
    private UserModel $model;
    private PasswordResetModel $resets;
    private RefreshTokenModel $refreshTokens;
    private string $jwtSecret = "kbforms_super_secret_key_2026_with_extra_entropy";

    // Durée de vie du JWT d'accès. Le web garde 1 h (le refresh silencieux
    // prend le relais tant que l'onglet est actif). L'app mobile, hors-ligne
    // par nature, obtient 7 j : sur un appareil de terrain tué par l'OS puis
    // rouvert SANS réseau après plus d'une heure, l'enquêteur pouvait sinon
    // se retrouver bloqué sur l'écran de connexion alors que ses formulaires
    // et réponses en attente sont toujours en cache local. Le refresh token
    // (30 j) et le jeton d'accès cohabitant de toute façon dans le coffre
    // chiffré de l'appareil, un jeton d'accès plus long n'y change quasiment
    // rien côté sécurité.
    private const ACCESS_TTL_WEB    = 3600;      // 1 h
    private const ACCESS_TTL_MOBILE = 7 * 86400; // 7 j

    public function __construct() {
        $this->model         = new UserModel();
        $this->resets        = new PasswordResetModel();
        $this->refreshTokens = new RefreshTokenModel();
    }

    // ── Register ──────────────────────────────────────────────────────────────
    public function register(array $data): int {
        if (empty($data['first_name']) || empty($data['last_name'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Le prénom et le nom sont requis"]);
            exit;
        }
        if (empty($data['email']) || empty($data['password'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "L'email et le mot de passe sont requis"]);
            exit;
        }

        $accountType = $data['account_type'] ?? 'individual';

        if ($accountType === 'admin') {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Création de compte admin non autorisée"]);
            exit;
        }

        if (!in_array($accountType, ['individual', 'enterprise'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Type de compte invalide"]);
            exit;
        }

        $email = trim((string) $data['email']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Adresse email invalide"]);
            exit;
        }

        if ($this->model->findByEmail($email) !== null) {
            http_response_code(409);
            echo json_encode(["success" => false, "error" => "Un compte existe déjà avec cette adresse email."]);
            exit;
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        $user = new User(
            null,
            $data['first_name'],
            $data['last_name'],
            $email,
            $hashedPassword,
            $accountType,
            $data['organization']  ?? null,
            $data['industry']      ?? null,
            $data['company_size']  ?? null,
            $data['country']       ?? null,
            $data['phone']         ?? null,
            $data['website']       ?? null,
            $data['job_title']     ?? null,
            date('Y-m-d H:i:s')
        );

        try {
            return $this->model->create($user);
        } catch (\PDOException $e) {
            // Course entre deux inscriptions simultanées, ou contrainte d'unicité.
            if ($e->getCode() === '23000') {
                http_response_code(409);
                echo json_encode(["success" => false, "error" => "Un compte existe déjà avec cette adresse email."]);
                exit;
            }
            error_log('[AuthService] register failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(["success" => false, "error" => "Erreur serveur lors de la création du compte."]);
            exit;
        }
    }

    // ── Login ─────────────────────────────────────────────────────────────────
    public function login(string $email, string $password): ?string {
        $user = $this->model->findByEmail($email);
        if ($user && password_verify($password, $user['password'])) {
            return $this->generateToken($user);
        }
        return null;
    }

    /**
     * Login renvoyant la paire complète { token, refresh_token, refresh_expires_at }.
     * Utilisé par POST /login. Retourne null si identifiants invalides.
     */
    public function loginTokens(string $email, string $password): ?array {
        $user = $this->model->findByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            return null;
        }
        return $this->bundleTokens($user);
    }

    // ── Refresh token ─────────────────────────────────────────────────────────
    // Rétro-compat : renouvelle depuis un JWT d'accès **encore valide** (Bearer).
    // Le chemin principal (hors-ligne) passe par redeemRefreshToken().
    public function refresh(string $token): ?string {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            $userId  = (int) $decoded->user_id;
            $user    = $this->model->findById($userId);
            if (!$user) return null;
            return $this->generateToken($user);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Échange un refresh token opaque contre une nouvelle paire (rotation à usage
     * unique : l'ancien refresh est révoqué). Accepte un token d'accès expiré
     * puisqu'il n'est pas requis ici.
     * @return array{token:string, refresh_token:string, refresh_expires_at:string}|null
     */
    public function redeemRefreshToken(string $rawRefreshToken): ?array {
        $row = $this->refreshTokens->findValid($rawRefreshToken);
        if (!$row) return null;

        $user = $this->model->findById((int) $row['user_id']);
        if (!$user) return null;

        $this->refreshTokens->revoke((int) $row['id']);
        return $this->bundleTokens($user);
    }

    // ── Génération des jetons ────────────────────────────────────────────────
    /** JWT d'accès seul (compat : ex. flux Google historique). */
    public function issueToken(array $user): string {
        return $this->generateToken($user);
    }

    /** Paire complète pour un utilisateur déjà authentifié (register, Google…). */
    public function issueTokens(array $user): array {
        return $this->bundleTokens($user);
    }

    /** JWT d'accès (1 h) + refresh token opaque (30 j). */
    private function bundleTokens(array $user): array {
        $refresh = $this->refreshTokens->issue((int) $user['id']);
        return [
            'token'              => $this->generateToken($user),
            'refresh_token'      => $refresh['token'],
            'refresh_expires_at' => $refresh['expires_at'],
        ];
    }

    private function generateToken(array $user): string {
        // Client mobile officiel reconnu (en-tête X-KBF-Client-Secret) → jeton long.
        $ttl = \Core\MobileClient::isTrusted() ? self::ACCESS_TTL_MOBILE : self::ACCESS_TTL_WEB;
        $payload = [
            "iss"          => "kbforms.local",
            "aud"          => "kbforms.local",
            "iat"          => time(),
            "exp"          => time() + $ttl,
            "user_id"      => $user['id'],
            "email"        => $user['email'],
            "account_type" => $user['account_type'],
        ];
        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    // ── Profil utilisateur ────────────────────────────────────────────────────
    public function getUser(int $id): ?array {
        return $this->model->findById($id);
    }

    public function updateUser(int $id, array $data): array {
        // ── Avatar (indépendant : peut être la seule chose envoyée) ──────────
        if (array_key_exists('avatar', $data)) {
            $avatar = $data['avatar'];
            if ($avatar === null || $avatar === '') {
                $this->model->updateAvatar($id, null);
            } else {
                if (!is_string($avatar)
                    || !preg_match('#^data:image/(png|jpe?g|webp);base64,#', $avatar)) {
                    return ["success" => false, "error" => "Format d'avatar invalide (image attendue)."];
                }
                if (strlen($avatar) > 1_500_000) { // ~1 Mo décodé
                    return ["success" => false, "error" => "Avatar trop lourd. Choisissez une image plus légère."];
                }
                $this->model->updateAvatar($id, $avatar);
            }
            $hasProfileFields = isset($data['first_name']) || isset($data['last_name']) || isset($data['name']);
            if (!$hasProfileFields) {
                return ["success" => true, "message" => "Avatar mis à jour"];
            }
        }

        // Accepter aussi { name: "Prénom Nom" } (page profil)
        if (empty($data['first_name']) && empty($data['last_name']) && !empty($data['name'])) {
            $parts = preg_split('/\s+/', trim((string) $data['name']), 2);
            $data['first_name'] = $parts[0] ?? '';
            $data['last_name']  = $parts[1] ?? $parts[0] ?? '';
        }

        $firstName    = $data['first_name']   ?? null;
        $lastName     = $data['last_name']    ?? null;
        $organization = $data['organization'] ?? null;
        $industry     = $data['industry']     ?? null;
        $companySize  = $data['company_size'] ?? null;
        $country      = $data['country']      ?? null;
        $phone        = $data['phone']        ?? null;
        $website      = $data['website']      ?? null;
        $jobTitle     = $data['job_title']    ?? null;

        if (!$firstName || !$lastName) {
            return ["success" => false, "error" => "Le prénom et le nom sont requis"];
        }

        $ok = $this->model->updateUser(
            $id, $firstName, $lastName, $organization,
            $industry, $companySize, $country, $phone, $website, $jobTitle
        );
        return ["success" => $ok, "message" => $ok ? "Profil mis à jour" : "Échec de la mise à jour"];
    }

    // ── Changement de mot de passe ────────────────────────────────────────────
    public function changePassword(int $id, array $data): ?array {
        if (!isset($data['current_password'], $data['new_password'])) {
            return null;
        }
        $user = $this->model->findById($id);
        if (!$user) {
            return ["success" => false, "error" => "Utilisateur introuvable"];
        }
        $userWithPwd = $this->model->findByEmail($user['email']);
        if (!$userWithPwd || !password_verify($data['current_password'], $userWithPwd['password'])) {
            return ["success" => false, "error" => "Mot de passe actuel incorrect"];
        }
        if (strlen($data['new_password']) < 8) {
            return ["success" => false, "error" => "Le nouveau mot de passe doit contenir au moins 8 caractères"];
        }
        $hashed = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $ok = $this->model->updatePassword($id, $hashed);
        return ["success" => $ok, "message" => $ok ? "Mot de passe modifié" : "Échec de la modification"];
    }

    // ── Suppression de compte ─────────────────────────────────────────────────
    public function deleteUser(int $id): array {
        $ok = $this->model->deleteUser($id);
        return ["success" => $ok, "message" => $ok ? "Compte supprimé" : "Échec de la suppression"];
    }

    // ── Mot de passe oublié : demande d'un code ──────────────────────────────
    public function requestPasswordReset(string $email): array {
        $email = trim(strtolower($email));
        $user  = $email ? $this->model->findByEmail($email) : null;

        // Anti-énumération : on renvoie toujours le même message,
        // mais on ne génère un code que si le compte existe.
        if ($user) {
            $code     = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $codeHash = password_hash($code, PASSWORD_BCRYPT);
            $this->resets->create($email, $codeHash, 15); // expire dans 15 min (horloge MySQL)
            $this->sendResetEmail($email, $code, $user['first_name'] ?? '');
        }

        $out = [
            'success' => true,
            'message' => "Si un compte existe pour cet email, un code de vérification vient d'être envoyé.",
        ];
        // Aide au développement local (aucun serveur mail branché) : renvoyer
        // le code directement. Activé par la présence du fichier config/dev-mode
        // ou la variable d'env KBF_DEV_RETURN_CODE. À retirer en production.
        if ($user && $this->devMode()) {
            $out['dev_code'] = $code;
        }
        return $out;
    }

    // ── Mot de passe oublié : vérification du code + nouveau mot de passe ────
    public function resetPassword(string $email, string $code, string $newPassword): array {
        $email = trim(strtolower($email));
        $code  = trim($code);

        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => "Le mot de passe doit contenir au moins 8 caractères"];
        }

        $row = $this->resets->findValid($email);
        if (!$row) {
            return ['success' => false, 'error' => "Code invalide ou expiré. Redemandez-en un."];
        }
        if ((int)$row['attempts'] >= 5) {
            $this->resets->markUsed((int)$row['id']);
            return ['success' => false, 'error' => "Trop de tentatives. Redemandez un code."];
        }

        $this->resets->incrementAttempts((int)$row['id']);
        if (!password_verify($code, $row['code_hash'])) {
            return ['success' => false, 'error' => "Code incorrect."];
        }

        $user = $this->model->findByEmail($email);
        if (!$user) {
            return ['success' => false, 'error' => "Compte introuvable."];
        }

        $this->resets->markUsed((int)$row['id']);
        $ok = $this->model->updatePassword((int)$user['id'], password_hash($newPassword, PASSWORD_BCRYPT));
        if (!$ok) {
            return ['success' => false, 'error' => "Échec de la mise à jour du mot de passe."];
        }

        return [
            'success' => true,
            'message' => "Mot de passe réinitialisé.",
            'token'   => $this->generateToken($user), // connexion immédiate
        ];
    }

    /** Mode développement : renvoie les codes dans la réponse et détaille les logs. */
    private function devMode(): bool {
        return getenv('KBF_DEV_RETURN_CODE')
            || is_file(__DIR__ . '/../../../../config/dev-mode');
    }

    // ── Envoi de l'email de réinitialisation ────────────────────────────────
    private function sendResetEmail(string $email, string $code, string $name): void {
        $greeting = $name !== '' ? "Bonjour $name," : "Bonjour,";
        $subject  = "Votre code de réinitialisation KBForms";
        $text =
            "$greeting\n\n" .
            "Voici votre code de vérification pour réinitialiser votre mot de passe KBForms :\n\n" .
            "    $code\n\n" .
            "Ce code expire dans 15 minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n\n" .
            "— L'équipe KBForms";

        $safeCode = htmlspecialchars($code, ENT_QUOTES);
        $safeGreeting = htmlspecialchars($greeting, ENT_QUOTES);
        $html =
            '<div style="font-family:Inter,Arial,sans-serif;max-width:480px;margin:auto;color:#1f2937">' .
            '<p>' . $safeGreeting . '</p>' .
            '<p>Voici votre code de vérification pour réinitialiser votre mot de passe KBForms :</p>' .
            '<p style="font-size:28px;font-weight:700;letter-spacing:6px;background:#f3f4f6;' .
            'border-radius:10px;padding:16px;text-align:center">' . $safeCode . '</p>' .
            '<p style="color:#6b7280;font-size:13px">Ce code expire dans 15 minutes. ' .
            "Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>" .
            '<p style="color:#6b7280;font-size:13px">— L\'équipe KBForms</p></div>';

        // Transport configurable (SMTP / mail() / log) — voir app/config/mail.php
        $res = (new \Modules\Mail\MailService())->send($email, $subject, $text, $html);

        // Trace dédiée « password reset » : le code reste consultable dans les
        // logs tant qu'aucun transport réel n'est branché (driver = 'log').
        $status = $res['ok'] ? $res['transport'] : 'ÉCHEC(' . ($res['error'] ?? '?') . ')';
        $line = date('c') . "  [$status]  $email  ->  code $code  (expire dans 15 min)\n";
        foreach ([
            __DIR__ . '/../../../../logs/password-resets.log',    // app/logs
            sys_get_temp_dir() . '/kbforms-password-resets.log',  // /tmp (toujours accessible)
        ] as $p) {
            if (@file_put_contents($p, $line, FILE_APPEND) !== false) break;
        }
        error_log("[password-reset] $email -> code $code (transport: $status)");
    }
}