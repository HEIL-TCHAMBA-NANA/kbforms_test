<?php
namespace Modules\Form\Services;
use Modules\Form\Models\QuestionModel;

class QuestionService {
    private QuestionModel $model;

    public function __construct() {
        $this->model = new QuestionModel();
    }

    // ── Création ──────────────────────────────────────────────────────────────
    /**
     * @param array $options     Valeurs pour radio/checkbox/dropdown
     * @param array $extra       Champs spécifiques au type :
     *   linear_scale → scale_min, scale_max, scale_step
     *   grid         → grid_rows (array), grid_columns (array)
     */
    public function createQuestion(
        int $formId, string $type, string $label,
        bool $required, int $position,
        array $options = [],
        ?string $imageData = null,
        int $sectionIndex = 0,
        array $extra = [],
        ?string $helpText = null
    ): array {
        $questionId = $this->model->createQuestion(
            $formId, $type, $label, $required, $position,
            $imageData, $sectionIndex, $extra, $helpText
        );

        foreach ($options as $opt) {
            $this->model->addOption($questionId, $opt);
        }

        return [
            "success"     => true,
            "message"     => "Question created successfully",
            "question_id" => $questionId,
        ];
    }

    // ── Lecture ───────────────────────────────────────────────────────────────
    public function getQuestionsByForm(int $formId): array {
        return $this->model->getQuestionsByForm($formId);
    }

    // ── Mise à jour simple ────────────────────────────────────────────────────
    public function updateQuestion(
        int $id, string $label, bool $required, int $position,
        array $options = [], array $extra = [],
        ?string $type = null, ?string $helpText = null, ?int $sectionIndex = null
    ): array {
        $success = $this->model->updateQuestion($id, $label, $required, $position, $options, $extra, $type, $helpText, $sectionIndex);
        return [
            "success" => $success,
            "message" => $success ? "Question updated successfully" : "Failed to update question",
        ];
    }

    // ── Bulk reorder ──────────────────────────────────────────────────────────
    /**
     * @param array $items  [ ['id' => int, 'position' => int], … ]
     */
    public function reorderQuestions(array $items): array {
        if (empty($items) || !is_array($items)) {
            return ["success" => false, "error" => "items array is required"];
        }
        $success = $this->model->bulkReorder($items);
        return [
            "success" => $success,
            "message" => $success ? "Questions reordered successfully" : "Reorder failed (transaction rolled back)",
        ];
    }

    // ── Suppression ───────────────────────────────────────────────────────────
    public function deleteQuestion(int $id): array {
        $success = $this->model->deleteQuestion($id);
        return [
            "success" => $success,
            "message" => $success ? "Question deleted successfully" : "Failed to delete question",
        ];
    }
}