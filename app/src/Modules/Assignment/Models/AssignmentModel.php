<?php
namespace Modules\Assignment\Models;

use Core\Database;
use PDO;

/**
 * Assignation d'un formulaire à un enquêteur (table form_assignments).
 * Distinct de form_collaborators : ici c'est une tâche de collecte, pas un droit.
 */
class AssignmentModel
{
    private PDO $db;

    public const VALID_STATUSES = ['pending', 'in_progress', 'done'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Crée ou rafraîchit l'assignation (form_id, assigned_to_user_id) est unique.
     * Un ré-assignement remet la note et le statut à 'pending'.
     */
    public function assign(int $formId, int $toUserId, int $byUserId, ?string $note): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO form_assignments (form_id, assigned_to_user_id, assigned_by_user_id, note)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                assigned_by_user_id = VALUES(assigned_by_user_id),
                note                = VALUES(note),
                status              = 'pending',
                updated_at          = current_timestamp()
        ");
        $stmt->execute([$formId, $toUserId, $byUserId, $note]);

        $id = (int) $this->db->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        // ON DUPLICATE KEY UPDATE sans insertion → relire l'id existant
        $stmt = $this->db->prepare(
            "SELECT id FROM form_assignments WHERE form_id = ? AND assigned_to_user_id = ?"
        );
        $stmt->execute([$formId, $toUserId]);
        return (int) $stmt->fetchColumn();
    }

    public function unassign(int $formId, int $toUserId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM form_assignments WHERE form_id = ? AND assigned_to_user_id = ?"
        );
        $stmt->execute([$formId, $toUserId]);
        return $stmt->rowCount() > 0;
    }

    /** Assignations d'un formulaire, avec l'enquêteur joint. */
    public function listForForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.form_id, a.assigned_to_user_id, a.assigned_by_user_id,
                   a.status, a.note, a.created_at, a.updated_at,
                   u.email AS assignee_email,
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS assignee_name
            FROM form_assignments a
            JOIN users u ON u.id = a.assigned_to_user_id
            WHERE a.form_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']                  = (int) $r['id'];
            $r['form_id']             = (int) $r['form_id'];
            $r['assigned_to_user_id'] = (int) $r['assigned_to_user_id'];
            $r['assigned_by_user_id'] = (int) $r['assigned_by_user_id'];
            $r['assignee_name']       = $r['assignee_name'] ?: $r['assignee_email'];
        }
        return $rows;
    }

    /** Assignations d'un enquêteur, avec le formulaire joint (formulaires supprimés exclus). */
    public function listForUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.form_id, a.status, a.note,
                   a.assigned_by_user_id, a.created_at, a.updated_at,
                   f.title, f.description, f.is_published, f.version, f.updated_at AS form_updated_at,
                   NULLIF(TRIM(CONCAT(COALESCE(b.first_name, ''), ' ', COALESCE(b.last_name, ''))), '') AS assigned_by_name
            FROM form_assignments a
            JOIN forms f ON f.id = a.form_id
            LEFT JOIN users b ON b.id = a.assigned_by_user_id
            WHERE a.assigned_to_user_id = ?
            ORDER BY a.updated_at IS NULL, a.updated_at DESC, a.created_at DESC
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']                  = (int) $r['id'];
            $r['form_id']             = (int) $r['form_id'];
            $r['assigned_by_user_id'] = (int) $r['assigned_by_user_id'];
            $r['is_published']        = (int) $r['is_published'];
            $r['version']             = (int) ($r['version'] ?? 1);
        }
        return $rows;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM form_assignments WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE form_assignments SET status = ?, updated_at = current_timestamp() WHERE id = ?"
        );
        $stmt->execute([$status, $id]);
        return true;
    }
}
