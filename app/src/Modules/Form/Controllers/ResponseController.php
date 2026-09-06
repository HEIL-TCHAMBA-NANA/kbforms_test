<?php
namespace Modules\Form\Controllers;

use Modules\Form\Services\ResponseService;
use Core\AuthMiddleware;

class ResponseController {
    private ResponseService $service;

    public function __construct() {
        $this->service = new ResponseService();
    }

    // ── GET /responses/{id} — consultation (mobile M1.5) ─────────────────────
    public function getResponseDetail($id) {
        $me = AuthMiddleware::getUser();
        $res = $this->service->getResponseDetail((int)$id, (int)($me->user_id ?? 0));
        if (!($res['success'] ?? false)) {
            http_response_code(($res['code'] ?? '') === 'forbidden' ? 403 : 404);
        }
        echo json_encode($res);
    }

    // ── POST /responses/{id}/media — pièce jointe (mobile M1.5) ───────────────
    // Body : { question_id, mime?, sha256?, data: "data:image/...;base64,..." }
    // M4 : accepte aussi un jeton agent (réponse déjà créée par cet agent).
    public function uploadMedia($id) {
        $data  = json_decode(file_get_contents("php://input"), true) ?? [];
        $agent = AuthMiddleware::getAgent();
        $res   = $agent
            ? $this->service->uploadMediaForAgent((int)$id, (int)$agent->agent_id, $data)
            : $this->service->uploadMedia((int)$id, (int)(AuthMiddleware::getUser()?->user_id ?? 0), $data);
        if (!($res['success'] ?? false)) {
            $code = $res['code'] ?? '';
            http_response_code($code === 'forbidden' ? 403 : ($code === 'not_found' ? 404 : 400));
        }
        echo json_encode($res);
    }

    // ── DELETE /responses/{id} ──────────────────────────────────────────────
    public function deleteResponse($id) {
        $me = AuthMiddleware::getUser();
        $res = $this->service->deleteResponse((int)$id, (int)($me->user_id ?? 0));
        if (!$res['success']) {
            http_response_code(($res['code'] ?? '') === 'forbidden' ? 403 : 404);
        }
        echo json_encode($res);
    }

    // ── POST /forms/{id}/responses/purge ───────────────────────────────────
    // Body optionnel : { "days": 30 } — sinon utilise forms.response_retention_days
    public function purgeOld($formId) {
        $me   = AuthMiddleware::getUser();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $days = isset($data['days']) ? (int)$data['days'] : null;
        $res  = $this->service->purgeOldResponses((int)$formId, (int)($me->user_id ?? 0), $days);
        if (!$res['success']) {
            http_response_code(($res['code'] ?? '') === 'forbidden' ? 403 : 400);
        }
        echo json_encode($res);
    }

    public function updateResponse($id) {
        $me      = AuthMiddleware::getUser();
        $data    = json_decode(file_get_contents("php://input"), true);
        $answers = $data["answers"] ?? [];

        if (empty($answers)) {
            http_response_code(400);
            echo json_encode(["error" => "answers are required"]);
            return;
        }

        $result = $this->service->updateResponse((int)$id, (int)($me->user_id ?? 0), $answers);
        if (!($result['success'] ?? false)) {
            $code = $result['code'] ?? '';
            http_response_code($code === 'forbidden' ? 403 : ($code === 'not_found' ? 404 : 400));
        }
        echo json_encode($result);
    }

    // Limite anti-abus : soumissions max par IP et par formulaire sur 60 min.
    private const RATE_LIMIT_PER_HOUR = 30;

    // Taille max d'un lot mobile.
    private const BATCH_MAX = 100;

    public function submitResponse() {
        $data = json_decode(file_get_contents("php://input"), true) ?? [];

        // ── Envoi groupé (app mobile) : { "responses": [ {...}, ... ] } ──────
        if (isset($data['responses']) && is_array($data['responses'])) {
            $this->submitBatch($data['responses']);
            return;
        }

        // M4 : route publique, mais un agent de terrain peut y joindre son
        // jeton — décodé/revalidé s'il est présent, requête anonyme sinon.
        AuthMiddleware::optionalAuthenticate();
        $agent = AuthMiddleware::getAgent();

        $formId  = $data["form_id"] ?? null;
        $userId  = $data["user_id"] ?? null; // peut être null si anonyme
        $answers = $data["answers"] ?? [];

        if (!$formId || empty($answers)) {
            http_response_code(400);
            echo json_encode(["error" => "form_id and answers are required"]);
            return;
        }
        $formId = (int) $formId;

        $agentId = null;
        $ipHash  = null;

        if ($agent) {
            // Un agent ne peut soumettre que pour SON formulaire, et la
            // réponse lui est attribuée — jamais un user_id.
            if ((int) $agent->form_id !== $formId) {
                http_response_code(403);
                echo json_encode(["success" => false, "error" => "Ce jeton agent n'est pas valide pour ce formulaire."]);
                return;
            }
            $agentId = (int) $agent->agent_id;
            $userId  = null;
            // Anti-abus (honeypot/rate-limit/CAPTCHA) inutile : l'agent est
            // déjà authentifié, comme le lot mobile authentifié (submitBatch).
        } else {
            // ── 1. Honeypot : champ invisible rempli = bot. Faux succès silencieux.
            $hp = $data['_hp'] ?? $data['website'] ?? '';
            if (is_string($hp) && trim($hp) !== '') {
                echo json_encode([
                    "success" => true,
                    "message" => "Response submitted successfully",
                    "confirmation_message" => "Votre réponse a bien été enregistrée.",
                ]);
                return;
            }

            $ip     = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
            $ip     = trim(explode(',', $ip)[0]);
            $ipHash = $ip !== '' ? hash('sha256', 'kbf|' . $ip) : null;

            // ── 2. Rate-limit par IP + formulaire (fenêtre glissante 60 min).
            if ($ipHash !== null) {
                $recent = (new \Modules\Form\Models\ResponseModel())
                    ->countRecentByIp($formId, $ipHash, 60);
                if ($recent >= self::RATE_LIMIT_PER_HOUR) {
                    http_response_code(429);
                    echo json_encode(["success" => false, "error" => "Trop de soumissions. Réessayez plus tard."]);
                    return;
                }
            }

            // ── 3. CAPTCHA si le formulaire l'exige et qu'il est configuré.
            $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
            if ($form && (int) ($form['require_captcha'] ?? 0) === 1 && \Core\Recaptcha::isEnabled()) {
                $token = $data['recaptcha_token'] ?? $data['g-recaptcha-response'] ?? null;
                if (!\Core\Recaptcha::verify($token, $ip ?: null)) {
                    http_response_code(400);
                    echo json_encode(["success" => false, "error" => "Vérification anti-robot échouée."]);
                    return;
                }
            }
        }

        $result = $this->service->submitResponse(
            $formId,
            $userId ? (int)$userId : null,
            $answers,
            $ipHash,
            $this->collectMeta($data),
            $agentId
        );
        if (($result['success'] ?? true) === false) {
            http_response_code(in_array($result['code'] ?? '', ['repeat_min', 'repeat_max'], true) ? 422 : 400);
        }
        echo json_encode($result);
    }

    // ── POST /responses (lot) — app mobile, authentifié ────────────────────
    // Body : { "responses": [ { client_uuid, form_id, answers:[...], + méta }, ... ] }
    // Réponse : { success, results: [ { client_uuid, status, response_id?, error? } ] }
    //   status ∈ created | duplicate | error
    private function submitBatch(array $items): void {
        // Le lot exige un utilisateur authentifié (le collecteur).
        AuthMiddleware::authenticate(); // 401 + exit si Bearer absent/invalide
        // M4 : le lot n'est pas adapté aux agents de terrain (pas de user_id) —
        // rejeter explicitement plutôt que de créer des réponses user_id=0.
        if (AuthMiddleware::getAgent()) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "L'envoi groupé n'est pas disponible pour un jeton agent."]);
            return;
        }
        $userId = (int) AuthMiddleware::getUser()->user_id;

        if (count($items) === 0) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "responses ne peut pas être vide"]);
            return;
        }
        if (count($items) > self::BATCH_MAX) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Lot trop volumineux (max " . self::BATCH_MAX . ")"]);
            return;
        }

        $results = [];
        foreach ($items as $item) {
            $item = is_array($item) ? $item : [];
            $clientUuid = isset($item['client_uuid']) ? trim((string) $item['client_uuid']) : '';
            $formId  = isset($item['form_id']) ? (int) $item['form_id'] : 0;
            $answers = $item['answers'] ?? [];

            if ($clientUuid === '') {
                $results[] = ["client_uuid" => null, "status" => "error", "error" => "client_uuid requis"];
                continue;
            }
            if ($formId <= 0 || !is_array($answers) || count($answers) === 0) {
                $results[] = ["client_uuid" => $clientUuid, "status" => "error", "error" => "form_id et answers requis"];
                continue;
            }

            try {
                $meta = $this->collectMeta($item);
                $res  = $this->service->submitResponse($formId, $userId, $answers, null, $meta);
                $results[] = [
                    "client_uuid" => $clientUuid,
                    "status"      => !empty($res['duplicate']) ? "duplicate" : "created",
                    "response_id" => $res['response_id'] ?? null,
                ];
            } catch (\Throwable $e) {
                error_log('[ResponseController::submitBatch] ' . $e->getMessage());
                $results[] = ["client_uuid" => $clientUuid, "status" => "error", "error" => "Erreur serveur"];
            }
        }

        echo json_encode(["success" => true, "results" => $results]);
    }

    /** Métadonnées de collecte mobile éventuelles (toutes optionnelles). */
    private function collectMeta(array $data): array {
        $toDateTime = static function ($v): ?string {
            if (!is_string($v) || trim($v) === '') return null;
            $ts = strtotime($v);
            return $ts === false ? null : date('Y-m-d H:i:s', $ts);
        };
        return [
            'client_uuid'   => isset($data['client_uuid']) ? trim((string)$data['client_uuid']) : null,
            'device_id'     => $data['device_id']     ?? null,
            'app_version'   => $data['app_version']   ?? null,
            'gps_lat'       => $data['gps_lat']       ?? null,
            'gps_lng'       => $data['gps_lng']       ?? null,
            'gps_accuracy'  => $data['gps_accuracy']  ?? null,
            'started_at'    => $toDateTime($data['started_at']   ?? null),
            'duration_s'    => isset($data['duration_s']) ? (int)$data['duration_s'] : null,
            'mock_location' => !empty($data['mock_location']) ? 1 : null,
            'submitted_at'  => $toDateTime($data['submitted_at'] ?? null),
        ];
    }

    public function getResponses($formId) {
        $result = $this->service->getResponses((int)$formId);
        echo json_encode($result);
    }

}