<?php
namespace Modules\Form\Services;
use Modules\Form\Models\FormModel;
use Modules\Form\Models\QuestionModel;
use Modules\Cache\Services\CacheService;
use Modules\Section\Models\SectionModel;

class FormService {
    private FormModel $model;

    public function __construct() {
        $this->model = new FormModel();
    }

    public function deleteForm(int $formId): array {
        // Invalider le cache public avant suppression (clé = token du share_link).
        $form = $this->model->getFormById($formId);
        if ($form && !empty($form['share_link'])
            && preg_match('#/f/([a-zA-Z0-9]+)$#', $form['share_link'], $m)) {
            try { (new CacheService())->invalidateForm($m[1]); } catch (\Throwable $e) {}
        }

        $success = $this->model->deleteForm($formId);
        return ["success" => $success, "message" => $success ? "Form deleted successfully" : "Failed to delete form"];
    }

    public function createForm(int $userId, string $title, ?string $description, ?string $bannerData = null): array {
        $formId = $this->model->createForm($userId, $title, $description, $bannerData);
        return ["success" => true, "message" => "Form created successfully", "form_id" => $formId];
    }

    public function listForms(int $userId): array {
        $forms = $this->model->getFormsByUser($userId);
        foreach ($forms as &$f) {
            if (!empty($f['share_link'])) {
                $f['share_link'] = \Core\AppUrl::rewriteHost($f['share_link']);
            }
        }
        return $forms;
    }

    /**
     * GET /forms/{id}/bundle — tout ce dont l'app a besoin pour mettre un
     * formulaire en cache et le faire remplir hors-ligne, en un seul appel.
     * Accès : propriétaire ou collaborateur.
     */
    public function getBundle(int $formId, int $userId): array {
        $collab = new \Modules\Collaboration\Services\CollaborationService();
        if (!$collab->canAccess($formId, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        return $this->buildBundle($formId);
    }

    /**
     * M4 : bundle pour un agent de terrain. Pas de contrôle CollaborationService
     * ici — l'accès a déjà été vérifié en amont (jeton agent revalidé par
     * AuthMiddleware + form_id de l'agent comparé à $formId par le contrôleur).
     */
    public function getBundleForAgent(int $formId): array {
        return $this->buildBundle($formId);
    }

    private function buildBundle(int $formId): array {
        $form = $this->model->getFormById($formId);
        if (!$form) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable'];
        }
        if (!empty($form['share_link'])) {
            $form['share_link'] = \Core\AppUrl::rewriteHost($form['share_link']);
        }

        $questions  = (new QuestionModel())->getQuestionsByForm($formId);
        $sections   = (new \Modules\Section\Models\SectionModel())->getByForm($formId);
        $conditions = (new \Modules\LogicEngine\Models\ConditionModel())->getByForm($formId);

        $theme = (new \Modules\Form\Services\ThemeService())->getTheme($formId);
        unset($theme['success'], $theme['error']);

        // Listes de choix en cascade utilisées par ce formulaire (B7) — l'app
        // les met en cache pour filtrer les niveaux hors-ligne.
        $choiceLists  = (new \Modules\ChoiceList\Services\ChoiceListService())->listsUsedByForm($formId);
        $repeatGroups = (new \Modules\RepeatGroup\Services\RepeatGroupService())->groupsForForm($formId);

        return [
            'success'       => true,
            'form'          => $form,
            'sections'      => $sections,
            'questions'     => $questions,
            'conditions'    => $conditions,
            'theme'         => $theme,
            'choice_lists'  => $choiceLists,
            'repeat_groups' => $repeatGroups,
            // forms.version arrivera avec le prérequis A5 ; 1 par défaut d'ici là.
            'version'       => (int) ($form['version'] ?? 1),
        ];
    }

    /**
     * GET /me/forms — formulaires possédés + ceux où l'utilisateur est
     * collaborateur. `$since` (ISO 8601) → synchro différentielle.
     */
    public function listMyForms(int $userId, ?string $since = null): array {
        $forms = $this->model->getFormsForUser($userId, $since);
        foreach ($forms as &$f) {
            if (!empty($f['share_link'])) {
                $f['share_link'] = \Core\AppUrl::rewriteHost($f['share_link']);
            }
            $f['id']             = (int) $f['id'];
            $f['user_id']        = (int) $f['user_id'];
            $f['is_published']   = (int) $f['is_published'];
            $f['response_count'] = (int) $f['response_count'];
            $f['version']        = (int) ($f['version'] ?? 1);
        }
        return $forms;
    }

    /** GET /forms/{id}/version — pour la synchro différentielle mobile. */
    public function getFormVersion(int $formId, int $userId): array {
        $collab = new \Modules\Collaboration\Services\CollaborationService();
        if (!$collab->canAccess($formId, $userId)) {
            return ['success' => false, 'code' => 'forbidden', 'error' => 'Accès refusé'];
        }
        $version = $this->model->getVersion($formId);
        if ($version === null) {
            return ['success' => false, 'code' => 'not_found', 'error' => 'Formulaire introuvable'];
        }
        return ['success' => true, 'version' => $version];
    }


    // ── GET /forms/{id} ───────────────────────────────────────────────────────
    public function getFormById(int $formId): ?array {
        $form = $this->model->getFormById($formId);
        if ($form && !empty($form['share_link'])) {
            $form['share_link'] = \Core\AppUrl::rewriteHost($form['share_link']);
        }
        return $form;
    }

    // ── PUT /forms/{id} ───────────────────────────────────────────────────────
    public function updateForm(int $formId, array $data): array {
        // Seuls les champs qui existent réellement dans la table forms
        $allowed = ['title', 'description', 'is_published', 'response_retention_days', 'require_captcha', 'notify_emails'];
        $payload = array_intersect_key($data, array_flip($allowed));
        if (empty($payload)) {
            return ['success' => false, 'error' => 'No valid fields to update'];
        }
        $ok = $this->model->updateForm($formId, $payload);
        return ['success' => $ok, 'message' => $ok ? 'Form updated' : 'Update failed'];
    }

    public function publishForm(int $formId): array {
        $form = $this->model->getFormById($formId);
        if (!$form) {
            return ["success" => false, "message" => "Form not found", "share_link" => null];
        }

        // Le jeton reste stable (les liens déjà partagés ne se cassent pas),
        // mais l'hôte est (re)calculé à chaque publication : IP LAN détectée
        // automatiquement si on est sur localhost, ou override config/app.php.
        $shareLink = $form['share_link']
            ? \Core\AppUrl::rewriteHost($form['share_link'])
            : (\Core\AppUrl::base() . "/f/" . bin2hex(random_bytes(6)));

        $success = $this->model->publishForm($formId, $shareLink);

        // Rafraîchir le cache public pour refléter les dernières questions/sections.
        if ($success && preg_match('#/f/([a-zA-Z0-9]+)$#', $shareLink, $m)) {
            (new CacheService())->invalidateForm($m[1]);
        }

        return [
            "success"    => $success,
            "message"    => $success ? "Form published successfully" : "Failed to publish form",
            "share_link" => $success ? $shareLink : null,
        ];
    }

    public function getPublicForm(string $token): array {
        // ── TECH-003 : tenter le cache avant la DB ────────────────────────
        $cache  = new CacheService();
        $cached = $cache->getForm($token);
        if ($cached !== null) {
            return $cached;
        }

        $form = $this->model->getFormByToken($token);
        if (!$form) {
            http_response_code(404);
            return ["error" => "Form not found or not published"];
        }
        $questionModel = new QuestionModel();
        $questions     = $questionModel->getQuestionsByForm((int)$form['id']);

        // Sections (pagination du formulaire public). `index` = position ordinale,
        // à comparer avec `section_index` des questions.
        $sectionModel = new SectionModel();
        $sections     = array_map(static function (array $s): array {
            $s['index'] = (int)$s['position'];
            return $s;
        }, $sectionModel->getByForm((int)$form['id']));

        // Listes de choix en cascade utilisées par le formulaire (B7).
        $choiceLists  = (new \Modules\ChoiceList\Services\ChoiceListService())->listsUsedByForm((int)$form['id']);
        $repeatGroups = (new \Modules\RepeatGroup\Services\RepeatGroupService())->groupsForForm((int)$form['id']);

        $result = ["success" => true, "form" => $form, "questions" => $questions, "sections" => $sections,
                   "choice_lists" => $choiceLists, "repeat_groups" => $repeatGroups];

        // Ne pas mettre en cache un formulaire encore sans question
        // (évite de figer 5 min un formulaire ouvert juste après publication).
        if (!empty($questions)) {
            $cache->setForm($token, $result);
        }
        return $result;
    }

    // ── US-015 : message de confirmation ─────────────────────────────────────
    /**
     * Définit le message affiché après soumission.
     * $message = null → revenir au message par défaut.
     */
    public function setConfirmationMessage(int $formId, ?string $message): array {
        $ok = $this->model->setConfirmationMessage($formId, $message);
        if (!$ok) {
            return ['success' => false, 'error' => 'Form not found'];
        }
        return [
            'success' => true,
            'confirmation_message' => $message ?? 'Votre réponse a bien été enregistrée.',
        ];
    }

    /**
     * Retourne le message de confirmation pour un formulaire.
     * Utilisé par ResponseService après soumission.
     */
    public function getConfirmationMessage(int $formId): string {
        $msg = $this->model->getConfirmationMessage($formId);
        return $msg ?? 'Votre réponse a bien été enregistrée.';
    }

    // ── Duplication ───────────────────────────────────────────────────────────
    public function duplicateForm(int $formId, int $userId): array {
        $newId = $this->model->duplicateForm($formId, $userId);
        if ($newId === null) {
            return ["success" => false, "error" => "Form not found or duplication failed"];
        }
        return ["success" => true, "message" => "Form duplicated successfully", "form_id" => $newId];
    }

    // ── Import JSON ───────────────────────────────────────────────────────────
    public function importFromJson(int $userId, string $jsonString): array {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["success" => false, "error" => "Invalid JSON: " . json_last_error_msg()];
        }
        return $this->importFromArray($userId, $data);
    }

    // ── Import CSV ────────────────────────────────────────────────────────────
    public function importFromCsv(int $userId, string $title, string $csvString): array {
        $lines = array_filter(array_map('trim', explode("\n", $csvString)));
        $questions = [];
        foreach ($lines as $i => $line) {
            $cols     = str_getcsv($line);
            $type     = $cols[0] ?? 'short_text';
            $label    = $cols[1] ?? 'Question ' . ($i + 1);
            $required = (bool)($cols[2] ?? false);
            $options  = isset($cols[3]) && $cols[3] !== ''
                        ? array_map('trim', explode('|', $cols[3]))
                        : [];
            $questions[] = [
                'type'     => $type,
                'label'    => $label,
                'required' => $required,
                'position' => $i,
                'options'  => $options,
            ];
        }
        return $this->importFromArray($userId, ['title' => $title, 'questions' => $questions]);
    }

    // ── Validation champs obligatoires ────────────────────────────────────────
    public function validateRequiredFields(int $formId, array $answers): array {
        $questionModel = new QuestionModel();
        $questions     = $questionModel->getQuestionsByForm($formId);
        $missing       = [];

        foreach ($questions as $q) {
            if (!$q['required']) continue;
            $value   = $answers[$q['id']] ?? null;
            $isEmpty = $value === null || $value === '' || (is_array($value) && count($value) === 0);
            if ($isEmpty) {
                $missing[] = ['question_id' => $q['id'], 'label' => $q['label']];
            }
        }

        return ['valid' => empty($missing), 'missing' => $missing];
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function importFromArray(int $userId, array $data): array {
        if (empty($data['title'])) {
            return ["success" => false, "error" => "title is required"];
        }
        if (empty($data['questions'])) {
            return ["success" => false, "error" => "questions array is required and must not be empty"];
        }

        $formId = $this->model->importForm($userId, $data);
        if ($formId === null) {
            return ["success" => false, "error" => "Import failed (transaction error)"];
        }
        return [
            "success"   => true,
            "message"   => "Form imported successfully",
            "form_id"   => $formId,
            "questions" => count($data['questions']),
        ];
    }
}
