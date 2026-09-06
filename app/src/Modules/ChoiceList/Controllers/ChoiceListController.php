<?php
namespace Modules\ChoiceList\Controllers;

use Modules\ChoiceList\Services\ChoiceListService;
use Core\AuthMiddleware;

class ChoiceListController
{
    private ChoiceListService $service;

    public function __construct()
    {
        $this->service = new ChoiceListService();
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

    // GET /forms/{id}/choice-lists
    public function listForForm(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->listForForm($formId, $uid));
    }

    // POST /forms/{id}/choice-lists  { name }
    public function create(int $formId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->createList($formId, (string) ($this->body()['name'] ?? ''), $uid));
    }

    // PUT /choice-lists/{id}  { name }
    public function rename(int $listId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->renameList($listId, (string) ($this->body()['name'] ?? ''), $uid));
    }

    // DELETE /choice-lists/{id}
    public function delete(int $listId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->deleteList($listId, $uid));
    }

    // POST /choice-lists/{id}/items  { label, value?, parent_item_id?, position? }
    public function addItem(int $listId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->addItem($listId, $this->body(), $uid));
    }

    // POST /choice-lists/{id}/import  { rows: [[lvl1, lvl2, ...], ...] }
    public function import(int $listId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $rows = $this->body()['rows'] ?? [];
        $this->emit($this->service->importRows($listId, is_array($rows) ? $rows : [], $uid));
    }

    // DELETE /choice-lists/{id}/items/{itemId}
    public function deleteItem(int $listId, int $itemId): void
    {
        header('Content-Type: application/json');
        if (($uid = $this->uid()) === null) return;
        $this->emit($this->service->deleteItem($itemId, $listId, $uid));
    }
}
