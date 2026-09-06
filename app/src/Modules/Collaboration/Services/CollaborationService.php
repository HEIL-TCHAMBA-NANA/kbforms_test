<?php
namespace Modules\Collaboration\Services;

use Modules\Collaboration\Models\CollaborationModel;
use Modules\Collaboration\Models\InvitationModel;
use Modules\Identity\Models\UserModel;
use Modules\Form\Models\FormModel;
use Modules\Mail\MailService;

class CollaborationService
{
    private CollaborationModel $model;
    private InvitationModel $invites;

    private const VALID_ROLES = ['viewer', 'editor', 'admin'];

    /** Plans avec équipe : collaborateurs illimités. */
    private const TEAM_ACCOUNT_TYPES = ['enterprise', 'admin'];

    /** Limite de collaborateurs (acceptés + invitations en attente) — compte individuel. */
    private const INDIVIDUAL_COLLAB_LIMIT = 1;

    private const ROLE_LABELS = [
        'viewer' => 'lecture seule',
        'editor' => 'édition',
        'admin'  => 'administration',
    ];

    public function __construct()
    {
        $this->model   = new CollaborationModel();
        $this->invites = new InvitationModel();
    }

    /**
     * Ajoute ou met à jour un collaborateur (par user_id connu).
     * Utilisé en interne après acceptation d'une invitation.
     */
    public function add(int $formId, int $userId, string $role): array
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            return ['success' => false, 'error' => 'Rôle invalide.'];
        }

        $limit = $this->collaboratorLimitForForm($formId);
        if ($limit !== null && $this->model->getRole($formId, $userId) === null) {
            if ($this->seatCount($formId) >= $limit) {
                return $this->planLimitError($limit);
            }
        }

        $ok = $this->model->add($formId, $userId, $role);
        return $ok
            ? ['success' => true, 'form_id' => $formId, 'user_id' => $userId, 'role' => $role]
            : ['success' => false, 'error' => 'Échec de l\'ajout du collaborateur.'];
    }

    /**
     * Invite une adresse email à collaborer.
     * - crée/rafraîchit une invitation en attente (token)
     * - envoie un email avec le lien /invite?token=…
     * L'invité rejoint réellement le formulaire quand il accepte le lien
     * (en étant connecté avec cette même adresse).
     */
    public function invite(int $formId, string $email, string $role, ?int $invitedBy, string $origin): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Adresse email invalide.'];
        }
        if (!in_array($role, self::VALID_ROLES, true)) {
            return ['success' => false, 'error' => 'Rôle invalide.'];
        }

        $form = (new FormModel())->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'error' => 'Formulaire introuvable.'];
        }

        // Déjà collaborateur ?
        $existing = (new UserModel())->findByEmail($email);
        if ($existing && $this->model->getRole($formId, (int) $existing['id']) !== null) {
            return ['success' => false, 'error' => 'Cette personne est déjà collaboratrice.'];
        }
        // Propriétaire ?
        if ((int) ($form['user_id'] ?? 0) === (int) ($existing['id'] ?? -1)) {
            return ['success' => false, 'error' => 'Cette personne est propriétaire du formulaire.'];
        }

        // Limite de plan (sièges = collaborateurs acceptés + invitations en attente).
        $limit = $this->collaboratorLimitForForm($formId);
        $alreadyPending = $this->invites->findPending($formId, $email) !== null;
        if ($limit !== null && !$alreadyPending && $this->seatCount($formId) >= $limit) {
            return $this->planLimitError($limit);
        }

        $token = $this->invites->upsert($formId, $email, $role, $invitedBy);
        $link  = rtrim($origin, '/') . '/invite?token=' . $token;

        $inviterName = '';
        if ($invitedBy) {
            $u = (new UserModel())->findById($invitedBy);
            $inviterName = $u ? trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) : '';
        }

        $mail = $this->sendInviteEmail($email, $inviterName, $form['title'] ?? 'un formulaire', $role, $link);

        return [
            'success'    => true,
            'status'     => 'pending',
            'email'      => $email,
            'role'       => $role,
            'invite_url' => $link,
            'email_sent' => $mail['ok'],
            'email_transport' => $mail['transport'],
            'message'    => $mail['ok']
                ? "Invitation envoyée à {$email}."
                : "Invitation créée. L'email n'a pas pu être envoyé (transport non configuré) — partagez le lien manuellement.",
        ];
    }

    public function listInvitations(int $formId): array
    {
        return $this->invites->listByForm($formId);
    }

    public function revokeInvitation(int $formId, int $invitationId): array
    {
        return ['success' => $this->invites->revoke($formId, $invitationId)];
    }

    /** Détails d'une invitation pour la page d'acceptation (public). */
    public function invitationDetails(string $token): array
    {
        $inv = $this->invites->findByToken($token);
        if (!$inv) {
            return ['success' => false, 'error' => 'Invitation introuvable ou expirée.'];
        }
        return [
            'success'      => true,
            'status'       => $inv['status'],
            'email'        => $inv['email'],
            'role'         => $inv['role'],
            'role_label'   => self::ROLE_LABELS[$inv['role']] ?? $inv['role'],
            'form_id'      => (int) $inv['form_id'],
            'form_title'   => $inv['form_title'],
            'inviter_name' => $inv['inviter_name'] ?: null,
        ];
    }

    /**
     * L'utilisateur connecté ($userId / $userEmail) accepte l'invitation.
     * L'adresse du compte doit correspondre à celle invitée.
     */
    public function acceptInvitation(string $token, int $userId, string $userEmail): array
    {
        $inv = $this->invites->findByToken($token);
        if (!$inv) {
            return ['success' => false, 'error' => 'Invitation introuvable.'];
        }
        if ($inv['status'] === 'accepted') {
            return ['success' => true, 'form_id' => (int) $inv['form_id'], 'message' => 'Invitation déjà acceptée.'];
        }
        if ($inv['status'] !== 'pending') {
            return ['success' => false, 'error' => 'Cette invitation n\'est plus valide.'];
        }
        if (strtolower(trim($userEmail)) !== strtolower(trim($inv['email']))) {
            return [
                'success' => false,
                'code'    => 'email_mismatch',
                'error'   => "Cette invitation est destinée à {$inv['email']}. Connectez-vous avec cette adresse.",
            ];
        }

        $addRes = $this->model->add((int) $inv['form_id'], $userId, $inv['role']);
        if (!$addRes) {
            return ['success' => false, 'error' => 'Impossible de rejoindre le formulaire.'];
        }
        $this->invites->markAccepted((int) $inv['id']);

        return [
            'success' => true,
            'form_id' => (int) $inv['form_id'],
            'role'    => $inv['role'],
            'message' => 'Vous avez rejoint le formulaire.',
        ];
    }

    public function remove(int $formId, int $userId): array
    {
        return ['success' => $this->model->remove($formId, $userId)];
    }

    public function list(int $formId): array
    {
        return $this->model->list($formId);
    }

    public function getRole(int $formId, int $userId): ?string
    {
        return $this->model->getRole($formId, $userId);
    }

    public function canAccess(int $formId, int $userId): bool
    {
        return $this->model->canAccess($formId, $userId);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function seatCount(int $formId): int
    {
        return count($this->model->list($formId)) + $this->invites->countPending($formId);
    }

    private function planLimitError(int $limit): array
    {
        return [
            'success' => false,
            'code'    => 'plan_limit',
            'error'   => "Les comptes individuels sont limités à {$limit} collaborateur par formulaire "
                       . "(invitations en attente comprises). Passez à un compte entreprise pour inviter toute votre équipe.",
        ];
    }

    private function collaboratorLimitForForm(int $formId): ?int
    {
        $form = (new FormModel())->getFormById($formId);
        if (!$form || empty($form['user_id'])) {
            return self::INDIVIDUAL_COLLAB_LIMIT;
        }
        $owner = (new UserModel())->findById((int) $form['user_id']);
        $type  = $owner['account_type'] ?? 'individual';
        return in_array($type, self::TEAM_ACCOUNT_TYPES, true) ? null : self::INDIVIDUAL_COLLAB_LIMIT;
    }

    private function sendInviteEmail(string $email, string $inviterName, string $formTitle, string $role, string $link): array
    {
        $who     = $inviterName !== '' ? $inviterName : 'Un utilisateur de KBForms';
        $roleLbl = self::ROLE_LABELS[$role] ?? $role;
        $subject = "Invitation à collaborer sur « {$formTitle} » — KBForms";

        $text =
            "Bonjour,\n\n" .
            "{$who} vous invite à collaborer sur le formulaire « {$formTitle} » " .
            "avec le rôle « {$roleLbl} ».\n\n" .
            "Pour rejoindre le formulaire, ouvrez ce lien :\n{$link}\n\n" .
            "Si vous n'avez pas encore de compte KBForms, créez-en un avec cette adresse email, " .
            "puis rouvrez le lien.\n\n" .
            "Si vous ne vous attendiez pas à cette invitation, ignorez simplement cet email.\n\n" .
            "— L'équipe KBForms";

        $safeLink  = htmlspecialchars($link, ENT_QUOTES);
        $safeWho   = htmlspecialchars($who, ENT_QUOTES);
        $safeForm  = htmlspecialchars($formTitle, ENT_QUOTES);
        $safeRole  = htmlspecialchars($roleLbl, ENT_QUOTES);
        $html =
            '<div style="font-family:Inter,Arial,sans-serif;max-width:480px;margin:auto;color:#1f2937">' .
            "<p><strong>{$safeWho}</strong> vous invite à collaborer sur le formulaire " .
            "<strong>« {$safeForm} »</strong> avec le rôle <strong>{$safeRole}</strong>.</p>" .
            '<p style="margin:24px 0">' .
            "<a href=\"{$safeLink}\" style=\"background:#4F46E5;color:#fff;text-decoration:none;" .
            'padding:12px 20px;border-radius:10px;display:inline-block;font-weight:600">Rejoindre le formulaire</a></p>' .
            "<p style=\"color:#6b7280;font-size:13px\">Ou copiez ce lien :<br>{$safeLink}</p>" .
            '<p style="color:#6b7280;font-size:13px">Si vous n\'avez pas encore de compte KBForms, ' .
            'créez-en un avec cette adresse email puis rouvrez le lien.</p>' .
            '<p style="color:#6b7280;font-size:13px">— L\'équipe KBForms</p></div>';

        return (new MailService())->send($email, $subject, $text, $html);
    }
}
