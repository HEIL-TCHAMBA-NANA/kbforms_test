<?php
namespace Modules\Form\Controllers;
use Modules\Form\Services\QuestionService;
use Modules\Form\Models\QuestionModel;
use Core\AuthMiddleware;
use Core\FormGuard;

class QuestionController {
    private QuestionService $service;

    public function __construct() {
        $this->service = new QuestionService();
    }

    /** Refuse (403) si l'utilisateur du jeton n'a pas droit d'édition sur ce formulaire. */
    private function guardEdit(int $formId): bool {
        $uid = (int) (AuthMiddleware::getUser()->user_id ?? 0);
        if ($formId <= 0) {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "Question introuvable."]);
            return false;
        }
        if (!FormGuard::canEdit($formId, $uid)) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Vous n'avez pas accès à ce formulaire."]);
            return false;
        }
        return true;
    }

    /** Valide une formule de champ calculé (grammaire volontairement étroite). */
    private function validCalcExpr($expr): bool {
        if (!is_string($expr)) return false;
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > 500) return false;
        $s = preg_replace('/\{q\d+\}/i', '1', $expr);                       // références → nombre
        $s = preg_replace('/\b(age|round|abs)\b/i', '', $s);               // fonctions autorisées
        if (!preg_match('#^[0-9+\-*/(),.\s]*$#', $s)) return false;         // reste = arithmétique pure
        $t = trim($s);
        if ($t === '') return false;
        if (preg_match('#[+\-*/(]$#', $t)) return false;                    // se termine par un opérateur / (
        if (preg_match('#^[*/)]#', $t)) return false;                      // commence par un opérateur / )
        $bal = 0;
        foreach (str_split($expr) as $c) {
            if ($c === '(') $bal++;
            elseif ($c === ')' && --$bal < 0) return false;
        }
        return $bal === 0;
    }

    // ── POST /questions ───────────────────────────────────────────────────────
    public function createQuestion() {
        $data = json_decode(file_get_contents("php://input"), true);

        $formId       = $data["form_id"]       ?? null;
        $type         = $data["type"]           ?? null;
        $label        = $data["label"]          ?? null;
        $required     = $data["required"]       ?? false;
        $position     = $data["position"]       ?? 0;
        $options      = $data["options"]        ?? [];
        $imageData    = $data["image_data"]     ?? null;
        $sectionIndex = $data["section_index"]  ?? 0;
        $helpText     = $data["help_text"]      ?? null;

        if (!$formId || !$type || !$label) {
            http_response_code(400);
            echo json_encode(["error" => "form_id, type and label are required"]);
            return;
        }
        if (!$this->guardEdit((int) $formId)) return;

        // Champs spécifiques par type
        $extra = [];
        if ($type === 'linear_scale') {
            $extra['scale_min']  = $data['scale_min']  ?? 1;
            $extra['scale_max']  = $data['scale_max']  ?? 5;
            $extra['scale_step'] = $data['scale_step'] ?? 1;
        }
        if ($type === 'grid') {
            $extra['grid_rows']    = $data['grid_rows']    ?? [];
            $extra['grid_columns'] = $data['grid_columns'] ?? [];
        }
        if ($type === 'phone') {
            $extra['phone_default_country'] = $data['phone_default_country'] ?? '';
        }
        // Listes de choix en cascade (B7) — applicable à dropdown / radio.
        if (array_key_exists('cascade_list_id', $data)) {
            $extra['cascade_list_id'] = $data['cascade_list_id'] !== null ? (int) $data['cascade_list_id'] : null;
        }
        if (array_key_exists('cascade_parent_question_id', $data)) {
            $extra['cascade_parent_question_id'] = $data['cascade_parent_question_id'] !== null ? (int) $data['cascade_parent_question_id'] : null;
        }
        if (array_key_exists('media_max_duration_s', $data)) {
            $extra['media_max_duration_s'] = $data['media_max_duration_s'] !== null && $data['media_max_duration_s'] !== ''
                ? (int) $data['media_max_duration_s'] : null;
        }
        if ($type === 'calculated' || array_key_exists('calculated_expression', $data)) {
            $expr = $data['calculated_expression'] ?? null;
            if ($type === 'calculated' && !$this->validCalcExpr($expr)) {
                http_response_code(400);
                echo json_encode(["error" => "calculated_expression invalide (ex. \"{q12} + {q13}\", age({q4}))"]);
                return;
            }
            $extra['calculated_expression'] = is_string($expr) ? trim($expr) : null;
        }
        if (array_key_exists('repeat_group_id', $data)) {
            $extra['repeat_group_id'] = ($data['repeat_group_id'] !== null && $data['repeat_group_id'] !== '')
                ? (int) $data['repeat_group_id'] : null;
        }
        if (array_key_exists('allow_photo', $data)) { // M5
            $extra['allow_photo'] = filter_var($data['allow_photo'], FILTER_VALIDATE_BOOL);
        }

        try {
            $result = $this->service->createQuestion(
                (int)$formId, $type, $label, (bool)$required,
                (int)$position, $options, $imageData, (int)$sectionIndex,
                $extra, $helpText
            );
            echo json_encode($result);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'error'   => 'Database error',
                'message' => $e->getMessage(),
                'type_sent' => $type
            ]);
        }
    }

    // ── GET /forms/{id}/questions ─────────────────────────────────────────────
    public function getQuestions($formId) {
        $questions = $this->service->getQuestionsByForm((int)$formId);
        echo json_encode($questions);
    }

    // ── PUT /questions/{id} ───────────────────────────────────────────────────
    public function updateQuestion($id) {
        if (!$this->guardEdit((new QuestionModel())->formIdOf((int) $id) ?? 0)) return;
        $data     = json_decode(file_get_contents("php://input"), true);
        $label    = $data["label"]    ?? null;
        $required = $data["required"] ?? false;
        $position = $data["position"] ?? 0;
        $type     = $data["type"]     ?? null;
        $helpText = array_key_exists("help_text", $data) ? ($data["help_text"] ?? '') : null;
        $sectionIndex = array_key_exists("section_index", $data) ? (int)$data["section_index"] : null;

        if (!$label) {
            http_response_code(400);
            echo json_encode(["error" => "label is required"]);
            return;
        }

        // ✅ FIX : transmettre les options, grid et scale au service
        $options = $data["options"] ?? [];
        $extra   = [];
        if ($type === 'linear_scale') {
            $extra['scale_min']  = $data['scale_min']  ?? 1;
            $extra['scale_max']  = $data['scale_max']  ?? 5;
            $extra['scale_step'] = $data['scale_step'] ?? 1;
        }
        if ($type === 'grid') {
            $extra['grid_rows']    = $data['grid_rows']    ?? [];
            $extra['grid_columns'] = $data['grid_columns'] ?? [];
        }
        if ($type === 'phone') {
            $extra['phone_default_country'] = $data['phone_default_country'] ?? '';
        }
        if (array_key_exists('cascade_list_id', $data)) {
            $extra['cascade_list_id'] = $data['cascade_list_id'] !== null ? (int) $data['cascade_list_id'] : null;
        }
        if (array_key_exists('cascade_parent_question_id', $data)) {
            $extra['cascade_parent_question_id'] = $data['cascade_parent_question_id'] !== null ? (int) $data['cascade_parent_question_id'] : null;
        }
        if (array_key_exists('media_max_duration_s', $data)) {
            $extra['media_max_duration_s'] = $data['media_max_duration_s'] !== null && $data['media_max_duration_s'] !== ''
                ? (int) $data['media_max_duration_s'] : null;
        }
        if (array_key_exists('calculated_expression', $data)) {
            $expr = $data['calculated_expression'];
            if (($type === 'calculated') && !$this->validCalcExpr($expr)) {
                http_response_code(400);
                echo json_encode(["error" => "calculated_expression invalide"]);
                return;
            }
            $extra['calculated_expression'] = is_string($expr) ? trim($expr) : null;
        }
        if (array_key_exists('repeat_group_id', $data)) {
            $extra['repeat_group_id'] = ($data['repeat_group_id'] !== null && $data['repeat_group_id'] !== '')
                ? (int) $data['repeat_group_id'] : null;
        }
        if (array_key_exists('allow_photo', $data)) { // M5
            $extra['allow_photo'] = filter_var($data['allow_photo'], FILTER_VALIDATE_BOOL);
        }

        $result = $this->service->updateQuestion(
            (int)$id, $label, (bool)$required, (int)$position,
            $options, $extra, $type, $helpText, $sectionIndex
        );
        echo json_encode($result);
    }

    // ── PUT /questions/reorder ────────────────────────────────────────────────
    /**
     * Body JSON :
     * { "items": [ {"id": 3, "position": 0}, {"id": 7, "position": 1}, … ] }
     */
    public function reorderQuestions() {
        $data  = json_decode(file_get_contents("php://input"), true);
        $items = $data['items'] ?? null;

        if (!$items || !is_array($items)) {
            http_response_code(400);
            echo json_encode(["error" => "items array is required"]);
            return;
        }

        // Contrôle d'accès : tous les formulaires touchés doivent être éditables.
        $uid = (int) (AuthMiddleware::getUser()->user_id ?? 0);
        $formIds = (new QuestionModel())->formIdsOf(array_column($items, 'id'));
        if ($formIds === []) {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "Questions introuvables."]);
            return;
        }
        foreach ($formIds as $fid) {
            if (!FormGuard::canEdit($fid, $uid)) {
                http_response_code(403);
                echo json_encode(["success" => false, "error" => "Vous n'avez pas accès à ce formulaire."]);
                return;
            }
        }

        $result = $this->service->reorderQuestions($items);
        if (!$result['success']) {
            http_response_code(500);
        }
        echo json_encode($result);
    }

    // ── DELETE /questions/{id} ────────────────────────────────────────────────
    public function deleteQuestion($id) {
        if (!$this->guardEdit((new QuestionModel())->formIdOf((int) $id) ?? 0)) return;
        $result = $this->service->deleteQuestion((int)$id);
        echo json_encode($result);
    }
}