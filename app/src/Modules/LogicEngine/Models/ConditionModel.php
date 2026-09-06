<?php
namespace Modules\LogicEngine\Models;

use Core\Database;
use PDO;

class ConditionModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function create(int $formId, int $sourceQuestionId, string $operator, string $value, int $targetSectionId): int {
        $stmt = $this->db->prepare("
            INSERT INTO conditions (form_id, source_question_id, operator, value, target_section_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$formId, $sourceQuestionId, $operator, $value, $targetSectionId]);
        return (int)$this->db->lastInsertId();
    }

    public function getByForm(int $formId): array {
        $stmt = $this->db->prepare("SELECT * FROM conditions WHERE form_id = ? ORDER BY id ASC");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM conditions WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /** Formulaire parent d'une condition (contrôle d'accès), null si inconnue. */
    public function formIdOf(int $id): ?int {
        $stmt = $this->db->prepare("SELECT form_id FROM conditions WHERE id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }
}
