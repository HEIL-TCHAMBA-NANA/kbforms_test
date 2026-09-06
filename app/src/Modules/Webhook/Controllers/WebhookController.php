<?php
namespace Modules\Webhook\Controllers;

use Modules\Webhook\Services\WebhookService;
use Modules\Webhook\Models\WebhookModel;
use Core\AuthMiddleware;
use Core\FormGuard;

class WebhookController
{
    private WebhookService $service;

    public function __construct()
    {
        $this->service = new WebhookService();
    }

    /** Refuse (403/404) si l'utilisateur du jeton n'est pas admin du formulaire porteur du webhook. */
    private function guardWebhook(int $webhookId): bool
    {
        $wh  = (new WebhookModel())->getById($webhookId);
        if (!$wh) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Webhook introuvable.']);
            return false;
        }
        $uid = (int) (AuthMiddleware::getUser()->user_id ?? 0);
        if (!FormGuard::canAdmin((int) $wh['form_id'], $uid)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => "Vous n'avez pas accès à ce formulaire."]);
            return false;
        }
        return true;
    }

    /**
     * POST /forms/{id}/webhooks
     * Protégé. Body : { "url": "https://...", "secret": "optionnel" }
     */
    public function create(int $formId): void
    {
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $url    = $data['url']    ?? null;
        $secret = $data['secret'] ?? null;

        if (!$url) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing field: url']);
            return;
        }

        $result = $this->service->register($formId, $url, $secret);
        if (!$result['success']) http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * GET /forms/{id}/webhooks
     * Protégé. Liste les webhooks du formulaire (secret masqué).
     */
    public function list(int $formId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->listByForm($formId));
    }

    /**
     * DELETE /webhooks/{id}
     * Protégé.
     */
    public function delete(int $webhookId): void
    {
        header('Content-Type: application/json');
        if (!$this->guardWebhook($webhookId)) return;
        $result = $this->service->delete($webhookId);
        echo json_encode($result);
    }

    /**
     * PUT /webhooks/{id}/toggle
     * Protégé. Body optionnel : { "active": true|false }.
     * Sans body, l'état est simplement inversé.
     */
    public function toggle(int $webhookId): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        header('Content-Type: application/json');
        if (!$this->guardWebhook($webhookId)) return;

        if (array_key_exists('active', $data) && $data['active'] !== null) {
            echo json_encode($this->service->setActive($webhookId, (bool)$data['active']));
            return;
        }

        echo json_encode($this->service->toggle($webhookId));
    }
}
