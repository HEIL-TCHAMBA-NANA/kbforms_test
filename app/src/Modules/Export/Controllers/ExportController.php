<?php
namespace Modules\Export\Controllers;

use Modules\Export\Services\ExportService;

class ExportController
{
    private ExportService $service;

    public function __construct()
    {
        $this->service = new ExportService();
    }

    /**
     * GET /forms/{id}/export/csv
     * Protégé (JWT requis).
     */
    public function exportCsv(int $formId): void
    {
        $this->service->streamCsv($formId);
    }
}
