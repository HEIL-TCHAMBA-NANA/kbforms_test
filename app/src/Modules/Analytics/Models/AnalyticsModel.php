<?php
namespace Modules\Analytics\Models;

use Core\Database;
use PDO;

class AnalyticsModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function countResponses(int $formId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM responses WHERE form_id = ?");
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    public function countIdentifiedRespondents(int $formId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT user_id) FROM responses WHERE form_id = ? AND user_id IS NOT NULL"
        );
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    public function countAnonymousResponses(int $formId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM responses WHERE form_id = ? AND user_id IS NULL"
        );
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    /** ['first' => ?string, 'last' => ?string] */
    public function getResponseSpan(int $formId): array
    {
        $stmt = $this->db->prepare(
            "SELECT MIN(submitted_at) AS first, MAX(submitted_at) AS last FROM responses WHERE form_id = ?"
        );
        $stmt->execute([$formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['first' => $row['first'] ?? null, 'last' => $row['last'] ?? null];
    }

    /**
     * Réponses reçues dans la fenêtre [now - $fromDays, now - $toDays).
     * $toDays = 0 → borne haute = maintenant (incluse).
     */
    public function countResponsesInWindow(int $formId, int $fromDays, int $toDays = 0): int
    {
        if ($toDays <= 0) {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM responses
                WHERE form_id = ? AND submitted_at >= (NOW() - INTERVAL ? DAY)
            ");
            $stmt->execute([$formId, $fromDays]);
        } else {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM responses
                WHERE form_id = ?
                  AND submitted_at >= (NOW() - INTERVAL ? DAY)
                  AND submitted_at <  (NOW() - INTERVAL ? DAY)
            ");
            $stmt->execute([$formId, $fromDays, $toDays]);
        }
        return (int) $stmt->fetchColumn();
    }

    public function getQuestions(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, label, type, required, position, section_index, scale_min, scale_max, scale_step
            FROM questions
            WHERE form_id = ?
            ORDER BY position ASC
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getValueCounts(int $questionId): array
    {
        $stmt = $this->db->prepare("
            SELECT value, COUNT(*) AS count
            FROM answers
            WHERE question_id = ? AND value IS NOT NULL AND value <> ''
            GROUP BY value
            ORDER BY count DESC
        ");
        $stmt->execute([$questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Nombre de réponses (lignes answers non vides) par question, pour tout le
     * formulaire. Retour : [question_id => count].
     */
    public function getAnsweredCounts(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.question_id, COUNT(*) AS c
            FROM answers a
            JOIN responses r ON r.id = a.response_id
            WHERE r.form_id = ? AND a.value IS NOT NULL AND a.value <> ''
            GROUP BY a.question_id
        ");
        $stmt->execute([$formId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['question_id']] = (int) $row['c'];
        }
        return $out;
    }

    /** Cellules « question obligatoire renseignée » sur tout le formulaire. */
    public function countAnsweredForQuestions(int $formId, array $questionIds): int
    {
        $questionIds = array_values(array_filter(array_map('intval', $questionIds)));
        if (empty($questionIds)) return 0;
        $ph = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM answers a
            JOIN responses r ON r.id = a.response_id
            WHERE r.form_id = ? AND a.value IS NOT NULL AND a.value <> ''
              AND a.question_id IN ($ph)
        ");
        $stmt->execute(array_merge([$formId], $questionIds));
        return (int) $stmt->fetchColumn();
    }

    public function countAnswerRows(int $formId): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM answers a
            JOIN responses r ON r.id = a.response_id
            WHERE r.form_id = ? AND a.value IS NOT NULL AND a.value <> ''
        ");
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    /** Valeurs numériques brutes d'une question (pour médiane / écart-type). */
    public function getNumericValues(int $questionId): array
    {
        $stmt = $this->db->prepare("
            SELECT CAST(value AS DECIMAL(12,4)) AS v
            FROM answers
            WHERE question_id = ?
              AND value REGEXP '^[[:space:]]*-?[0-9]+(\\.[0-9]+)?[[:space:]]*\$'
        ");
        $stmt->execute([$questionId]);
        return array_map(static fn ($r) => (float) $r['v'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Longueur moyenne (caractères) et nombre de mots moyen d'une question texte. */
    public function getTextMetrics(int $questionId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                AVG(CHAR_LENGTH(value)) AS avg_chars,
                AVG(CHAR_LENGTH(TRIM(value)) - CHAR_LENGTH(REPLACE(TRIM(value), ' ', '')) + 1) AS avg_words,
                MAX(CHAR_LENGTH(value)) AS max_chars
            FROM answers
            WHERE question_id = ? AND value IS NOT NULL AND value <> ''
        ");
        $stmt->execute([$questionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'avg_chars' => $row['avg_chars'] !== null ? round((float) $row['avg_chars'], 1) : null,
            'avg_words' => $row['avg_words'] !== null ? round((float) $row['avg_words'], 1) : null,
            'max_chars' => $row['max_chars'] !== null ? (int) $row['max_chars'] : null,
        ];
    }

    public function getResponsesPerDay(int $formId, int $days = 30): array
    {
        $stmt = $this->db->prepare("
            SELECT DATE(submitted_at) AS day, COUNT(*) AS count
            FROM responses
            WHERE form_id = ?
              AND submitted_at >= (CURDATE() - INTERVAL ? DAY)
            GROUP BY DATE(submitted_at)
            ORDER BY day ASC
        ");
        $stmt->execute([$formId, $days]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** [0..6] => count  (0 = lundi, via WEEKDAY()). */
    public function getResponsesByWeekday(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT WEEKDAY(submitted_at) AS wd, COUNT(*) AS c
            FROM responses WHERE form_id = ?
            GROUP BY WEEKDAY(submitted_at)
        ");
        $stmt->execute([$formId]);
        $out = array_fill(0, 7, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['wd']] = (int) $row['c'];
        }
        return $out;
    }

    /** [0..23] => count. */
    public function getResponsesByHour(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT HOUR(submitted_at) AS h, COUNT(*) AS c
            FROM responses WHERE form_id = ?
            GROUP BY HOUR(submitted_at)
        ");
        $stmt->execute([$formId]);
        $out = array_fill(0, 24, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['h']] = (int) $row['c'];
        }
        return $out;
    }

    public function getRawResponses(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT
                r.id          AS response_id,
                r.user_id,
                r.submitted_at,
                q.id          AS question_id,
                q.label       AS question_label,
                a.value,
                a.repeat_index
            FROM responses r
            JOIN answers a   ON a.response_id  = r.id
            JOIN questions q ON q.id           = a.question_id
            WHERE r.form_id = ?
            ORDER BY r.submitted_at ASC, q.position ASC, a.repeat_index ASC
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
