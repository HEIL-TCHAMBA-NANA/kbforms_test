<?php
namespace Modules\Section\Services;

use Modules\Section\Models\SectionModel;

class SectionService {
    private SectionModel $model;

    public function __construct() {
        $this->model = new SectionModel();
    }

    public function createSection(int $formId, string $title, ?string $description, int $position): array {
        if (trim($title) === '') {
            return ['success' => false, 'error' => 'title cannot be empty'];
        }
        $id = $this->model->create($formId, $title, $description, $position);
        return ['success' => true, 'section_id' => $id, 'message' => 'Section created successfully'];
    }

    public function listSections(int $formId): array {
        return $this->model->getByForm($formId);
    }

    public function getSectionsWithQuestions(int $formId): array {
        return $this->model->getSectionsWithQuestions($formId);
    }

    public function updateSection(int $id, array $fields): array {
        if (array_key_exists('title', $fields) && trim((string)$fields['title']) === '') {
            return ['success' => false, 'error' => 'title cannot be empty'];
        }
        if (empty($fields)) {
            return ['success' => false, 'error' => 'no fields to update'];
        }
        $ok = $this->model->updatePartial($id, $fields);
        return ['success' => $ok, 'message' => $ok ? 'Section updated' : 'Section not found'];
    }

    public function deleteSection(int $id): array {
        $ok = $this->model->delete($id);
        return ['success' => $ok, 'message' => $ok ? 'Section deleted' : 'Section not found'];
    }
}
