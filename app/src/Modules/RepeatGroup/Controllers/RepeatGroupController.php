<?php
namespace Modules\RepeatGroup\Controllers;

use Modules\RepeatGroup\Services\RepeatGroupService;
use Core\AuthMiddleware;

class RepeatGroupController
{
    private RepeatGroupService $service;

    public function __construct()
    {
        $this->service = new RepeatGroupService();
    }

    private function uid(): ?int
    {
        $u = AuthMiddleware::getUser();
        if (!$u) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Non authentifié']);
            return null;
        }
        return (int) $u->user_id;
    }

    private function emit(array $res): void
    {
        if (!($res['success'] ?? false)) {
            http_response_code(match ($res['code'] ?? '') {
                'forbidden' => 403,
                'not_found' => 404,
                default     => 400,
            });
        }
        echo json_encode($res);
    }

    private function body(): array
    {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    // GET /forms/{id}/repeat-groups
    public function listForForm(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->listForForm($formId, $uid));
    }

    // POST /forms/{id}/repeat-groups  { label, section_index?, min_repeat?, max_repeat?, position? }
    public function create(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->create($formId, $this->body(), $uid));
    }

    // PUT /repeat-groups/{id}
    public function update(int $groupId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->update($groupId, $this->body(), $uid));
    }

    // DELETE /repeat-groups/{id}
    public function delete(int $groupId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->delete($groupId, $uid));
    }
}
