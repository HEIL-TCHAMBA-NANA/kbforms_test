<?php
namespace Modules\Section\Models;

use Core\Database;
use PDO;

class SectionModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function create(int $formId, string $title, ?string $description, int $position): int {
        $stmt = $this->db->prepare("
            INSERT INTO sections (form_id, title, description, position)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$formId, $title, $description, $position]);
        return (int)$this->db->lastInsertId();
    }

    public function getByForm(int $formId): array {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE form_id = ? ORDER BY position ASC");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM sections WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Mise à jour partielle : seules les clés fournies parmi
     * [title, description, position] sont écrasées.
     */
    public function updatePartial(int $id, array $fields): bool {
        $allowed = ['title', 'description', 'position'];
        $set = [];
        $values = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]    = "$col = ?";
                $values[] = $fields[$col];
            }
        }
        if (empty($set)) return false;
        $values[] = $id;
        $stmt = $this->db->prepare(
            "UPDATE sections SET " . implode(', ', $set) . " WHERE id = ?"
        );
        return $stmt->execute($values);
    }

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM sections WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Retourne les sections avec leurs questions groupées dedans.
     * Les questions sans section (section_index non mappé) vont dans section index 0.
     */
    public function getSectionsWithQuestions(int $formId): array {
        // Sections
        $stmtS = $this->db->prepare("SELECT * FROM sections WHERE form_id = ? ORDER BY position ASC");
        $stmtS->execute([$formId]);
        $sections = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        // Questions
        $stmtQ = $this->db->prepare("
            SELECT q.id, q.type, q.label, q.required, q.position, q.section_index,
                   q.scale_min, q.scale_max, q.scale_step, q.grid_rows, q.grid_columns,
                   o.value AS option_value
            FROM questions q
            LEFT JOIN options o ON o.question_id = q.id
            WHERE q.form_id = ?
            ORDER BY q.section_index ASC, q.position ASC, o.id ASC
        ");
        $stmtQ->execute([$formId]);
        $rows = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

        // Grouper questions par section_index
        $questionsByIndex = [];
        foreach ($rows as $row) {
            $idx = (int)($row['section_index'] ?? 0);
            $qid = $row['id'];
            if (!isset($questionsByIndex[$idx][$qid])) {
                $entry = [
                    'id'            => $qid,
                    'type'          => $row['type'],
                    'label'         => $row['label'],
                    'required'      => (bool)$row['required'],
                    'position'      => (int)$row['position'],
                    'section_index' => $idx,
                    'options'       => [],
                ];
                if ($row['type'] === 'linear_scale') {
                    $entry['scale_min']  = $row['scale_min']  !== null ? (int)$row['scale_min']  : 1;
                    $entry['scale_max']  = $row['scale_max']  !== null ? (int)$row['scale_max']  : 5;
                    $entry['scale_step'] = $row['scale_step'] !== null ? (int)$row['scale_step'] : 1;
                }
                if ($row['type'] === 'grid') {
                    $entry['grid_rows']    = $row['grid_rows']    ? json_decode($row['grid_rows'],    true) : [];
                    $entry['grid_columns'] = $row['grid_columns'] ? json_decode($row['grid_columns'], true) : [];
                }
                $questionsByIndex[$idx][$qid] = $entry;
            }
            if ($row['option_value'] !== null) {
                $questionsByIndex[$idx][$qid]['options'][] = $row['option_value'];
            }
        }

        // Attacher les questions à chaque section (section position = section_index)
        $result = [];
        foreach ($sections as $section) {
            $pos = (int)$section['position'];
            $section['questions'] = isset($questionsByIndex[$pos])
                ? array_values($questionsByIndex[$pos])
                : [];
            $result[] = $section;
        }

        return $result;
    }
}
