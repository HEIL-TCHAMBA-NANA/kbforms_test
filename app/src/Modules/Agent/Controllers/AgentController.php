<?php
namespace Modules\Agent\Controllers;

use Modules\Agent\Services\AgentService;
use Core\AuthMiddleware;

class AgentController
{
    private AgentService $service;

    public function __construct()
    {
        $this->service = new AgentService();
    }

    private function emit(array $res): void
    {
        if (!($res['success'] ?? false)) {
            http_response_code(match ($res['code'] ?? '') {
                'forbidden'         => 403,
                'not_found'         => 404,
                'form_unavailable'  => 403,
                default             => 400,
            });
        }
        echo json_encode($res);
    }

    private function body(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    private function uid(): ?int
    {
        $u = AuthMiddleware::getUser();
        if (!$u) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return null;
        }
        return (int) $u->user_id;
    }

    // ── POST /agent-login — public, body { identifiant, password } ─────────
    public function login(): void
    {
        header('Content-Type: application/json');
        $data        = $this->body();
        $identifiant = (string) ($data['identifiant'] ?? '');
        $password    = (string) ($data['password'] ?? '');
        if ($identifiant === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'identifiant et password sont requis']);
            return;
        }
        $res = $this->service->login($identifiant, $password);
        if (!($res['success'] ?? false)) {
            http_response_code(($res['code'] ?? '') === 'form_unavailable' ? 403 : 401);
        }
        echo json_encode($res);
    }

    // ── POST /forms/{id}/agents — body { display_name, email } ──────────────
    public function create(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $data = $this->body();
        $this->emit($this->service->create(
            $formId,
            (string) ($data['display_name'] ?? ''),
            (string) ($data['email'] ?? ''),
            $uid
        ));
    }

    // ── POST /forms/{id}/agents/{agentId}/regenerate-password ───────────────
    public function regeneratePassword(int $formId, int $agentId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->regeneratePassword($formId, $agentId, $uid));
    }

    // ── GET /forms/{id}/agents ───────────────────────────────────────────────
    public function listForForm(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->listForForm($formId, $uid));
    }

    // ── PATCH /forms/{id}/agents/{agentId} — body { is_revoked } ─────────────
    public function patch(int $formId, int $agentId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $data = $this->body();
        if (!array_key_exists('is_revoked', $data)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'is_revoked requis']);
            return;
        }
        $this->emit($this->service->setRevoked($formId, $agentId, (bool) $data['is_revoked'], $uid));
    }

    // ── DELETE /forms/{id}/agents/{agentId} ──────────────────────────────────
    public function delete(int $formId, int $agentId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->delete($formId, $agentId, $uid));
    }
}
