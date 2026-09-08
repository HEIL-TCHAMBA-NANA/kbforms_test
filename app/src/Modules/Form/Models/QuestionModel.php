<?php
namespace Modules\Form\Models;
use Core\Database;
use PDO;

class QuestionModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /** Formulaire parent d'une question (contrôle d'accès), null si inconnue. */
    public function formIdOf(int $questionId): ?int {
        $stmt = $this->db->prepare("SELECT form_id FROM questions WHERE id = ?");
        $stmt->execute([$questionId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Formulaires distincts couverts par un lot d'ids de questions. */
    public function formIdsOf(array $questionIds): array {
        $ids = array_values(array_filter(array_map('intval', $questionIds)));
        if ($ids === []) return [];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT DISTINCT form_id FROM questions WHERE id IN ($ph)");
        $stmt->execute($ids);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ── Création ──────────────────────────────────────────────────────────────
    /**
     * @param array $extra  Champs additionnels selon le type :
     *   - linear_scale : ['scale_min'=>int, 'scale_max'=>int, 'scale_step'=>int]
     *   - grid         : ['grid_rows'=>string (JSON), 'grid_columns'=>string (JSON)]
     */
    public function createQuestion(
        int $formId, string $type, string $label,
        bool $required, int $position,
        ?string $imageData = null, int $sectionIndex = 0,
        array $extra = [], ?string $helpText = null
    ): int {
        $scaleMin  = isset($extra['scale_min'])            ? (int)$extra['scale_min']                   : null;
        $scaleMax  = isset($extra['scale_max'])            ? (int)$extra['scale_max']                   : null;
        $scaleStep = isset($extra['scale_step'])           ? (int)$extra['scale_step']                  : null;
        $gridRows  = isset($extra['grid_rows'])            ? json_encode($extra['grid_rows'])            : null;
        $gridCols  = isset($extra['grid_columns'])         ? json_encode($extra['grid_columns'])         : null;
        $phoneDefaultCountry = $extra['phone_default_country'] ?? null;
        $cascadeListId   = array_key_exists('cascade_list_id', $extra) && $extra['cascade_list_id'] !== null
            ? (int) $extra['cascade_list_id'] : null;
        $cascadeParentQid = array_key_exists('cascade_parent_question_id', $extra) && $extra['cascade_parent_question_id'] !== null
            ? (int) $extra['cascade_parent_question_id'] : null;
        $mediaMaxDuration = array_key_exists('media_max_duration_s', $extra) && $extra['media_max_duration_s'] !== null
            ? (int) $extra['media_max_duration_s'] : null;
        $calcExpr = array_key_exists('calculated_expression', $extra) && $extra['calculated_expression'] !== null
            ? (string) $extra['calculated_expression'] : null;
        $repeatGroupId = array_key_exists('repeat_group_id', $extra) && $extra['repeat_group_id'] !== null
            ? (int) $extra['repeat_group_id'] : null;
        $allowPhoto = !empty($extra['allow_photo']) ? 1 : 0; // M5

        try {
            $stmt = $this->db->prepare("
                INSERT INTO questions
                    (form_id, type, label, help_text, required, position, image_data, section_index,
                     scale_min, scale_max, scale_step, grid_rows, grid_columns, phone_default_country,
                     cascade_list_id, cascade_parent_question_id, media_max_duration_s, allow_photo,
                     calculated_expression, repeat_group_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $formId, $type, $label, ($helpText !== null && $helpText !== '' ? $helpText : null),
                (int)$required, $position,
                $imageData, $sectionIndex,
                $scaleMin, $scaleMax, $scaleStep, $gridRows, $gridCols, $phoneDefaultCountry,
                $cascadeListId, $cascadeParentQid, $mediaMaxDuration, $allowPhoto, $calcExpr, $repeatGroupId,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Log l'erreur pour diagnostic et la remonte au controller
            error_log('[QuestionModel] createQuestion failed: ' . $e->getMessage());
            throw $e;
        }
    }

    // ── Options (radio, checkbox, dropdown) ───────────────────────────────────
    public function addOption(int $questionId, string $value): int {
        $stmt = $this->db->prepare("INSERT INTO options (question_id, value) VALUES (?, ?)");
        $stmt->execute([$questionId, $value]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteOptions(int $questionId): void {
        $stmt = $this->db->prepare("DELETE FROM options WHERE question_id = ?");
        $stmt->execute([$questionId]);
    }

    // ── Lecture ───────────────────────────────────────────────────────────────
    public function getQuestionsByForm(int $formId): array {
        $stmt = $this->db->prepare("
            SELECT q.id, q.type, q.label, q.help_text, q.required, q.position, q.image_data, q.section_index,
                   q.scale_min, q.scale_max, q.scale_step,
                   q.grid_rows, q.grid_columns, q.phone_default_country,
                   q.cascade_list_id, q.cascade_parent_question_id, q.media_max_duration_s, q.allow_photo,
                   q.calculated_expression, q.repeat_group_id,
                   o.id AS option_id, o.value AS option_value
            FROM questions q
            LEFT JOIN options o ON o.question_id = q.id
            WHERE q.form_id = ?
            ORDER BY q.position ASC, o.id ASC
        ");
        $stmt->execute([$formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $questions = [];
        foreach ($rows as $row) {
            $qid = $row['id'];
            if (!isset($questions[$qid])) {
                $entry = [
                    'id'            => $qid,
                    'type'          => $row['type'],
                    'label'         => $row['label'],
                    'help_text'     => $row['help_text'] ?? '',
                    'required'      => (bool)$row['required'],
                    'position'      => (int)$row['position'],
                    'image_data'    => $row['image_data'],
                    'section_index' => (int)($row['section_index'] ?? 0),
                    'allow_photo'   => (bool)($row['allow_photo'] ?? 0), // M5 — toujours exposé
                    'options'       => [],
                ];

                // Champs spécifiques linear_scale
                if ($row['type'] === 'linear_scale') {
                    $entry['scale_min']  = $row['scale_min'] !== null ? (int)$row['scale_min']  : 1;
                    $entry['scale_max']  = $row['scale_max'] !== null ? (int)$row['scale_max']  : 5;
                    $entry['scale_step'] = $row['scale_step'] !== null ? (int)$row['scale_step'] : 1;
                }

                // Champs spécifiques grid
                if ($row['type'] === 'grid') {
                    $entry['grid_rows']    = $row['grid_rows']    ? json_decode($row['grid_rows'],    true) : [];
                    $entry['grid_columns'] = $row['grid_columns'] ? json_decode($row['grid_columns'], true) : [];
                }

                // Champs spécifiques phone
                if ($row['type'] === 'phone') {
                    $entry['phone_default_country'] = $row['phone_default_country'] ?? '';
                }

                // Listes de choix en cascade (B7)
                if ($row['cascade_list_id'] !== null) {
                    $entry['cascade_list_id'] = (int) $row['cascade_list_id'];
                    $entry['cascade_parent_question_id'] = $row['cascade_parent_question_id'] !== null
                        ? (int) $row['cascade_parent_question_id'] : null;
                }

                // audio / vidéo (B5)
                if (($row['type'] === 'audio' || $row['type'] === 'video') && $row['media_max_duration_s'] !== null) {
                    $entry['media_max_duration_s'] = (int) $row['media_max_duration_s'];
                }

                // champ calculé (B6)
                if ($row['type'] === 'calculated') {
                    $entry['calculated_expression'] = $row['calculated_expression'] ?? '';
                }

                // groupe répétable (B8)
                if ($row['repeat_group_id'] !== null) {
                    $entry['repeat_group_id'] = (int) $row['repeat_group_id'];
                }

                $questions[$qid] = $entry;
            }

            if ($row['option_value'] !== null) {
                $questions[$qid]['options'][] = $row['option_value'];
            }
        }
        return array_values($questions);
    }

    // ── Mise à jour simple ────────────────────────────────────────────────────
    public function updateQuestion(
        int $id, string $label, bool $required, int $position,
        array $options = [], array $extra = [],
        ?string $type = null, ?string $helpText = null, ?int $sectionIndex = null
    ): bool {
        // Mise à jour des champs de base (SET dynamique)
        $set  = ['label = ?', 'required = ?', 'position = ?'];
        $vals = [$label, (int)$required, $position];
        if ($type !== null && $type !== '') { $set[] = 'type = ?';          $vals[] = $type; }
        if ($helpText !== null)             { $set[] = 'help_text = ?';     $vals[] = ($helpText !== '' ? $helpText : null); }
        if ($sectionIndex !== null)         { $set[] = 'section_index = ?'; $vals[] = $sectionIndex; }
        $vals[] = $id;
        $stmt = $this->db->prepare("UPDATE questions SET " . implode(', ', $set) . " WHERE id = ?");
        $ok = $stmt->execute($vals);

        // ✅ FIX : mise à jour des options (radio/checkbox/dropdown)
        if (!empty($options)) {
            $this->deleteOptions($id);
            foreach ($options as $opt) {
                $this->addOption($id, $opt);
            }
        } elseif ($type !== null && !in_array($type, ['radio', 'checkbox', 'dropdown'], true)) {
            // Changement vers un type sans options → nettoyer les anciennes
            $this->deleteOptions($id);
        }

        // ✅ FIX : mise à jour grid_rows/grid_columns
        if (isset($extra['grid_rows']) || isset($extra['grid_columns'])) {
            $gridRows = isset($extra['grid_rows'])    ? json_encode($extra['grid_rows'])    : null;
            $gridCols = isset($extra['grid_columns']) ? json_encode($extra['grid_columns']) : null;
            $s = $this->db->prepare("UPDATE questions SET grid_rows = ?, grid_columns = ? WHERE id = ?");
            $s->execute([$gridRows, $gridCols, $id]);
        }

        // ✅ FIX : mise à jour scale_min/max/step
        if (isset($extra['scale_min']) || isset($extra['scale_max'])) {
            $s = $this->db->prepare("UPDATE questions SET scale_min = ?, scale_max = ?, scale_step = ? WHERE id = ?");
            $s->execute([$extra['scale_min'] ?? 1, $extra['scale_max'] ?? 5, $extra['scale_step'] ?? 1, $id]);
        }

        // Mise à jour phone_default_country
        if (array_key_exists('phone_default_country', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET phone_default_country = ? WHERE id = ?");
            $s->execute([$extra['phone_default_country'], $id]);
        }

        // Listes de choix en cascade (B7)
        if (array_key_exists('cascade_list_id', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET cascade_list_id = ? WHERE id = ?");
            $s->execute([$extra['cascade_list_id'] !== null ? (int) $extra['cascade_list_id'] : null, $id]);
        }
        if (array_key_exists('cascade_parent_question_id', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET cascade_parent_question_id = ? WHERE id = ?");
            $s->execute([$extra['cascade_parent_question_id'] !== null ? (int) $extra['cascade_parent_question_id'] : null, $id]);
        }
        if (array_key_exists('media_max_duration_s', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET media_max_duration_s = ? WHERE id = ?");
            $s->execute([$extra['media_max_duration_s'] !== null ? (int) $extra['media_max_duration_s'] : null, $id]);
        }
        if (array_key_exists('allow_photo', $extra)) { // M5
            $s = $this->db->prepare("UPDATE questions SET allow_photo = ? WHERE id = ?");
            $s->execute([!empty($extra['allow_photo']) ? 1 : 0, $id]);
        }
        if (array_key_exists('calculated_expression', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET calculated_expression = ? WHERE id = ?");
            $s->execute([$extra['calculated_expression'] !== null ? (string) $extra['calculated_expression'] : null, $id]);
        }
        if (array_key_exists('repeat_group_id', $extra)) {
            $s = $this->db->prepare("UPDATE questions SET repeat_group_id = ? WHERE id = ?");
            $s->execute([$extra['repeat_group_id'] !== null ? (int) $extra['repeat_group_id'] : null, $id]);
        }

        return $ok;
    }

    // ── Bulk reorder (atomique via transaction) ───────────────────────────────
    /**
     * @param array $items  [ ['id' => int, 'position' => int], … ]
     */
    public function bulkReorder(array $items): bool {
        $this->db->beginTransaction();
        try {
            // FIX: mettre à jour section_index en plus de position
            $stmt = $this->db->prepare("UPDATE questions SET position = ?, section_index = ? WHERE id = ?");
            foreach ($items as $item) {
                if (!isset($item['id'], $item['position'])) {
                    throw new \InvalidArgumentException("Each item needs 'id' and 'position'");
                }
                $sectionIndex = isset($item['section_index']) ? (int)$item['section_index'] : 0;
                $stmt->execute([(int)$item['position'], $sectionIndex, (int)$item['id']]);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return false;
        }
    }

    // ── Suppression ───────────────────────────────────────────────────────────
    public function deleteQuestion(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM questions WHERE id = ?");
        return $stmt->execute([$id]);
    }
}