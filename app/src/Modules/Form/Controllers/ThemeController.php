<?php
namespace Modules\Form\Controllers;

use Modules\Form\Services\ThemeService;

class ThemeController
{
    private ThemeService $service;

    public function __construct()
    {
        $this->service = new ThemeService();
    }

    /**
     * GET /forms/{id}/theme
     * Protégé. Retourne les paramètres visuels du formulaire.
     */
    public function getTheme(int $formId): void
    {
        $result = $this->service->getTheme($formId);
        if (!$result['success']) http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * PATCH /forms/{id}/theme
     * Protégé. Met à jour un ou plusieurs paramètres visuels.
     *
     * Body JSON (tous les champs sont optionnels) :
     * {
     *   "theme_color":  "#4F46E5",   (US-026)
     *   "header_image": "https://…", (US-027)  base64 ou URL
     *   "font_family":  "Inter",     (US-028)
     *   "font_size":    16           (US-028)
     * }
     */
    public function updateTheme(int $formId): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        $result = $this->service->updateTheme($formId, $input);

        if (!$result['success']) {
            http_response_code(isset($result['errors']) ? 422 : 404);
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }
}
