<?php
// En production : NE PAS afficher les erreurs dans la réponse HTTP
// (un warning PHP avant le JSON le corrompt → "Unexpected token '<'")
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
// Journal applicatif centralisé (dossier accessible en écriture par le process web).
foreach ([__DIR__ . '/../../logs/app.log', __DIR__ . '/../logs/app.log', sys_get_temp_dir() . '/kbforms-app.log'] as $__log) {
    if (@is_writable(dirname($__log))) { ini_set('error_log', $__log); break; }
}
error_reporting(E_ALL);

// Route /f/{token} → sert la page HTML si c'est une navigation navigateur
// Les appels fetch() JS ont Accept: */* sans text/html prioritaire
$uri    = strtok($_SERVER['REQUEST_URI'], '?');
$accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$isBrowserNav = strpos($accept, 'text/html') !== false;

// ── Pages HTML du frontend — servies par index.php ────────────────────────────
// Correspondance URI propre → fichier HTML dans /assets/html/
$htmlPages = [
    '/'                    => 'login.html',
    '/login'               => 'login.html',
    '/login.html'          => 'login.html',
    '/dashboard'           => 'dashboard.html',
    '/dashboard.html'      => 'dashboard.html',
    '/forms'               => 'forms.html',
    '/forms.html'          => 'forms.html',
    '/form-builder'        => 'form-builder.html',
    '/form-builder.html'   => 'form-builder.html',
    '/form-responses'      => 'form-responses.html',
    '/form-responses.html' => 'form-responses.html',
    '/form-analytics'      => 'form-analytics.html',
    '/form-analytics.html' => 'form-analytics.html',
    '/form-settings'       => 'form-settings.html',
    '/form-settings.html'  => 'form-settings.html',
    '/profile'             => 'profile.html',
    '/profile.html'        => 'profile.html',
    '/roles'               => 'roles.html',
    '/roles.html'          => 'roles.html',
    '/settings'             => 'settings.html',
    '/settings.html'        => 'settings.html',
    '/form-appearance'      => 'form-appearance.html',
    '/form-appearance.html' => 'form-appearance.html',
    '/form-public'          => 'form-public.html',
    '/form-public.html'     => 'form-public.html',
    '/invite'               => 'invite.html',
    '/invite.html'          => 'invite.html',
    '/download'             => 'download.html',
    '/download.html'        => 'download.html',
];

if (isset($htmlPages[$uri])
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && $isBrowserNav) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/assets/html/' . $htmlPages[$uri]);
    exit();
}

// Route /f/{token} → formulaire public
if (preg_match('@^/f/[a-zA-Z0-9]+$@', $uri)
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && $isBrowserNav) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/assets/html/form-public.html');
    exit();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Core
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/AppUrl.php';
require_once __DIR__ . '/../src/Core/Recaptcha.php';
require_once __DIR__ . '/../src/Core/MobileClient.php';

// Mail (transport email partagé)
require_once __DIR__ . '/../src/Modules/Mail/SmtpClient.php';
require_once __DIR__ . '/../src/Modules/Mail/MailService.php';

// Identity
require_once __DIR__ . '/../src/Modules/Identity/Entities/User.php';
require_once __DIR__ . '/../src/Modules/Identity/Models/UserModel.php';
require_once __DIR__ . '/../src/Modules/Identity/Models/RoleModel.php';
require_once __DIR__ . '/../src/Modules/Identity/Models/PasswordResetModel.php';
require_once __DIR__ . '/../src/Modules/Identity/Models/RefreshTokenModel.php';
require_once __DIR__ . '/../src/Modules/Identity/Services/AuthService.php';
require_once __DIR__ . '/../src/Modules/Identity/Services/GoogleAuthService.php';
require_once __DIR__ . '/../src/Modules/Identity/Services/RoleService.php';
require_once __DIR__ . '/../src/Modules/Identity/Controllers/AuthController.php';
require_once __DIR__ . '/../src/Modules/Identity/Controllers/RoleController.php';

// Form
require_once __DIR__ . '/../src/Modules/Form/Entities/Form.php';
require_once __DIR__ . '/../src/Modules/Form/Models/FormModel.php';
require_once __DIR__ . '/../src/Modules/Form/Models/QuestionModel.php';
require_once __DIR__ . '/../src/Modules/Form/Models/ResponseModel.php';
require_once __DIR__ . '/../src/Modules/Form/Models/ResponseMediaModel.php';
require_once __DIR__ . '/../src/Modules/Form/Services/FormService.php';
require_once __DIR__ . '/../src/Modules/Form/Services/QuestionService.php';
require_once __DIR__ . '/../src/Modules/Form/Services/ResponseService.php';
require_once __DIR__ . '/../src/Modules/Form/Controllers/FormController.php';
require_once __DIR__ . '/../src/Modules/Form/Controllers/QuestionController.php';
require_once __DIR__ . '/../src/Modules/Form/Controllers/ResponseController.php';

// Question & Response (modules autonomes)
require_once __DIR__ . '/../src/Modules/Question/Entities/Question.php';
require_once __DIR__ . '/../src/Modules/Question/Entities/Option.php';
require_once __DIR__ . '/../src/Modules/Question/Entities/QuestionType.php';
require_once __DIR__ . '/../src/Modules/Response/Entities/Response.php';
require_once __DIR__ . '/../src/Modules/Response/Entities/ResponseItem.php';

// Middleware
require_once __DIR__ . '/../src/Core/AuthMiddleware.php';
require_once __DIR__ . '/../src/Core/FormGuard.php';

// Sharing (FEAT-002)
require_once __DIR__ . '/../src/Modules/Sharing/Entities/ShareLink.php';
require_once __DIR__ . '/../src/Modules/Sharing/Models/ShareModel.php';
require_once __DIR__ . '/../src/Modules/Sharing/Services/ShareService.php';
require_once __DIR__ . '/../src/Modules/Sharing/Controllers/ShareController.php';

// Analytics (FEAT-001)
require_once __DIR__ . '/../src/Modules/Analytics/Models/AnalyticsModel.php';
require_once __DIR__ . '/../src/Modules/Analytics/Services/AnalyticsService.php';
require_once __DIR__ . '/../src/Modules/Analytics/Controllers/AnalyticsController.php';

// Export (FEAT-001)
require_once __DIR__ . '/../src/Modules/Export/Services/ExportService.php';
require_once __DIR__ . '/../src/Modules/Export/Controllers/ExportController.php';

// Cache (TECH-003)
require_once __DIR__ . '/../src/Modules/Cache/Models/CacheModel.php';
require_once __DIR__ . '/../src/Modules/Cache/Services/CacheService.php';

// Webhook (US-029)
require_once __DIR__ . '/../src/Modules/Webhook/Models/WebhookModel.php';
require_once __DIR__ . '/../src/Modules/Webhook/Services/WebhookService.php';
require_once __DIR__ . '/../src/Modules/Webhook/Controllers/WebhookController.php';


// Theme (FEAT-003)
require_once __DIR__ . '/../src/Modules/Form/Models/ThemeModel.php';
require_once __DIR__ . '/../src/Modules/Form/Services/ThemeService.php';
require_once __DIR__ . '/../src/Modules/Form/Controllers/ThemeController.php';

// Collaboration (US-025)
require_once __DIR__ . '/../src/Modules/Collaboration/Models/CollaborationModel.php';
require_once __DIR__ . '/../src/Modules/Collaboration/Models/InvitationModel.php';
require_once __DIR__ . '/../src/Modules/Collaboration/Services/CollaborationService.php';
require_once __DIR__ . '/../src/Modules/Collaboration/Controllers/CollaborationController.php';

// ── M2 · B3 : Notifications push (FCM) ───────────────────────────────────────
require_once __DIR__ . '/../src/Modules/Notification/Models/DeviceTokenModel.php';
require_once __DIR__ . '/../src/Modules/Notification/Services/PushService.php';
require_once __DIR__ . '/../src/Modules/Notification/Controllers/DeviceController.php';

// ── M2 · B2 : Assignation d'enquêtes (mobile) ────────────────────────────────
require_once __DIR__ . '/../src/Modules/Assignment/Models/AssignmentModel.php';
require_once __DIR__ . '/../src/Modules/Assignment/Services/AssignmentService.php';
require_once __DIR__ . '/../src/Modules/Assignment/Controllers/AssignmentController.php';

// ── M2 · B7 : Listes de choix en cascade ─────────────────────────────────────
require_once __DIR__ . '/../src/Modules/ChoiceList/Models/ChoiceListModel.php';
require_once __DIR__ . '/../src/Modules/ChoiceList/Services/ChoiceListService.php';
require_once __DIR__ . '/../src/Modules/ChoiceList/Controllers/ChoiceListController.php';

// ── M2 · B8 : Groupes de questions répétables ────────────────────────────────
require_once __DIR__ . '/../src/Modules/RepeatGroup/Models/RepeatGroupModel.php';
require_once __DIR__ . '/../src/Modules/RepeatGroup/Services/RepeatGroupService.php';
require_once __DIR__ . '/../src/Modules/RepeatGroup/Controllers/RepeatGroupController.php';

// ── M4 : Agents de terrain scopés à une enquête ──────────────────────────────
require_once __DIR__ . '/../src/Modules/Agent/Models/FormAgentModel.php';
require_once __DIR__ . '/../src/Modules/Agent/Services/AgentService.php';
require_once __DIR__ . '/../src/Modules/Agent/Controllers/AgentController.php';

// LogicEngine + Section (TECH-002 + US-014)
require_once __DIR__ . '/../src/Modules/LogicEngine/Entities/Condition.php';
require_once __DIR__ . '/../src/Modules/LogicEngine/Models/ConditionModel.php';
require_once __DIR__ . '/../src/Modules/LogicEngine/Services/LogicEngineService.php';
require_once __DIR__ . '/../src/Modules/Section/Models/SectionModel.php';
require_once __DIR__ . '/../src/Modules/Section/Services/SectionService.php';
require_once __DIR__ . '/../src/Modules/Section/Controllers/SectionController.php';

// ── US-021 : Google Sheets ────────────────────────────────────────────────────
require_once __DIR__ . '/../src/Modules/GoogleSheets/Models/GoogleSheetsModel.php';
require_once __DIR__ . '/../src/Modules/GoogleSheets/Services/GoogleSheetsService.php';
require_once __DIR__ . '/../src/Modules/GoogleSheets/Controllers/GoogleSheetsController.php';

// ── Modèles de formulaires prêts à l'emploi ──────────────────────────────────
require_once __DIR__ . '/../src/Modules/Template/Data/TemplateCatalog.php';
require_once __DIR__ . '/../src/Modules/Template/Services/TemplateService.php';
require_once __DIR__ . '/../src/Modules/Template/Controllers/TemplateController.php';

// Router
require_once __DIR__ . '/../src/Core/Router.php';