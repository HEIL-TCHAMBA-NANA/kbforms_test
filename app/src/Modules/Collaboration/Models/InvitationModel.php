<?php
namespace Modules\Collaboration\Models;

use Core\Database;
use PDO;

class InvitationModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Crée (ou réactive) une invitation pour un couple (form_id, email).
     * Renvoie le token.
     */
    public function upsert(int $formId, string $email, string $role, ?int $invitedBy): string
    {
        $token = bin2hex(random_bytes(20)); // 40 hex
        $stmt = $this->db->prepare("
            INSERT INTO form_invitations (form_id, email, role, token, invited_by, status, created_at, accepted_at)
            VALUES (?, ?, ?, ?, ?, 'pending', NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                role = VALUES(role),
                token = VALUES(token),
                invited_by = VALUES(invited_by),
                status = 'pending',
                created_at = NOW(),
                accepted_at = NULL
        ");
        $stmt->execute([$formId, $email, $role, $token, $invitedBy]);
        return $token;
    }

    public function findByToken(string $token): ?array
    {
        $stmt = $this->db->prepare("
            SELECT i.*, f.title AS form_title, f.user_id AS form_owner_id,
                   NULLIF(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))),'') AS inviter_name
            FROM form_invitations i
            JOIN forms f ON f.id = i.form_id
            LEFT JOIN users u ON u.id = i.invited_by
            WHERE i.token = ?
        ");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findPending(int $formId, string $email): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM form_invitations
            WHERE form_id = ? AND email = ? AND status = 'pending'
        ");
        $stmt->execute([$formId, $email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function listByForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT id, email, role, status, token, created_at, accepted_at
            FROM form_invitations
            WHERE form_id = ? AND status = 'pending'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$formId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countPending(int $formId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM form_invitations WHERE form_id = ? AND status = 'pending'"
        );
        $stmt->execute([$formId]);
        return (int) $stmt->fetchColumn();
    }

    public function markAccepted(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE form_invitations SET status = 'accepted', accepted_at = NOW() WHERE id = ?"
        );
        return $stmt->execute([$id]);
    }

    public function revoke(int $formId, int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE form_invitations SET status = 'revoked' WHERE id = ? AND form_id = ?"
        );
        return $stmt->execute([$id, $formId]);
    }
}
