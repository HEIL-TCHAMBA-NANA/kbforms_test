<?php
namespace Modules\Collaboration\Models;

use Core\Database;
use PDO;

class CollaborationModel
{
    private PDO $db;

    private const VALID_ROLES = ['viewer', 'editor', 'admin'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function add(int $formId, int $userId, string $role): bool
    {
        if (!in_array($role, self::VALID_ROLES, true)) return false;

        $stmt = $this->db->prepare("
            INSERT INTO form_collaborators (form_id, user_id, role)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role)
        ");
        return $stmt->execute([$formId, $userId, $role]);
    }

    public function remove(int $formId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM form_collaborators WHERE form_id = ? AND user_id = ?"
        );
        return $stmt->execute([$formId, $userId]);
    }

    public function list(int $formId): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT fc.id, fc.user_id, u.email,
                       NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), '') AS name,
                       fc.role,
                       fc.invited_at
                FROM form_collaborators fc
                JOIN users u ON u.id = fc.user_id
                WHERE fc.form_id = ?
                ORDER BY fc.id ASC
            ");
            $stmt->execute([$formId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['name'] = $r['name'] ?: $r['email'];
            }
            return $rows;
        } catch (\PDOException $e) {
            return [];
        }
    }

    public function getRole(int $formId, int $userId): ?string
    {
        $stmt = $this->db->prepare("
            SELECT role FROM form_collaborators
            WHERE form_id = ? AND user_id = ?
        ");
        $stmt->execute([$formId, $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['role'] : null;
    }

    /**
     * Vérifie si un utilisateur est propriétaire OU collaborateur d'un formulaire.
     */
    public function canAccess(int $formId, int $userId): bool
    {
        // Propriétaire
        $stmt = $this->db->prepare("SELECT id FROM forms WHERE id = ? AND user_id = ?");
        $stmt->execute([$formId, $userId]);
        if ($stmt->fetch()) return true;

        // Collaborateur
        return $this->getRole($formId, $userId) !== null;
    }
}
