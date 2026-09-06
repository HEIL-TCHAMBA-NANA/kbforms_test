<?php
namespace Modules\Form\Models;

use Core\Database;
use PDO;

class ResponseModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /** Colonnes de métadonnées de collecte acceptées dans $opts. */
    private const META_COLS = [
        'client_uuid', 'device_id', 'app_version',
        'gps_lat', 'gps_lng', 'gps_accuracy',
        'started_at', 'duration_s', 'mock_location', 'submitted_at',
    ];

    /**
     * @param array $opts    sous-ensemble de META_COLS (métadonnées mobiles).
     *                       `submitted_at` (ISO) surcharge l'horodatage serveur.
     * @param int|null $agentId M4 — agent de terrain à l'origine de la réponse
     *                       (mutuellement exclusif de $userId en pratique).
     */
    public function createResponse(int $formId, ?int $userId, ?string $ipHash = null, array $opts = [], ?int $agentId = null): int {
        $cols = ['form_id', 'user_id', 'ip_hash', 'agent_id'];
        $vals = [$formId, $userId, $ipHash, $agentId];
        foreach (self::META_COLS as $col) {
            if (!array_key_exists($col, $opts)) continue;
            $v = $opts[$col];
            if ($v === null || $v === '') continue;
            $cols[] = $col;
            $vals[] = $col === 'mock_location' ? ((int) (bool) $v) : $v;
        }
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $stmt = $this->db->prepare(
            'INSERT INTO responses (' . implode(', ', $cols) . ") VALUES ($placeholders)"
        );
        $stmt->execute($vals);
        return (int) $this->db->lastInsertId();
    }

    /** Id de la réponse portant ce client_uuid, ou null (idempotence). */
    public function findIdByClientUuid(string $clientUuid): ?int {
        $stmt = $this->db->prepare("SELECT id FROM responses WHERE client_uuid = ? LIMIT 1");
        $stmt->execute([$clientUuid]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Nombre de réponses d'une même IP sur ce formulaire dans la fenêtre donnée. */
    public function countRecentByIp(int $formId, string $ipHash, int $minutes): int {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM responses
            WHERE form_id = ? AND ip_hash = ? AND submitted_at >= (NOW() - INTERVAL ? MINUTE)
        ");
        $stmt->execute([$formId, $ipHash, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    public function addAnswer(int $responseId, int $questionId, string $value, ?int $repeatIndex = null): int {
        $stmt = $this->db->prepare("INSERT INTO answers (response_id, question_id, value, repeat_index) VALUES (?, ?, ?, ?)");
        $stmt->execute([$responseId, $questionId, $value, $repeatIndex]);
        return (int)$this->db->lastInsertId();
    }

    public function deleteAnswers(int $responseId): bool {
        $stmt = $this->db->prepare("DELETE FROM answers WHERE response_id = ?");
        return $stmt->execute([$responseId]);
    }

    public function updateSubmittedAt(int $responseId): bool {
        $stmt = $this->db->prepare("UPDATE responses SET submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
        return $stmt->execute([$responseId]);
    }

    public function getFormId(int $responseId): ?int {
        $stmt = $this->db->prepare("SELECT form_id FROM responses WHERE id = ?");
        $stmt->execute([$responseId]);
        $v = $stmt->fetchColumn();
        return $v === false ? null : (int) $v;
    }

    /** Ligne complète d'une réponse (GET /responses/{id} — consultation mobile). */
    public function getResponseById(int $responseId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM responses WHERE id = ?");
        $stmt->execute([$responseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getAnswersByResponse(int $responseId): array {
        $stmt = $this->db->prepare(
            "SELECT question_id, value, repeat_index FROM answers WHERE response_id = ? ORDER BY id ASC"
        );
        $stmt->execute([$responseId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Supprime une réponse (les answers suivent via FK ON DELETE CASCADE). */
    public function deleteResponse(int $responseId): bool {
        $stmt = $this->db->prepare("DELETE FROM responses WHERE id = ?");
        return $stmt->execute([$responseId]) && $stmt->rowCount() > 0;
    }

    /** Purge les réponses d'un formulaire antérieures à N jours. Retourne le nb supprimé. */
    public function deleteOlderThan(int $formId, int $days): int {
        $stmt = $this->db->prepare(
            "DELETE FROM responses WHERE form_id = ? AND submitted_at < (NOW() - INTERVAL ? DAY)"
        );
        $stmt->execute([$formId, $days]);
        return $stmt->rowCount();
    }

    public function getResponsesByForm(int $formId): array {
        // repeat_index : plusieurs occurrences par question dans un groupe répétable (B8).
        // media_count : signale une pièce jointe (response_media) sans la charger en masse —
        // le détail complet (GET /responses/{id}) la fournit à l'ouverture du tiroir.
        $stmt = $this->db->prepare("
            SELECT r.id AS response_id, r.user_id, r.submitted_at,
                   a.question_id, a.value, a.repeat_index,
                   (SELECT COUNT(*) FROM response_media rm WHERE rm.response_id = r.id) AS media_count
            FROM responses r
            LEFT JOIN answers a ON r.id = a.response_id
            WHERE r.form_id = ?
            ORDER BY r.submitted_at DESC
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

}