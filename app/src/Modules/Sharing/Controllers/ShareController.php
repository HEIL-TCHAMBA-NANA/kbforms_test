<?php
namespace Modules\Sharing\Controllers;

use Modules\Sharing\Services\ShareService;

class ShareController
{
    private ShareService $service;

    public function __construct()
    {
        $this->service = new ShareService();
    }

    /**
     * POST /forms/{id}/share-settings
     * Protégé (JWT requis).
     *
     * Body JSON :
     *   { "password": "secret" }  → active la protection
     *   { "password": null }      → supprime la protection
     */
    public function setShareSettings(int $formId): void
    {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $hasKey  = array_key_exists('password', $body);

        if (!$hasKey) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing field: password (use null to remove protection)']);
            return;
        }

        $password = $body['password'];

        if ($password !== null && (strlen($password) < 4 || strlen($password) > 72)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Password must be between 4 and 72 characters']);
            return;
        }

        $ok = $this->service->setPassword($formId, $password);

        if (!$ok) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Form not found']);
            return;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success'   => true,
            'protected' => $password !== null,
        ]);
    }

    /**
     * POST /f/{token}/unlock
     * Public — appelé par le frontend avant d'afficher un formulaire protégé.
     *
     * Body JSON : { "password": "secret" }
     *
     * Réponses :
     *   200  { "access": true }
     *   403  { "error": "Wrong password" }
     */
    public function unlock(string $token): void
    {
        $body     = json_decode(file_get_contents('php://input'), true) ?? [];
        $password = $body['password'] ?? null;

        if ($password === null) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Missing field: password']);
            return;
        }

        $result = $this->service->verifyPassword($token, $password);

        header('Content-Type: application/json');

        switch ($result) {
            case 'ok':
            case 'no_password_set':
                http_response_code(200);
                echo json_encode(['access' => true]);
                break;

            case 'wrong_password':
            default:
                http_response_code(403);
                echo json_encode(['error' => 'Wrong password']);
                break;
        }
    }
}
