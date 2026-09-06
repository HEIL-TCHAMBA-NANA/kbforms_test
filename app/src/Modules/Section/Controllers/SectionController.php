<?php
namespace Modules\Section\Controllers;

use Modules\Section\Services\SectionService;
use Modules\Section\Models\SectionModel;
use Modules\LogicEngine\Services\LogicEngineService;
use Modules\LogicEngine\Models\ConditionModel;
use Core\AuthMiddleware;
use Core\FormGuard;

class SectionController {
    private SectionService     $sectionService;
    private LogicEngineService $logicService;

    public function __construct() {
        $this->sectionService = new SectionService();
        $this->logicService   = new LogicEngineService();
    }

    /** Refuse (403/404) si l'utilisateur du jeton n'a pas droit d'édition sur ce formulaire. */
    private function guardEdit(?int $formId): bool {
        if (!$formId) {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "Ressource introuvable."]);
            return false;
        }
        $uid = (int) (AuthMiddleware::getUser()->user_id ?? 0);
        if (!FormGuard::canEdit($formId, $uid)) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Vous n'avez pas accès à ce formulaire."]);
            return false;
        }
        return true;
    }

    // ── POST /forms/{id}/sections ─────────────────────────────────────────────
    public function createSection($formId) {
        $data        = json_decode(file_get_contents('php://input'), true) ?? [];
        $title       = $data['title']       ?? null;
        $description = $data['description'] ?? null;
        $position    = $data['position']    ?? 0;

        if (!$title) {
            http_response_code(400);
            echo json_encode(['error' => 'title is required']);
            return;
        }

        $result = $this->sectionService->createSection((int)$formId, $title, $description, (int)$position);
        if (!$result['success']) http_response_code(422);
        echo json_encode($result);
    }

    // ── GET /forms/{id}/sections ──────────────────────────────────────────────
    // Retourne sections + leurs questions groupées
    public function listSections($formId) {
        $result = $this->sectionService->getSectionsWithQuestions((int)$formId);
        echo json_encode($result);
    }

    // ── PUT /sections/{id} ────────────────────────────────────────────────────
    public function updateSection($id) {
        $sec = (new SectionModel())->getById((int)$id);
        if (!$this->guardEdit($sec ? (int)$sec['form_id'] : null)) return;
        $data   = json_decode(file_get_contents('php://input'), true) ?? [];
        $fields = [];

        if (array_key_exists('title', $data)) {
            if (trim((string)$data['title']) === '') {
                http_response_code(400);
                echo json_encode(['error' => 'title cannot be empty']);
                return;
            }
            $fields['title'] = trim((string)$data['title']);
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = $data['description'];
        }
        if (array_key_exists('position', $data)) {
            $fields['position'] = (int)$data['position'];
        }

        if (empty($fields)) {
            http_response_code(400);
            echo json_encode(['error' => 'Nothing to update (title, description or position)']);
            return;
        }

        $result = $this->sectionService->updateSection((int)$id, $fields);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    // ── DELETE /sections/{id} ─────────────────────────────────────────────────
    public function deleteSection($id) {
        $sec = (new SectionModel())->getById((int)$id);
        if (!$this->guardEdit($sec ? (int)$sec['form_id'] : null)) return;
        $result = $this->sectionService->deleteSection((int)$id);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    // ── POST /forms/{id}/conditions ───────────────────────────────────────────
    public function addCondition($formId) {
        $data             = json_decode(file_get_contents('php://input'), true) ?? [];
        $sourceQuestionId = $data['source_question_id'] ?? null;
        $operator         = $data['operator']            ?? null;
        $value            = $data['value']               ?? null;
        $targetSectionId  = $data['target_section_id']   ?? null;

        if (!$sourceQuestionId || !$operator || $value === null || !$targetSectionId) {
            http_response_code(400);
            echo json_encode(['error' => 'source_question_id, operator, value and target_section_id are required']);
            return;
        }

        $result = $this->logicService->addCondition(
            (int)$formId, (int)$sourceQuestionId, $operator, (string)$value, (int)$targetSectionId
        );
        if (!$result['success']) http_response_code(422);
        echo json_encode($result);
    }

    // ── GET /forms/{id}/conditions ────────────────────────────────────────────
    public function listConditions($formId) {
        $result = $this->logicService->listConditions((int)$formId);
        echo json_encode($result);
    }

    // ── DELETE /conditions/{id} ───────────────────────────────────────────────
    public function deleteCondition($id) {
        if (!$this->guardEdit((new ConditionModel())->formIdOf((int)$id))) return;
        $result = $this->logicService->deleteCondition((int)$id);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    // ── POST /forms/{id}/logic/evaluate ──────────────────────────────────────
    // Body : { "answers": { "question_id": "valeur", ... } }
    // Retourne les section_ids à afficher selon les réponses
    public function evaluate($formId) {
        $data    = json_decode(file_get_contents('php://input'), true) ?? [];
        $answers = $data['answers'] ?? [];

        // Normalise les clés en int
        $normalized = [];
        foreach ($answers as $qId => $val) {
            $normalized[(int)$qId] = $val;
        }

        $result = $this->logicService->evaluate((int)$formId, $normalized);
        echo json_encode($result);
    }
}
