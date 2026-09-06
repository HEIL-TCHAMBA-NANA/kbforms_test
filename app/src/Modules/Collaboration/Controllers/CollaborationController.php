<?php
namespace Modules\Collaboration\Controllers;

use Modules\Collaboration\Services\CollaborationService;
use Core\AuthMiddleware;

class CollaborationController
{
    private CollaborationService $service;

    public function __construct()
    {
        $this->service = new CollaborationService();
    }

    /** GET /forms/{id}/collaborators — liste des collaborateurs acceptés. */
    public function list(int $formId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->list($formId));
    }

    /** GET /forms/{id}/invitations — invitations en attente. */
    public function listInvitations(int $formId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->listInvitations($formId));
    }

    /**
     * POST /forms/{id}/collaborators
     * Body : { "email": "x@y.z", "role": "editor" }  → envoie une invitation par email
     *   ou : { "user_id": 42, "role": "editor" }      → ajout direct (usage interne)
     */
    public function add(int $formId): void
    {
        header('Content-Type: application/json');
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $data['user_id'] ?? null;
        $email  = $data['email']   ?? null;
        $role   = $data['role']    ?? 'viewer';

        if (!$userId && !$email) {
            http_response_code(400);
            echo json_encode(['error' => 'email (ou user_id) et role sont requis']);
            return;
        }

        if ($email) {
            $me = AuthMiddleware::getUser();
            $result = $this->service->invite(
                $formId,
                (string) $email,
                (string) $role,
                $me ? (int) $me->user_id : null,
                $this->origin()
            );
        } else {
            $result = $this->service->add($formId, (int) $userId, (string) $role);
        }

        if (!$result['success']) {
            http_response_code(($result['code'] ?? '') === 'plan_limit' ? 422 : 400);
        }
        echo json_encode($result);
    }

    /** DELETE /forms/{id}/collaborators/{user_id} */
    public function remove(int $formId, int $userId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->remove($formId, $userId));
    }

    /** DELETE /forms/{id}/invitations/{invitation_id} */
    public function revokeInvitation(int $formId, int $invitationId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->revokeInvitation($formId, $invitationId));
    }

    /** GET /invitations/{token} — détails publics pour la page d'acceptation. */
    public function showInvitation(string $token): void
    {
        header('Content-Type: application/json');
        $res = $this->service->invitationDetails($token);
        if (!$res['success']) http_response_code(404);
        echo json_encode($res);
    }

    /** POST /invitations/{token}/accept — protégé : l'utilisateur connecté rejoint. */
    public function acceptInvitation(string $token): void
    {
        header('Content-Type: application/json');
        $me = AuthMiddleware::getUser();
        if (!$me) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        $res = $this->service->acceptInvitation($token, (int) $me->user_id, (string) $me->email);
        if (!$res['success']) {
            http_response_code(($res['code'] ?? '') === 'email_mismatch' ? 403 : 400);
        }
        echo json_encode($res);
    }

    private function origin(): string
    {
        // Détecte l'hôte public exploitable (IP LAN auto si on est sur localhost).
        return \Core\AppUrl::base();
    }
}
