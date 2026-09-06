<?php
namespace Modules\RepeatGroup\Models;

use Core\Database;
use PDO;

/**
 * Groupes de questions répétables (table repeat_groups).
 * Un groupe vit dans une section (par son index ordinal) ; ses questions membres
 * portent questions.repeat_group_id.
 */
class RepeatGroupModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function create(int $formId, int $sectionIndex, string $label, int $minRepeat, ?int $maxRepeat, int $position): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO repeat_groups (form_id, section_index, label, min_repeat, max_repeat, position)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$formId, $sectionIndex, $label, $minRepeat, $maxRepeat, $position]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $fields): bool
    {
        $set = [];
        $val = [];
        foreach (['label', 'section_index', 'min_repeat', 'max_repeat', 'position'] as $k) {
            if (array_key_exists($k, $fields)) {
                $set[] = "`$k` = ?";
                $val[] = $fields[$k];
            }
        }
        if (!$set) {
            return true;
        }
        $val[] = $id;
        $stmt = $this->db->prepare("UPDATE repeat_groups SET " . implode(', ', $set) . " WHERE id = ?");
        return $stmt->execute($val);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM repeat_groups WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function get(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM repeat_groups WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Groupes d'un formulaire, chacun avec la liste des ids de questions membres. */
    public function listForForm(int $formId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM repeat_groups WHERE form_id = ? ORDER BY section_index, position, id");
        $stmt->execute([$formId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $members = $this->db->prepare("SELECT id FROM questions WHERE repeat_group_id = ? ORDER BY position, id");
        foreach ($groups as &$g) {
            $g['id']            = (int) $g['id'];
            $g['form_id']       = (int) $g['form_id'];
            $g['section_index'] = (int) $g['section_index'];
            $g['min_repeat']    = (int) $g['min_repeat'];
            $g['max_repeat']    = $g['max_repeat'] !== null ? (int) $g['max_repeat'] : null;
            $g['position']      = (int) $g['position'];
            $members->execute([$g['id']]);
            $g['question_ids']  = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
        }
        return $groups;
    }

    public function belongsToForm(int $groupId, int $formId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM repeat_groups WHERE id = ? AND form_id = ?");
        $stmt->execute([$groupId, $formId]);
        return (bool) $stmt->fetchColumn();
    }
}
