<?php
namespace Modules\Notification\Controllers;

use Modules\Notification\Services\PushService;
use Core\AuthMiddleware;

class DeviceController
{
    private PushService $service;

    public function __construct()
    {
        $this->service = new PushService();
    }

    private function user(): ?object
    {
        $u = AuthMiddleware::getUser();
        if (!$u) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return null;
        }
        return $u;
    }

    // ── POST /me/devices — body { fcm_token, platform? } ───────────────────
    public function register(): void
    {
        header('Content-Type: application/json');
        if (!($u = $this->user())) {
            return;
        }
        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $token    = (string) ($data['fcm_token'] ?? $data['token'] ?? '');
        $platform = (string) ($data['platform'] ?? 'android');

        if (trim($token) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'fcm_token requis']);
            return;
        }
        $res = $this->service->registerDevice((int) $u->user_id, $token, $platform);
        if (!($res['success'] ?? false)) {
            http_response_code(400);
        }
        echo json_encode($res);
    }

    // ── DELETE /me/devices — body { fcm_token } ────────────────────────────
    // (le routeur ne sait pas capturer un jeton FCM dans l'URL : ':' et '_'
    //  hors de [a-zA-Z0-9]+, d'où le jeton dans le corps.)
    public function unregister(): void
    {
        header('Content-Type: application/json');
        if (!($u = $this->user())) {
            return;
        }
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = (string) ($data['fcm_token'] ?? $data['token'] ?? '');
        if (trim($token) === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'fcm_token requis']);
            return;
        }
        echo json_encode($this->service->unregisterDevice((int) $u->user_id, $token));
    }
}
