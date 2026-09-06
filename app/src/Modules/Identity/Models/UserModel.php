<?php
namespace Modules\Identity\Models;

use Core\Database;
use Modules\Identity\Entities\User;
use PDO;

class UserModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    // ── Création ──────────────────────────────────────────────────────────────
    public function create(User $user): int {
        $stmt = $this->db->prepare("
            INSERT INTO users
                (first_name, last_name, email, password, account_type,
                 organization, industry, company_size, country, phone, website, job_title,
                 created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $user->firstName, $user->lastName, $user->email,
            $user->password, $user->accountType, $user->organization,
            $user->industry, $user->companySize, $user->country,
            $user->phone, $user->website, $user->jobTitle, $user->createdAt,
        ]);
        return (int)$this->db->lastInsertId();
    }

    // ── Lecture ───────────────────────────────────────────────────────────────
    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare("
            SELECT id, first_name, last_name, email, account_type,
                   organization, industry, company_size, country, phone, website, job_title,
                   avatar_data, created_at
            FROM users WHERE id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateAvatar(int $id, ?string $dataUri): bool {
        $stmt = $this->db->prepare("UPDATE users SET avatar_data = ? WHERE id = ?");
        return $stmt->execute([$dataUri, $id]);
    }

    // ── Mise à jour ───────────────────────────────────────────────────────────
    public function updateUser(
        int $id, string $firstName, string $lastName, ?string $organization,
        ?string $industry = null, ?string $companySize = null,
        ?string $country = null, ?string $phone = null,
        ?string $website = null, ?string $jobTitle = null
    ): bool {
        $stmt = $this->db->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, organization = ?,
                industry = ?, company_size = ?, country = ?,
                phone = ?, website = ?, job_title = ?
            WHERE id = ?
        ");
        return $stmt->execute([
            $firstName, $lastName, $organization,
            $industry, $companySize, $country,
            $phone, $website, $jobTitle, $id
        ]);
    }

    public function updatePassword(int $id, string $hashedPassword): bool {
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $id]);
    }

    // ── Suppression ───────────────────────────────────────────────────────────
    public function deleteUser(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        return $stmt->execute([$id]);
    }

    // ── Liste pour gestion des rôles ──────────────────────────────────────────
    // Admin → tous les utilisateurs sauf lui-même
    public function listAll(int $excludeId = 0): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email,
                   u.account_type, u.organization, u.created_at,
                   r.name AS role_name, r.id AS role_id
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.id != ? AND u.account_type != 'admin'
            ORDER BY u.first_name ASC
        ");
        $stmt->execute([$excludeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Enterprise → membres de la même organisation
    public function listByOrganization(string $organization, int $excludeId = 0): array {
        $stmt = $this->db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email,
                   u.account_type, u.organization, u.created_at,
                   r.name AS role_name, r.id AS role_id
            FROM users u
            LEFT JOIN user_roles ur ON ur.user_id = u.id
            LEFT JOIN roles r ON r.id = ur.role_id
            WHERE u.organization = ? AND u.id != ?
            ORDER BY u.first_name ASC
        ");
        $stmt->execute([$organization, $excludeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}