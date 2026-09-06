<?php
namespace Modules\Identity\Services;

use Modules\Identity\Models\RoleModel;

class RoleService {
    private RoleModel $model;

    public function __construct() {
        $this->model = new RoleModel();
    }

    public function createRole(string $name): int         { return $this->model->createRole($name); }
    public function listRoles(): array                    { return $this->model->getRoles(); }
    public function updateRole(int $id, string $name): bool { return $this->model->updateRole($id, $name); }
    public function deleteRole(int $id): bool             { return $this->model->deleteRole($id); }

    public function createPermission(string $name): int   { return $this->model->createPermission($name); }
    public function listPermissions(): array              { return $this->model->getPermissions(); }
    public function updatePermission(int $id, string $name): bool { return $this->model->updatePermission($id, $name); }
    public function deletePermission(int $id): bool       { return $this->model->deletePermission($id); }

    // FIX: supporter granted toggle
    public function assignPermissionToRole(int $roleId, int $permissionId, bool $granted = true): bool {
        return $this->model->assignPermission($roleId, $permissionId, $granted);
    }

    // FIX: accepter email OU user_id
    public function assignRoleToUser($userIdentifier, int $roleId): array {
        $userId = null;
        if (is_int($userIdentifier) || ctype_digit((string)$userIdentifier)) {
            $userId = (int)$userIdentifier;
        } else {
            $userId = $this->model->findUserIdByEmail((string)$userIdentifier);
            if (!$userId) {
                return ['success' => false, 'error' => 'Utilisateur introuvable avec cet email'];
            }
        }
        $ok = $this->model->assignRole($userId, $roleId);
        return ['success' => $ok];
    }
}