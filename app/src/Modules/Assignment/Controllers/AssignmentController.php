<?php
namespace Modules\Assignment\Controllers;

use Modules\Assignment\Services\AssignmentService;
use Core\AuthMiddleware;

class AssignmentController
{
    private AssignmentService $service;

    public function __construct()
    {
        $this->service = new AssignmentService();
    }

    private function httpForCode(string $code): int
    {
        return match ($code) {
            'forbidden' => 403,
            'not_found' => 404,
            default     => 400,
        };
    }

    // ── GET /me/assignments — les assignations de l'utilisateur connecté ────
    public function myAssignments(): void
    {
        header('Content-Type: application/json');
        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        echo json_encode($this->service->myAssignments((int) $user->user_id));
    }

    // ── GET /forms/{id}/assignments — liste (superviseur) ──────────────────
    public function listForForm(int $formId): void
    {
        header('Content-Type: application/json');
        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        $res = $this->service->listForForm($formId, (int) $user->user_id);
        if (!($res['success'] ?? false)) {
            http_response_code($this->httpForCode($res['code'] ?? ''));
        }
        echo json_encode($res);
    }

    // ── POST /forms/{id}/assignments — body { user_id, note? } ─────────────
    public function create(int $formId): void
    {
        header('Content-Type: application/json');
        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $toUser = (int) ($data['user_id'] ?? 0);
        $note   = array_key_exists('note', $data) ? (string) $data['note'] : null;

        $res = $this->service->assign($formId, $toUser, $note, (int) $user->user_id);
        if (!($res['success'] ?? false)) {
            http_response_code($this->httpForCode($res['code'] ?? ''));
        }
        echo json_encode($res);
    }

    // ── DELETE /forms/{id}/assignments/{userId} ────────────────────────────
    public function remove(int $formId, int $userId): void
    {
        header('Content-Type: application/json');
        $me = AuthMiddleware::getUser();
        if (!$me) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        $res = $this->service->unassign($formId, $userId, (int) $me->user_id);
        if (!($res['success'] ?? false)) {
            http_response_code($this->httpForCode($res['code'] ?? ''));
        }
        echo json_encode($res);
    }

    // ── PATCH /assignments/{id} — body { status } ──────────────────────────
    public function updateStatus(int $id): void
    {
        header('Content-Type: application/json');
        $me = AuthMiddleware::getUser();
        if (!$me) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return;
        }
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $status = (string) ($data['status'] ?? '');

        $res = $this->service->setStatus($id, $status, (int) $me->user_id);
        if (!($res['success'] ?? false)) {
            http_response_code($this->httpForCode($res['code'] ?? ''));
        }
        echo json_encode($res);
    }
}
