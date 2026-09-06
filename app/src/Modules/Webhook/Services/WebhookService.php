<?php
namespace Modules\Webhook\Services;

use Modules\Webhook\Models\WebhookModel;

class WebhookService
{
    private WebhookModel $model;

    public function __construct()
    {
        $this->model = new WebhookModel();
    }

    // ── Gestion ───────────────────────────────────────────────────────────────

    public function register(int $formId, string $url, ?string $secret): array
    {
        if (empty(trim($url))) {
            return ['success' => false, 'error' => 'L\'URL du webhook est requise'];
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'URL invalide. Elle doit commencer par https:// ou http://'];
        }

        $id = $this->model->create($formId, $url, $secret ?: null);
        return ['success' => true, 'webhook_id' => $id];
    }

    public function listByForm(int $formId): array
    {
        $rows = $this->model->getByForm($formId);
        // Masquer le secret dans la liste
        return array_map(function ($w) {
            $w['secret'] = $w['secret'] ? '***' : null;
            return $w;
        }, $rows);
    }

    public function delete(int $webhookId): array
    {
        $ok = $this->model->delete($webhookId);
        return ['success' => $ok];
    }

    public function setActive(int $webhookId, bool $active): array
    {
        $ok = $this->model->setActive($webhookId, $active);
        return ['success' => $ok, 'is_active' => $active ? 1 : 0];
    }

    /**
     * Inverse l'état actif/inactif d'un webhook (lecture puis écriture).
     */
    public function toggle(int $webhookId): array
    {
        $wh = $this->model->getById($webhookId);
        if (!$wh) {
            return ['success' => false, 'error' => 'Webhook introuvable'];
        }
        $new = !((int) $wh['is_active']);
        $ok  = $this->model->setActive($webhookId, $new);
        return ['success' => $ok, 'is_active' => $new ? 1 : 0];
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Envoie l'événement à tous les webhooks actifs du formulaire.
     * Appelé par ResponseService après chaque soumission réussie.
     *
     * @param int    $formId
     * @param string $event    ex: "response.submitted"
     * @param array  $payload  données à envoyer
     */
    public function dispatch(int $formId, string $event, array $payload): void
    {
        $webhooks = $this->model->getActiveByForm($formId);
        if (empty($webhooks)) return;

        $body = json_encode([
            'event'      => $event,
            'form_id'    => $formId,
            'timestamp'  => date('c'),
            'data'       => $payload,
        ]);

        foreach ($webhooks as $wh) {
            $this->send($wh['url'], $body, $wh['secret'] ?? null);
        }
    }

    /**
     * Envoi HTTP POST avec signature HMAC optionnelle.
     * Fire-and-forget : les erreurs sont loguées mais n'affectent pas la réponse.
     */
    private function send(string $url, string $body, ?string $secret): void
    {
        $headers = [
            'Content-Type: application/json',
            'User-Agent: KBForms-Webhook/1.0',
        ];

        if ($secret !== null) {
            $sig = 'sha256=' . hash_hmac('sha256', $body, $secret);
            $headers[] = 'X-KBForms-Signature: ' . $sig;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        @file_get_contents($url, false, $ctx);
        // Les erreurs de livraison sont ignorées intentionnellement (fire-and-forget).
    }
}