<?php
namespace Modules\Identity\Models;

use Core\Database;
use PDO;

class RoleModel {
    private PDO $db;

    // Rôles prédéfinis avec leurs permissions par défaut
    const DEFAULT_ROLES = [
        'admin'  => [
            'users.manage', 'users.view',
            'forms.create', 'forms.edit', 'forms.delete', 'forms.publish',
            'responses.view', 'responses.delete', 'responses.export',
            'roles.manage', 'analytics.view', 'settings.manage'
        ],
        'editor' => [
            'forms.create', 'forms.edit', 'forms.publish',
            'responses.view', 'responses.export', 'analytics.view'
        ],
        'viewer' => [
            'responses.view', 'analytics.view'
        ],
    ];

    /** Le seed ne doit tourner qu'une fois par process PHP (et pas à chaque requête). */
    private static bool $seeded = false;

    public function __construct() {
        $this->db = Database::getConnection();
        if (!self::$seeded) {
            $this->seedDefaultRoles();
            self::$seeded = true;
        }
    }

    // ── Seed automatique des rôles prédéfinis ─────────────────────────────────
    private function seedDefaultRoles(): void {
        // Déjà peuplé → sortir immédiatement (1 requête au lieu de ~80).
        try {
            if ((int)$this->db->query("SELECT COUNT(*) FROM role_permissions")->fetchColumn() > 0) {
                return;
            }
        } catch (\Throwable $e) {
            // table absente / erreur → on laisse le seed complet tenter sa chance
        }

        foreach (self::DEFAULT_ROLES as $roleName => $permissions) {
            // Créer le rôle s'il n'existe pas
            $stmt = $this->db->prepare("INSERT IGNORE INTO roles (name) VALUES (?)");
            $stmt->execute([$roleName]);

            // Récupérer l'ID du rôle
            $stmt = $this->db->prepare("SELECT id FROM roles WHERE name = ?");
            $stmt->execute([$roleName]);
            $role = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$role) continue;

            // Créer et assigner chaque permission
            foreach ($permissions as $permName) {
                // Créer la permission si elle n'existe pas
                $this->db->prepare("INSERT IGNORE INTO permissions (name) VALUES (?)")
                         ->execute([$permName]);

                // Récupérer l'ID de la permission
                $stmt2 = $this->db->prepare("SELECT id FROM permissions WHERE name = ?");
                $stmt2->execute([$permName]);
                $perm = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$perm) continue;

                // Assigner la permission au rôle
                $this->db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)")
                         ->execute([$role['id'], $perm['id']]);
            }
        }
    }

    // ── CRUD Roles ────────────────────────────────────────────────────────────
    public function createRole(string $name): int {
        $stmt = $this->db->prepare("INSERT INTO roles (name) VALUES (?)");
        $stmt->execute([$name]);
        return (int)$this->db->lastInsertId();
    }

    public function getRoles(): array {
        $roles = $this->db->query("SELECT * FROM roles ORDER BY id ASC")
                          ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roles as &$role) {
            $stmt = $this->db->prepare("
                SELECT p.id, p.name FROM permissions p
                INNER JOIN role_permissions rp ON rp.permission_id = p.id
                WHERE rp.role_id = ?
            ");
            $stmt->execute([$role['id']]);
            $role['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Marquer les rôles prédéfinis pour les protéger dans l'UI
            $role['is_default'] = array_key_exists($role['name'], self::DEFAULT_ROLES);
        }
        return $roles;
    }

    public function updateRole(int $id, string $name): bool {
        // Empêcher la modification du nom des rôles prédéfinis
        $stmt = $this->db->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role && array_key_exists($role['name'], self::DEFAULT_ROLES)) {
            return false; // Protégé
        }
        $stmt = $this->db->prepare("UPDATE roles SET name = ? WHERE id = ?");
        return $stmt->execute([$name, $id]);
    }

    public function deleteRole(int $id): bool {
        // Empêcher la suppression des rôles prédéfinis
        $stmt = $this->db->prepare("SELECT name FROM roles WHERE id = ?");
        $stmt->execute([$id]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($role && array_key_exists($role['name'], self::DEFAULT_ROLES)) {
            return false; // Protégé
        }
        $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
        $this->db->prepare("DELETE FROM user_roles WHERE role_id = ?")->execute([$id]);
        return $this->db->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
    }

    // ── CRUD Permissions ──────────────────────────────────────────────────────
    public function createPermission(string $name): int {
        $stmt = $this->db->prepare("INSERT INTO permissions (name) VALUES (?)");
        $stmt->execute([$name]);
        return (int)$this->db->lastInsertId();
    }

    public function getPermissions(): array {
        return $this->db->query("SELECT * FROM permissions ORDER BY name ASC")
                        ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updatePermission(int $id, string $name): bool {
        $stmt = $this->db->prepare("UPDATE permissions SET name = ? WHERE id = ?");
        return $stmt->execute([$name, $id]);
    }

    public function deletePermission(int $id): bool {
        $this->db->prepare("DELETE FROM role_permissions WHERE permission_id = ?")->execute([$id]);
        return $this->db->prepare("DELETE FROM permissions WHERE id = ?")->execute([$id]);
    }

    // ── Assignation permission ↔ rôle ─────────────────────────────────────────
    public function assignPermission(int $roleId, int $permissionId, bool $granted = true): bool {
        if ($granted) {
            $stmt = $this->db->prepare("INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
        } else {
            $stmt = $this->db->prepare("DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?");
        }
        return $stmt->execute([$roleId, $permissionId]);
    }

    // ── Assignation user ↔ rôle ───────────────────────────────────────────────
    public function assignRole(int $userId, int $roleId): bool {
        $stmt = $this->db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)");
        return $stmt->execute([$userId, $roleId]);
    }

    public function findUserIdByEmail(string $email): ?int {
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : null;
    }
}