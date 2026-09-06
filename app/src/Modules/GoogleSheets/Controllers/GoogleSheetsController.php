<?php
namespace Modules\GoogleSheets\Controllers;

use Modules\GoogleSheets\Services\GoogleSheetsService;

class GoogleSheetsController
{
    private GoogleSheetsService $service;

    public function __construct()
    {
        $this->service = new GoogleSheetsService();
    }

    /** Émet le JSON avec un code HTTP cohérent (422 si success=false). */
    private function respond(array $res): void
    {
        header('Content-Type: application/json');
        if (array_key_exists('success', $res) && $res['success'] === false) {
            http_response_code(422);
        }
        echo json_encode($res);
    }

    // GET /forms/{id}/sheets
    public function getSheet(int $formId): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->getSheet($formId));
    }

    // POST /forms/{id}/sheets/create
    public function createSheet(int $formId): void
    {
        $data  = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->respond($this->service->createSheet($formId, $data['title'] ?? null));
    }

    // POST /forms/{id}/sheets/link
    public function linkSheet(int $formId): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $this->respond($this->service->linkSheet($formId, $data));
    }

    // POST /forms/{id}/sheets/export
    public function exportAll(int $formId): void
    {
        $this->respond($this->service->exportAll($formId));
    }

    // POST /forms/{id}/sheets/export/append
    public function exportAppend(int $formId): void
    {
        $this->respond($this->service->exportAppend($formId));
    }

    // POST /forms/{id}/sheets/import
    public function importFromSheet(int $formId): void
    {
        $this->respond($this->service->importFromSheet($formId));
    }

    // DELETE /forms/{id}/sheets
    public function disconnect(int $formId): void
    {
        $this->respond($this->service->disconnect($formId));
    }
}
