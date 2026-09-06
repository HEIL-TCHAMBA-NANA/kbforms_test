<?php
namespace Modules\Template\Services;

use Core\Database;
use Modules\Template\Data\TemplateCatalog;
use Modules\Form\Models\FormModel;
use Modules\Form\Models\ThemeModel;
use Modules\Form\Services\QuestionService;
use Modules\Section\Models\SectionModel;

class TemplateService
{
    /** Galerie : catégories + modèles (métadonnées seules). */
    public function listCatalog(): array
    {
        return [
            'success'    => true,
            'categories' => TemplateCatalog::CATEGORIES,
            'templates'  => TemplateCatalog::catalog(),
        ];
    }

    /**
     * Crée un vrai formulaire appartenant à $userId à partir du modèle $key :
     * formulaire + sections + questions + options + couleur de thème.
     * Le tout dans une transaction (rollback si une insertion échoue).
     */
    public function instantiate(int $userId, string $key): array
    {
        $tpl = TemplateCatalog::get($key);
        if ($tpl === null) {
            return ['success' => false, 'error' => 'Unknown template: ' . $key];
        }

        $db = Database::getConnection();
        $formModel       = new FormModel();
        $sectionModel    = new SectionModel();
        $questionService = new QuestionService();
        $themeModel      = new ThemeModel();

        $ownTransaction = !$db->inTransaction();
        if ($ownTransaction) {
            $db->beginTransaction();
        }

        try {
            $formId = $formModel->createForm($userId, $tpl['name'], $tpl['description'] ?? null);

            foreach (array_values($tpl['sections']) as $sectionIndex => $section) {
                $sectionModel->create(
                    $formId,
                    $section['title'] ?? 'Section ' . ($sectionIndex + 1),
                    $section['description'] ?? null,
                    $sectionIndex
                );

                $position = 0;
                foreach ($section['questions'] ?? [] as $q) {
                    $type    = $q['type'] ?? 'short_text';
                    $options = $q['options'] ?? [];
                    $extra   = [];

                    if ($type === 'linear_scale') {
                        $extra['scale_min']  = $q['scale_min']  ?? 1;
                        $extra['scale_max']  = $q['scale_max']  ?? 5;
                        $extra['scale_step'] = $q['scale_step'] ?? 1;
                    }
                    if ($type === 'grid') {
                        $extra['grid_rows']    = $q['grid_rows']    ?? [];
                        $extra['grid_columns'] = $q['grid_columns'] ?? [];
                    }
                    if ($type === 'phone' && !empty($q['phone_default_country'])) {
                        $extra['phone_default_country'] = $q['phone_default_country'];
                    }

                    $questionService->createQuestion(
                        $formId,
                        $type,
                        $q['label'] ?? 'Question ' . ($position + 1),
                        (bool)($q['required'] ?? false),
                        $position,
                        $options,
                        null,
                        $sectionIndex,
                        $extra,
                        $q['help_text'] ?? null
                    );
                    $position++;
                }
            }

            if (!empty($tpl['accent'])) {
                $themeModel->updateTheme($formId, ['theme_color' => $tpl['accent']]);
            }

            if ($ownTransaction) {
                $db->commit();
            }

            return [
                'success'  => true,
                'form_id'  => $formId,
                'template' => $key,
                'message'  => 'Formulaire créé à partir du modèle « ' . $tpl['name'] . ' »',
            ];
        } catch (\Throwable $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log('[TemplateService] instantiate failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Template instantiation failed'];
        }
    }
}
