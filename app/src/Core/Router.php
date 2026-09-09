<?php
namespace Core;

use Modules\Identity\Controllers\RoleController;
use Modules\Identity\Controllers\AuthController;
use Modules\Form\Controllers\FormController;
use Modules\Form\Controllers\QuestionController;
use Modules\Form\Controllers\ResponseController;
use Modules\Form\Controllers\ThemeController;           // FEAT-003
use Modules\Analytics\Controllers\AnalyticsController;
use Modules\Export\Controllers\ExportController;
use Modules\Sharing\Controllers\ShareController;
use Modules\Webhook\Controllers\WebhookController;
use Modules\Collaboration\Controllers\CollaborationController; // US-025
use Modules\Assignment\Controllers\AssignmentController;      // M2 · B2 (mobile)
use Modules\Notification\Controllers\DeviceController;        // M2 · B3 (mobile)
use Modules\ChoiceList\Controllers\ChoiceListController;      // M2 · B7 (mobile)
use Modules\RepeatGroup\Controllers\RepeatGroupController;    // M2 · B8 (mobile)
use Modules\Agent\Controllers\AgentController;                // M4 (agents de terrain)
use Modules\Section\Controllers\SectionController;
use Modules\GoogleSheets\Controllers\GoogleSheetsController; // US-021
use Modules\Template\Controllers\TemplateController;

class Router {
    private array $routes = [];

    /**
     * @param bool   $agentOk M4 — si $protected, autorise aussi un jeton agent
     *   (par défaut refusé : « tout endpoint de gestion de formulaire » est
     *   hors de portée d'un agent). N'a d'effet que si $protected = true.
     * @param string $formScope Contrôle d'appartenance au formulaire, pour les
     *   routes dont le PREMIER paramètre d'URL est un id de formulaire
     *   (`/forms/{id}/...`). '' = aucun (défaut), 'member' = propriétaire ou
     *   collaborateur, 'edit' = propriétaire/editor/admin, 'admin' =
     *   propriétaire/admin. N'a d'effet que si $protected = true. Les modules
     *   qui font déjà leur propre contrôle (agents, assignations, listes de
     *   choix, groupes répétables, réponses détaillées) restent à ''.
     */
    public function add(string $method, string $path, callable $handler, bool $protected = false, bool $agentOk = false, string $formScope = '') {
        $this->routes[] = compact('method', 'path', 'handler', 'protected', 'agentOk', 'formScope');
    }

    public function dispatch(string $method, string $uri) {
        foreach ($this->routes as $route) {
            $pattern = $route['path'];
            $pattern = preg_replace('/\{token\}/', '([a-zA-Z0-9]+)', $pattern);
            $pattern = preg_replace('/\{[a-zA-Z_]+\}/', '([0-9]+)', $pattern);
            $pattern = "@^" . $pattern . "$@";

            if ($method === $route['method'] && preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                if ($route['protected']) {
                    AuthMiddleware::authenticate();
                    // M4 : un jeton agent ne donne accès qu'aux quelques routes
                    // explicitement marquées agentOk (bundle, upload média).
                    if (AuthMiddleware::getAgent() !== null && empty($route['agentOk'])) {
                        http_response_code(403);
                        echo json_encode(["success" => false, "error" => "Ce jeton agent ne donne pas accès à cette ressource."]);
                        return;
                    }
                    // Contrôle d'appartenance au formulaire (1er param d'URL).
                    if (!empty($route['formScope'])) {
                        $me  = AuthMiddleware::getUser();
                        $uid = (int) ($me->user_id ?? 0);
                        $formId = (int) ($matches[0] ?? 0);
                        if (!FormGuard::enforce($route['formScope'], $formId, $uid)) {
                            return;
                        }
                    }
                }
                return call_user_func_array($route['handler'], $matches);
            }
        }
        http_response_code(404);
        echo json_encode(["error" => "Not Found"]);
    }
}

// ── Instanciation ─────────────────────────────────────────────────────────────
$router = new Router();

$roleController          = new RoleController();
$authController          = new AuthController();
$formController          = new FormController();
$questionController      = new QuestionController();
$responseController      = new ResponseController();
$themeController         = new ThemeController();
$analyticsController     = new AnalyticsController();
$exportController        = new ExportController();
$shareController         = new ShareController();
$webhookController       = new WebhookController();
$collaborationController = new CollaborationController();
$assignmentController    = new AssignmentController();
$deviceController        = new DeviceController();
$choiceListController     = new ChoiceListController();
$repeatGroupController    = new RepeatGroupController();
$agentController          = new AgentController();
$sectionController       = new SectionController();
$googleSheetsController  = new GoogleSheetsController(); // US-021
$templateController      = new TemplateController();

// ── Auth (public) ─────────────────────────────────────────────────────────────
$router->add("POST",   "/register",                          [$authController,          "register"]);
$router->add("POST",   "/login",                             [$authController,          "login"]);
$router->add("POST",   "/agent-login",                       [$agentController,         "login"]);              // M4 — connexion agent de terrain
$router->add("POST",   "/auth/refresh",                      [$authController,          "refresh"]);
$router->add("POST",   "/auth/forgot-password",              [$authController,          "forgotPassword"]);
$router->add("POST",   "/auth/reset-password",               [$authController,          "resetPassword"]);
$router->add("GET",    "/auth/config",                       [$authController,          "authConfig"]);
$router->add("GET",    "/download/apk",                      [$authController,          "mobileApkDownload"]);   // redirige vers l'APK (URL réelle non exposée)
$router->add("GET",    "/auth/google",                       [$authController,          "googleStart"]);
$router->add("GET",    "/auth/google/callback",              [$authController,          "googleCallback"]);

// ── Compte utilisateur (protégé) ──────────────────────────────────────────────
$router->add("GET",    "/users",                             [$authController,          "listUsers"],              true);
$router->add("GET",    "/users/{id}",                        [$authController,          "getUser"],                true);
$router->add("PUT",    "/users/{id}",                        [$authController,          "updateUser"],             true);
$router->add("DELETE", "/users/{id}",                        [$authController,          "deleteUser"],             true);

// ── Roles / Permissions (protégé) ─────────────────────────────────────────────
$router->add("POST",   "/roles",                             [$roleController,          "createRole"],             true);
$router->add("GET",    "/roles",                             [$roleController,          "listRoles"],              true);
$router->add("PUT",    "/roles/{id}",                        [$roleController,          "updateRole"],             true);
$router->add("DELETE", "/roles/{id}",                        [$roleController,          "deleteRole"],             true);
$router->add("POST",   "/permissions",                       [$roleController,          "createPermission"],       true);
$router->add("GET",    "/permissions",                       [$roleController,          "listPermissions"],        true);
$router->add("PUT",    "/permissions/{id}",                  [$roleController,          "updatePermission"],       true);
$router->add("DELETE", "/permissions/{id}",                  [$roleController,          "deletePermission"],       true);
$router->add("POST",   "/roles/assign",                      [$roleController,          "assignRole"],             true);
$router->add("POST",   "/roles/permissions/assign",          [$roleController,          "assignPermission"],       true);

// ── Formulaire public (public) ────────────────────────────────────────────────
$router->add("GET",    "/f/{token}",                         [$formController,          "getPublicForm"]);
$router->add("POST",   "/f/{token}/unlock",                  [$shareController,         "unlock"]);

// ── Modèles de formulaires prêts à l'emploi (protégé) ─────────────────────────
$router->add("GET",    "/templates",                         [$templateController,      "listTemplates"],          true);
$router->add("POST",   "/templates/use",                     [$templateController,      "useTemplate"],            true);

// ── Forms (protégé) ───────────────────────────────────────────────────────────
$router->add("POST",   "/forms",                             [$formController,          "createForm"],             true);
$router->add("POST",   "/forms/import/json",                 [$formController,          "importJson"],             true);
$router->add("POST",   "/forms/import/csv",                  [$formController,          "importCsv"],              true);
$router->add("POST",   "/forms/{id}/publish",                [$formController,          "publishForm"],            true, false, 'admin');
$router->add("POST",   "/forms/{id}/duplicate",              [$formController,          "duplicateForm"],          true, false, 'member');
$router->add("POST",   "/forms/{id}/validate",               [$formController,          "validateForm"]); // public : utilisé au remplissage (répondant anonyme), comme /responses et /logic/evaluate
$router->add("POST",   "/forms/{id}/confirmation",           [$formController,          "setConfirmationMessage"], true, false, 'admin');
$router->add("POST",   "/forms/{id}/share-settings",         [$shareController,         "setShareSettings"],       true, false, 'admin');
$router->add("DELETE", "/forms/{id}",                        [$formController,          "deleteForm"],             true, false, 'admin');
$router->add("GET",    "/forms/{id}",                        [$formController,          "getForm"],                true, false, 'member');
$router->add("PUT",    "/forms/{id}",                        [$formController,          "updateForm"],             true, false, 'admin');
$router->add("GET",    "/forms/{id}/bundle",                 [$formController,          "getBundle"],              true, true); // M4 : agentOk — contrôle d'accès dans le service (CollaborationService::canAccess ou scope du jeton agent)
$router->add("GET",    "/forms/{id}/version",                [$formController,          "getFormVersion"],         true, false, 'member');
$router->add("GET",    "/forms/{id}/responses",              [$responseController,      "getResponses"],           true, false, 'member');
$router->add("GET",    "/forms/{id}/questions",              [$questionController,      "getQuestions"],           true, false, 'member');
$router->add("GET",    "/forms/{id}/analytics",              [$analyticsController,     "getAnalytics"],           true, false, 'member');
$router->add("GET",    "/forms/{id}/export/csv",             [$exportController,        "exportCsv"],              true, false, 'member');
$router->add("POST",   "/forms/{id}/webhooks",               [$webhookController,       "create"],                 true, false, 'admin');
$router->add("GET",    "/forms/{id}/webhooks",               [$webhookController,       "list"],                   true, false, 'admin');

// ── FEAT-003 : Thème visuel (protégé) ─────────────────────────────────────────
$router->add("GET",    "/forms/{id}/theme",                  [$themeController,         "getTheme"],               true, false, 'member');
$router->add("PATCH",  "/forms/{id}/theme",                  [$themeController,         "updateTheme"],            true, false, 'edit');

// ── US-025 : Collaborateurs (protégé) ─────────────────────────────────────────
$router->add("GET",    "/forms/{id}/collaborators",          [$collaborationController, "list"],                   true, false, 'member');
$router->add("POST",   "/forms/{id}/collaborators",          [$collaborationController, "add"],                    true, false, 'admin');
$router->add("DELETE", "/forms/{id}/collaborators/{id}",     [$collaborationController, "remove"],                 true, false, 'admin');
$router->add("GET",    "/forms/{id}/invitations",            [$collaborationController, "listInvitations"],        true, false, 'admin');
$router->add("DELETE", "/forms/{id}/invitations/{id}",       [$collaborationController, "revokeInvitation"],       true, false, 'admin');
$router->add("GET",    "/invitations/{token}",               [$collaborationController, "showInvitation"]);
$router->add("POST",   "/invitations/{token}/accept",        [$collaborationController, "acceptInvitation"],       true);

$router->add("GET",    "/users/{id}/forms",                  [$formController,          "listForms"],              true);
$router->add("GET",    "/me/forms",                          [$formController,          "listMyForms"],            true);

// ── M2 · B2 : Assignation d'enquêtes (mobile) ────────────────────────────────
$router->add("GET",    "/me/assignments",                    [$assignmentController,    "myAssignments"],          true);
$router->add("GET",    "/forms/{id}/assignments",            [$assignmentController,    "listForForm"],            true);
$router->add("POST",   "/forms/{id}/assignments",            [$assignmentController,    "create"],                 true);
$router->add("DELETE", "/forms/{id}/assignments/{id}",       [$assignmentController,    "remove"],                 true);
$router->add("PATCH",  "/assignments/{id}",                  [$assignmentController,    "updateStatus"],           true);

// ── M2 · B3 : Enregistrement des appareils pour les notifications push ────────
$router->add("POST",   "/me/devices",                        [$deviceController,        "register"],               true);
$router->add("DELETE", "/me/devices",                        [$deviceController,        "unregister"],             true);

// ── M2 · B7 : Listes de choix en cascade ────────────────────────────────────
$router->add("GET",    "/forms/{id}/choice-lists",           [$choiceListController,    "listForForm"],            true);
$router->add("POST",   "/forms/{id}/choice-lists",           [$choiceListController,    "create"],                 true);
$router->add("PUT",    "/choice-lists/{id}",                 [$choiceListController,    "rename"],                 true);
$router->add("DELETE", "/choice-lists/{id}",                 [$choiceListController,    "delete"],                 true);
$router->add("POST",   "/choice-lists/{id}/items",           [$choiceListController,    "addItem"],                true);
$router->add("POST",   "/choice-lists/{id}/import",          [$choiceListController,    "import"],                 true);
$router->add("DELETE", "/choice-lists/{id}/items/{id}",      [$choiceListController,    "deleteItem"],             true);

// ── M2 · B8 : Groupes de questions répétables ───────────────────────────────
$router->add("GET",    "/forms/{id}/repeat-groups",          [$repeatGroupController,   "listForForm"],            true);
$router->add("POST",   "/forms/{id}/repeat-groups",          [$repeatGroupController,   "create"],                 true);
$router->add("PUT",    "/repeat-groups/{id}",                [$repeatGroupController,   "update"],                 true);
$router->add("DELETE", "/repeat-groups/{id}",                [$repeatGroupController,   "delete"],                 true);

// ── M4 : Agents de terrain scopés à une enquête (propriétaire/admin) ────────
$router->add("POST",   "/forms/{id}/agents",                 [$agentController,         "create"],                 true);
$router->add("GET",    "/forms/{id}/agents",                 [$agentController,         "listForForm"],            true);
$router->add("POST",   "/forms/{id}/agents/{id}/regenerate-password", [$agentController, "regeneratePassword"],    true);
$router->add("PATCH",  "/forms/{id}/agents/{id}",            [$agentController,         "patch"],                  true);
$router->add("DELETE", "/forms/{id}/agents/{id}",            [$agentController,         "delete"],                 true);

// ── US-014 : Sections (protégé) ───────────────────────────────────────────────
$router->add("POST",   "/forms/{id}/sections",           [$sectionController, "createSection"],   true, false, 'edit');
$router->add("GET",    "/forms/{id}/sections",           [$sectionController, "listSections"],    true, false, 'member');
$router->add("PUT",    "/sections/{id}",                 [$sectionController, "updateSection"],   true); // contrôle dans le contrôleur (id de section → formulaire)
$router->add("DELETE", "/sections/{id}",                 [$sectionController, "deleteSection"],   true); // idem

// ── TECH-002 : Logique conditionnelle (protégé) ───────────────────────────────
$router->add("POST",   "/forms/{id}/conditions",         [$sectionController, "addCondition"],    true, false, 'edit');
$router->add("GET",    "/forms/{id}/conditions",         [$sectionController, "listConditions"],  true, false, 'member');
$router->add("DELETE", "/conditions/{id}",               [$sectionController, "deleteCondition"], true); // contrôle dans le contrôleur (id de condition → formulaire)
$router->add("POST",   "/forms/{id}/logic/evaluate",     [$sectionController, "evaluate"],        false); // public (utilisé lors du remplissage)

// ── Questions (protégé) ───────────────────────────────────────────────────────
$router->add("POST",   "/questions",                         [$questionController,      "createQuestion"],         true);
$router->add("PUT",    "/questions/reorder",                 [$questionController,      "reorderQuestions"],       true);
$router->add("PUT",    "/questions/{id}",                    [$questionController,      "updateQuestion"],         true);
$router->add("DELETE", "/questions/{id}",                    [$questionController,      "deleteQuestion"],         true);

// ── Réponses ──────────────────────────────────────────────────────────────────
$router->add("POST",   "/responses",                         [$responseController,      "submitResponse"]);
$router->add("GET",    "/responses/{id}",                    [$responseController,      "getResponseDetail"],      true);
$router->add("PUT",    "/responses/{id}",                    [$responseController,      "updateResponse"],         true);
$router->add("DELETE", "/responses/{id}",                    [$responseController,      "deleteResponse"],         true);
$router->add("POST",   "/responses/{id}/media",              [$responseController,      "uploadMedia"],            true, true); // M4 : agentOk
$router->add("POST",   "/forms/{id}/responses/purge",        [$responseController,      "purgeOld"],               true, false, 'admin');

// ── Webhooks gestion individuelle (protégé) ───────────────────────────────────
$router->add("DELETE", "/webhooks/{id}",                     [$webhookController,       "delete"],                 true); // contrôle dans le contrôleur (id de webhook → formulaire)
$router->add("PUT",    "/webhooks/{id}/toggle",              [$webhookController,       "toggle"],                 true); // idem

// ── US-021 : Google Sheets (protégé) ─────────────────────────────────────────
$router->add("GET",    "/forms/{id}/sheets",                 [$googleSheetsController, "getSheet"],       true, false, 'admin');
$router->add("POST",   "/forms/{id}/sheets/create",          [$googleSheetsController, "createSheet"],    true, false, 'admin');
$router->add("POST",   "/forms/{id}/sheets/link",            [$googleSheetsController, "linkSheet"],      true, false, 'admin');
$router->add("POST",   "/forms/{id}/sheets/export",          [$googleSheetsController, "exportAll"],      true, false, 'admin');
$router->add("POST",   "/forms/{id}/sheets/export/append",   [$googleSheetsController, "exportAppend"],   true, false, 'admin');
$router->add("POST",   "/forms/{id}/sheets/import",          [$googleSheetsController, "importFromSheet"],true, false, 'admin');
$router->add("DELETE", "/forms/{id}/sheets",                 [$googleSheetsController, "disconnect"],     true, false, 'admin');

// ── Dispatch ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = strtok($_SERVER['REQUEST_URI'], '?');
$router->dispatch($method, $uri);