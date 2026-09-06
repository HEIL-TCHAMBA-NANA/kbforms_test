<?php
namespace Modules\Form\Models;
use Core\Database;
use PDO;

class FormModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function deleteForm(int $formId): bool {
        // Les FK ON DELETE CASCADE couvrent : questions → options/answers/conditions,
        // sections, responses → answers, form_google_sheets.
        // Ces tables-ci n'ont PAS de FK (types divergents) → nettoyage explicite
        // pour ne laisser aucune donnée résiduelle (tokens d'invitation, webhooks…).
        $this->db->beginTransaction();
        try {
            foreach (['form_collaborators', 'form_invitations', 'webhooks'] as $t) {
                $this->db->prepare("DELETE FROM `$t` WHERE form_id = ?")->execute([$formId]);
            }
            $ok = $this->db->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
            $this->db->commit();
            return $ok;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('[FormModel] deleteForm failed: ' . $e->getMessage());
            return false;
        }
    }

    public function createForm(int $userId, string $title, ?string $description, ?string $bannerData = null): int {
        $stmt = $this->db->prepare("INSERT INTO forms (user_id, title, description, banner_data) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $title, $description, $bannerData]);
        return (int)$this->db->lastInsertId();
    }

    public function getFormsByUser(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT f.id, f.user_id, f.title, f.description, f.is_published,
                   f.share_link, f.banner_data, f.created_at, f.updated_at,
                   COUNT(r.id) AS response_count
            FROM forms f
            LEFT JOIN responses r ON r.form_id = f.id
            WHERE f.user_id = ?
            GROUP BY f.id
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Formulaires accessibles à un utilisateur : ceux qu'il possède + ceux où il
     * est collaborateur. `role` = 'owner' | 'viewer' | 'editor' | 'admin'.
     * `$since` (datetime ISO) → ne renvoie que les formulaires modifiés depuis.
     * Sans `banner_data` (payload de synchro mobile allégé).
     */
    public function getFormsForUser(int $userId, ?string $since = null): array {
        $sinceSql = $since !== null ? " AND f.updated_at > ?" : "";
        $sql = "
            SELECT f.id, f.user_id, f.title, f.description, f.is_published,
                   f.share_link, f.version, f.created_at, f.updated_at,
                   CASE WHEN f.user_id = ? THEN 'owner' ELSE fc.role END AS role,
                   (SELECT COUNT(*) FROM responses r WHERE r.form_id = f.id) AS response_count
            FROM forms f
            LEFT JOIN form_collaborators fc ON fc.form_id = f.id AND fc.user_id = ?
            WHERE (f.user_id = ? OR fc.user_id = ?)$sinceSql
            ORDER BY f.updated_at DESC
        ";
        $params = [$userId, $userId, $userId, $userId];
        if ($since !== null) {
            $params[] = $since;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function publishForm(int $formId, string $shareLink): bool {
        $stmt = $this->db->prepare("UPDATE forms SET is_published = 1, share_link = ? WHERE id = ?");
        return $stmt->execute([$shareLink, $formId]);
    }

    public function getFormByToken(string $token): ?array {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE share_link LIKE ? AND is_published = 1");
        $stmt->execute(['%/f/' . $token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getFormById(int $formId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE id = ?");
        $stmt->execute([$formId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Version de structure du formulaire (cf. migration form_version). */
    public function getVersion(int $formId): ?int {
        $stmt = $this->db->prepare("SELECT version FROM forms WHERE id = ?");
        $stmt->execute([$formId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    // ── US-015 : message de confirmation ─────────────────────────────────────

    // ── PUT /forms/{id} ───────────────────────────────────────────────────────
    public function updateForm(int $formId, array $data): bool {
        $setClauses = [];
        $values     = [];
        $fieldMap   = [
            'title'        => 'title',
            'description'  => 'description',
            'is_published' => 'is_published',
            'response_retention_days' => 'response_retention_days',
            'require_captcha' => 'require_captcha',
            'notify_emails' => 'notify_emails',
        ];
        foreach ($fieldMap as $inputKey => $dbCol) {
            if (array_key_exists($inputKey, $data)) {
                $setClauses[] = "$dbCol = ?";
                $values[]     = $data[$inputKey];
            }
        }
        if (empty($setClauses)) return false;
        $setClauses[] = "updated_at = NOW()";
        $values[]     = $formId;
        $sql  = "UPDATE forms SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($values);
    }

    public function setConfirmationMessage(int $formId, ?string $message): bool {
        $stmt = $this->db->prepare("UPDATE forms SET confirmation_message = ? WHERE id = ?");
        return $stmt->execute([$message, $formId]);
    }

    public function getConfirmationMessage(int $formId): ?string {
        $stmt = $this->db->prepare("SELECT confirmation_message FROM forms WHERE id = ?");
        $stmt->execute([$formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['confirmation_message'] : null;
    }

    // ── Duplication ───────────────────────────────────────────────────────────
    public function duplicateForm(int $formId, int $userId): ?int {
        $original = $this->getFormById($formId);
        if (!$original) return null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("
                INSERT INTO forms (user_id, title, description, banner_data, confirmation_message)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $userId,
                $original['title'] . ' (copie)',
                $original['description'],
                $original['banner_data'],
                $original['confirmation_message'] ?? null,
            ]);
            $newFormId = (int)$this->db->lastInsertId();

            $stmtQ = $this->db->prepare("SELECT * FROM questions WHERE form_id = ? ORDER BY position ASC");
            $stmtQ->execute([$formId]);
            $questions = $stmtQ->fetchAll(PDO::FETCH_ASSOC);

            $stmtInsertQ = $this->db->prepare("
                INSERT INTO questions
                    (form_id, type, label, required, position, image_data, section_index,
                     scale_min, scale_max, scale_step, grid_rows, grid_columns)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtInsertO = $this->db->prepare("INSERT INTO options (question_id, value) VALUES (?, ?)");
            $stmtGetO    = $this->db->prepare("SELECT value FROM options WHERE question_id = ?");

            foreach ($questions as $q) {
                $stmtInsertQ->execute([
                    $newFormId, $q['type'], $q['label'], $q['required'], $q['position'],
                    $q['image_data'], $q['section_index'] ?? 0,
                    $q['scale_min'] ?? null, $q['scale_max'] ?? null, $q['scale_step'] ?? null,
                    $q['grid_rows'] ?? null, $q['grid_columns'] ?? null,
                ]);
                $newQId = (int)$this->db->lastInsertId();

                $stmtGetO->execute([$q['id']]);
                foreach ($stmtGetO->fetchAll(PDO::FETCH_COLUMN) as $val) {
                    $stmtInsertO->execute([$newQId, $val]);
                }
            }

            $this->db->commit();
            return $newFormId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return null;
        }
    }

    public function importForm(int $userId, array $data): ?int {
        if (empty($data['title']) || empty($data['questions'])) return null;

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO forms (user_id, title, description) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $data['title'], $data['description'] ?? null]);
            $formId = (int)$this->db->lastInsertId();

            // ── Sections ────────────────────────────────────────────────────
            // On recrée une structure de sections cohérente (comme le builder) :
            // soit à partir d'un tableau `sections` fourni, soit en synthétisant
            // une section par valeur distincte de `section_index` des questions.
            // Les positions sont rendues contiguës (0..N-1) et les section_index
            // des questions sont remappés en conséquence.
            $distinctIdx = [];
            foreach ($data['questions'] as $q) {
                $distinctIdx[(int)($q['section_index'] ?? 0)] = true;
            }
            ksort($distinctIdx);
            $providedSections = (isset($data['sections']) && is_array($data['sections']))
                ? array_values($data['sections']) : [];

            $sectionCount = $providedSections ? count($providedSections) : max(1, count($distinctIdx));
            $idxRemap = [];                       // ancien section_index => nouvel ordinal
            if (!$providedSections) {
                foreach (array_keys($distinctIdx) as $ord => $old) {
                    $idxRemap[$old] = $ord;
                }
            }

            $stmtS = $this->db->prepare(
                "INSERT INTO sections (form_id, title, description, position) VALUES (?, ?, ?, ?)"
            );
            for ($ord = 0; $ord < $sectionCount; $ord++) {
                $meta  = $providedSections[$ord] ?? [];
                $title = $meta['title'] ?? ($sectionCount === 1
                    ? 'Section sans titre' : 'Section ' . ($ord + 1));
                $stmtS->execute([$formId, $title, $meta['description'] ?? null, $ord]);
            }

            $stmtQ = $this->db->prepare("
                INSERT INTO questions
                    (form_id, type, label, required, position, section_index,
                     scale_min, scale_max, scale_step, grid_rows, grid_columns)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtO = $this->db->prepare("INSERT INTO options (question_id, value) VALUES (?, ?)");

            foreach ($data['questions'] as $i => $q) {
                $gridRows = isset($q['grid_rows'])    ? json_encode($q['grid_rows'])    : null;
                $gridCols = isset($q['grid_columns']) ? json_encode($q['grid_columns']) : null;

                $rawIdx     = (int)($q['section_index'] ?? 0);
                $sectionIdx = $providedSections
                    ? min($rawIdx, $sectionCount - 1)
                    : ($idxRemap[$rawIdx] ?? 0);

                $stmtQ->execute([
                    $formId,
                    $q['type']          ?? 'short_text',
                    $q['label']         ?? 'Question ' . ($i + 1),
                    (int)($q['required'] ?? false),
                    $q['position']      ?? $i,
                    $sectionIdx,
                    $q['scale_min']     ?? null,
                    $q['scale_max']     ?? null,
                    $q['scale_step']    ?? null,
                    $gridRows,
                    $gridCols,
                ]);
                $qId = (int)$this->db->lastInsertId();

                foreach ($q['options'] ?? [] as $opt) {
                    $stmtO->execute([$qId, $opt]);
                }
            }

            $this->db->commit();
            return $formId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            return null;
        }
    }
}
