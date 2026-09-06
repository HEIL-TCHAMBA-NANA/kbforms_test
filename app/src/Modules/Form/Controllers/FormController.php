<?php
namespace Modules\Form\Controllers;
use Modules\Form\Services\FormService;
use Modules\Sharing\Services\ShareService;
use Core\AuthMiddleware;

class FormController {
    private FormService  $service;
    private ShareService $shareService;

    public function __construct() {
        $this->service      = new FormService();
        $this->shareService = new ShareService();
    }

    public function deleteForm($id) {
        $result = $this->service->deleteForm((int)$id);
        echo json_encode($result);
    }

    public function createForm() {
        $data        = json_decode(file_get_contents("php://input"), true);
        $title       = $data["title"]       ?? null;
        $description = $data["description"] ?? null;
        $bannerData  = $data["banner_data"] ?? null;

        // Le formulaire appartient à l'utilisateur authentifié — un user_id
        // fourni dans le corps est ignoré (on ne crée pas pour le compte d'un tiers).
        $me = AuthMiddleware::getUser();
        if (!$me) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }
        if (!$title) {
            http_response_code(400);
            echo json_encode(["error" => "title is required"]);
            return;
        }

        $result = $this->service->createForm((int)$me->user_id, $title, $description, $bannerData);
        echo json_encode($result);
    }

    public function listForms($userId) {
        // On ne liste que SES propres formulaires — pas ceux d'un autre compte.
        $me = AuthMiddleware::getUser();
        if (!$me || (int) $me->user_id !== (int) $userId) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Accès refusé."]);
            return;
        }
        $forms = $this->service->listForms((int)$userId);
        echo json_encode($forms);
    }

    // ── GET /me/forms — formulaires de l'utilisateur authentifié ─────────────
    // (possédés + collaborateur). Query `?since=<ISO 8601>` → synchro diff.
    public function listMyForms() {
        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }
        $since = $_GET['since'] ?? null;
        if ($since !== null && ($since === '' || strtotime($since) === false)) {
            $since = null;
        }
        echo json_encode($this->service->listMyForms((int)$user->user_id, $since));
    }

    // ── GET /forms/{id}/bundle — form + sections + questions + conditions ────
    //    + theme + version, en un seul appel (synchro mobile).
    //    M4 : accessible aussi à un agent de terrain, scopé à SON formulaire.
    public function getBundle($id) {
        $agent = AuthMiddleware::getAgent();
        if ($agent) {
            if ((int) $agent->form_id !== (int) $id) {
                http_response_code(403);
                echo json_encode(["success" => false, "error" => "Ce jeton agent ne donne pas accès à ce formulaire."]);
                return;
            }
            $result = $this->service->getBundleForAgent((int) $id);
            if (!($result['success'] ?? false)) {
                http_response_code(404);
            }
            echo json_encode($result);
            return;
        }

        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }
        $result = $this->service->getBundle((int)$id, (int)$user->user_id);
        if (!($result['success'] ?? false)) {
            http_response_code(($result['code'] ?? '') === 'forbidden' ? 403 : 404);
        }
        echo json_encode($result);
    }

    // ── GET /forms/{id}/version — { version:int } (synchro différentielle) ──
    public function getFormVersion($id) {
        $user = AuthMiddleware::getUser();
        if (!$user) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }
        $result = $this->service->getFormVersion((int)$id, (int)$user->user_id);
        if (!($result['success'] ?? false)) {
            http_response_code(($result['code'] ?? '') === 'forbidden' ? 403 : 404);
        }
        echo json_encode($result);
    }

    // ── GET /forms/{id} — récupérer un formulaire par son ID ─────────────────
    public function getForm($id) {
        $form = $this->service->getFormById((int)$id);
        if (!$form) {
            http_response_code(404);
            echo json_encode(['error' => 'Form not found']);
            return;
        }
        echo json_encode($form);
    }

    // ── PUT /forms/{id} — mettre à jour titre / description / status ─────────
    public function updateForm($id) {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $result = $this->service->updateForm((int)$id, $data);
        if (!$result['success']) {
            http_response_code(422);
        }
        echo json_encode($result);
    }

    public function publishForm($id) {
        $result = $this->service->publishForm((int)$id);
        echo json_encode($result);
    }

    // ── GET /f/{token} — bloque si protégé par mot de passe ─────────────────
    public function getPublicForm($token) {
        if ($this->shareService->isProtected($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error'      => 'This form is password-protected',
                'protected'  => true,
                'unlock_url' => '/f/' . $token . '/unlock',
            ]);
            return;
        }

        $result = $this->service->getPublicForm($token);
        echo json_encode($result);
    }

    // ── US-015 : POST /forms/{id}/confirmation ───────────────────────────────
    /**
     * Body JSON :
     *   { "message": "Merci pour votre participation !" }
     *   { "message": null }  → remet le message par défaut
     */
    public function setConfirmationMessage($id) {
        $data    = json_decode(file_get_contents("php://input"), true) ?? [];
        $hasKey  = array_key_exists('message', $data);

        if (!$hasKey) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing field: message (use null to reset to default)']);
            return;
        }

        $message = $data['message'];
        if ($message !== null && strlen(trim($message)) === 0) {
            http_response_code(422);
            echo json_encode(['error' => 'message cannot be an empty string (use null to reset)']);
            return;
        }

        $result = $this->service->setConfirmationMessage((int)$id, $message);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    public function duplicateForm($id) {
        // Le formulaire dupliqué appartient à l'utilisateur authentifié —
        // pas à un user_id fourni dans le corps. (Accès au formulaire source
        // déjà vérifié par le Router : formScope 'member'.)
        $me = AuthMiddleware::getUser();
        if (!$me) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }
        $result = $this->service->duplicateForm((int)$id, (int)$me->user_id);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    public function importJson() {
        $me   = AuthMiddleware::getUser();
        $data = json_decode(file_get_contents("php://input"), true);
        $form = $data['form'] ?? null;

        if (!$me) { http_response_code(401); echo json_encode(["success" => false, "error" => "Non authentifié"]); return; }
        if (!$form) {
            http_response_code(400);
            echo json_encode(["error" => "form object is required"]);
            return;
        }

        $result = $this->service->importFromJson((int)$me->user_id, json_encode($form));
        if (!$result['success']) http_response_code(400);
        echo json_encode($result);
    }

    public function importCsv() {
        $me    = AuthMiddleware::getUser();
        $data  = json_decode(file_get_contents("php://input"), true);
        $title = $data['title'] ?? null;
        $csv   = $data['csv']   ?? null;

        if (!$me) { http_response_code(401); echo json_encode(["success" => false, "error" => "Non authentifié"]); return; }
        if (!$title || !$csv) {
            http_response_code(400);
            echo json_encode(["error" => "title and csv are required"]);
            return;
        }

        $result = $this->service->importFromCsv((int)$me->user_id, $title, $csv);
        if (!$result['success']) http_response_code(400);
        echo json_encode($result);
    }

    public function validateForm($id) {
        $data    = json_decode(file_get_contents("php://input"), true);
        $answers = $data['answers'] ?? [];

        $normalized = [];
        foreach ($answers as $qId => $val) {
            $normalized[(int)$qId] = $val;
        }

        $result = $this->service->validateRequiredFields((int)$id, $normalized);
        if (!$result['valid']) http_response_code(422);
        echo json_encode($result);
    }
}