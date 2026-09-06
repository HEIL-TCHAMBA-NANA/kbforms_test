<?php
namespace Modules\Agent\Models;

use Core\Database;
use PDO;

/**
 * Agents de terrain scopés à une enquête (table form_agents, M4).
 * Un agent n'est pas un utilisateur KBForms : identifiant + mot de passe
 * générés par le système, valables pour un seul formulaire.
 */
class FormAgentModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function identifiantExists(string $identifiant): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM form_agents WHERE identifiant = ?");
        $stmt->execute([$identifiant]);
        return (bool) $stmt->fetchColumn();
    }

    public function create(int $formId, string $identifiant, string $passwordHash, string $displayName, string $email, int $createdByUserId): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO form_agents (form_id, identifiant, password_hash, display_name, email, created_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$formId, $identifiant, $passwordHash, $displayName, $email, $createdByUserId]);
        return (int) $this->db->lastInsertId();
    }

    /** Régénération du mot de passe (le mot de passe en clair n'est jamais stocké). */
    public function updatePasswordHash(int $id, string $passwordHash): bool
    {
        $stmt = $this->db->prepare("UPDATE form_agents SET password_hash = ? WHERE id = ?");
        return $stmt->execute([$passwordHash, $id]);
    }

    public function findByIdentifiant(string $identifiant): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM form_agents WHERE identifiant = ?");
        $stmt->execute([$identifiant]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM form_agents WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function belongsToForm(int $agentId, int $formId): bool
    {
        $stmt = $this->db->prepare("SELECT 1 FROM form_agents WHERE id = ? AND form_id = ?");
        $stmt->execute([$agentId, $formId]);
        return (bool) $stmt->fetchColumn();
    }

    public function updateLastLogin(int $id): void
    {
        $stmt = $this->db->prepare("UPDATE form_agents SET last_login_at = current_timestamp() WHERE id = ?");
        $stmt->execute([$id]);
    }

    public function setRevoked(int $id, bool $revoked): bool
    {
        $stmt = $this->db->prepare("UPDATE form_agents SET is_revoked = ? WHERE id = ?");
        return $stmt->execute([$revoked ? 1 : 0, $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM form_agents WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Agents d'un formulaire, avec le nombre de réponses collectées par chacun. */
    public function listForForm(int $formId): array
    {
        $stmt = $this->db->prepare("
            SELECT a.id, a.identifiant, a.display_name, a.email, a.is_revoked, a.created_at, a.last_login_at,
                   (SELECT COUNT(*) FROM responses r WHERE r.agent_id = a.id) AS response_count
            FROM form_agents a
            WHERE a.form_id = ?
            ORDER BY a.created_at ASC
        ");
        $stmt->execute([$formId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']             = (int) $r['id'];
            $r['is_revoked']     = (bool) $r['is_revoked'];
            $r['response_count'] = (int) $r['response_count'];
        }
        return $rows;
    }
}
