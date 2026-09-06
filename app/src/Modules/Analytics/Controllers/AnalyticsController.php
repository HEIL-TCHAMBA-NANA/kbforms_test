<?php
namespace Modules\Analytics\Controllers;

use Modules\Analytics\Services\AnalyticsService;

class AnalyticsController
{
    private AnalyticsService $service;

    public function __construct()
    {
        $this->service = new AnalyticsService();
    }

    /**
     * GET /forms/{id}/analytics
     * Protégé (JWT requis).
     */
    public function getAnalytics(int $formId): void
    {
        $data = $this->service->getAnalytics($formId);
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
