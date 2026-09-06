<?php
namespace Modules\Identity\Controllers;

use Modules\Identity\Services\RoleService;

class RoleController {
    private RoleService $service;

    public function __construct() {
        $this->service = new RoleService();
    }

    // ── Roles ─────────────────────────────────────────────────────────────────
    public function createRole() {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['name'])) { http_response_code(400); echo json_encode(["success" => false, "error" => "name requis"]); return; }
        $id = $this->service->createRole($data['name']);
        echo json_encode(["success" => true, "role_id" => $id]);
    }

    public function listRoles() {
        echo json_encode($this->service->listRoles());
    }

    public function updateRole($id) {
        $data = json_decode(file_get_contents("php://input"), true);
        $ok = $this->service->updateRole((int)$id, $data['name'] ?? '');
        echo json_encode(["success" => $ok]);
    }

    public function deleteRole($id) {
        $ok = $this->service->deleteRole((int)$id);
        echo json_encode(["success" => $ok]);
    }

    // ── Permissions ───────────────────────────────────────────────────────────
    public function createPermission() {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['name'])) { http_response_code(400); echo json_encode(["success" => false, "error" => "name requis"]); return; }
        $id = $this->service->createPermission($data['name']);
        echo json_encode(["success" => true, "permission_id" => $id]);
    }

    public function listPermissions() {
        echo json_encode($this->service->listPermissions());
    }

    public function updatePermission($id) {
        $data = json_decode(file_get_contents("php://input"), true);
        $ok = $this->service->updatePermission((int)$id, $data['name'] ?? '');
        echo json_encode(["success" => $ok]);
    }

    public function deletePermission($id) {
        $ok = $this->service->deletePermission((int)$id);
        echo json_encode(["success" => $ok]);
    }

    // ── Assignations ──────────────────────────────────────────────────────────
    // FIX: accepter email OU user_id pour assigner un rôle
    public function assignRole() {
        $data = json_decode(file_get_contents("php://input"), true);
        $identifier = $data['email'] ?? $data['user_id'] ?? null;
        if (!$identifier || empty($data['role_id'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "email (ou user_id) et role_id requis"]);
            return;
        }
        $result = $this->service->assignRoleToUser($identifier, (int)$data['role_id']);
        if (!$result['success']) http_response_code(404);
        echo json_encode($result);
    }

    // FIX: supporter granted=true/false pour cocher/décocher une permission
    public function assignPermission() {
        $data = json_decode(file_get_contents("php://input"), true);
        if (empty($data['role_id']) || empty($data['permission_id'])) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "role_id et permission_id requis"]);
            return;
        }
        $granted = isset($data['granted']) ? (bool)$data['granted'] : true;
        $ok = $this->service->assignPermissionToRole((int)$data['role_id'], (int)$data['permission_id'], $granted);
        echo json_encode(["success" => $ok]);
    }
}