<?php
namespace Modules\Template\Controllers;

use Modules\Template\Services\TemplateService;

class TemplateController
{
    private TemplateService $service;

    public function __construct()
    {
        $this->service = new TemplateService();
    }

    /** GET /templates — catégories + modèles disponibles. */
    public function listTemplates(): void
    {
        header('Content-Type: application/json');
        echo json_encode($this->service->listCatalog());
    }

    /**
     * POST /templates/use
     * Body JSON : { "user_id": 12, "template": "contact" }
     * Crée un formulaire complet et renvoie son form_id.
     */
    public function useTemplate(): void
    {
        header('Content-Type: application/json');

        $data     = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId   = $data['user_id']  ?? null;
        $template = $data['template'] ?? null;

        if (!$userId || !$template) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id and template are required']);
            return;
        }

        $result = $this->service->instantiate((int)$userId, (string)$template);
        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
    }
}
