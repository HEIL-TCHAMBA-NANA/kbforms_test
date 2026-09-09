<?php
namespace Modules\Identity\Controllers;

use Modules\Identity\Services\AuthService;
use Modules\Identity\Services\GoogleAuthService;
use Core\AuthMiddleware;

class AuthController {
    private AuthService $service;

    public function __construct() {
        $this->service = new AuthService();
    }

    // ── GET /auth/config — capacités d'auth exposées au frontend ─────────────
    public function authConfig() {
        header('Content-Type: application/json');
        echo json_encode([
            'google'             => (new GoogleAuthService())->isEnabled(),
            'recaptcha_site_key' => \Core\Recaptcha::isEnabled() ? \Core\Recaptcha::siteKey() : null,
            // Lien de téléchargement de l'app mobile (APK) — affiché dans la
            // sidebar / la page /download quand il est défini. Priorité :
            //   1. fichier livré dans l'image  app/public/downloads/kbforms.apk
            //   2. variable d'env KBF_MOBILE_APK_URL (ex. release GitHub)
            'mobile_apk_url'     => $this->mobileApkUrl(),
        ]);
    }

    /** URL de l'APK mobile, ou null si aucune distribution n'est configurée. */
    private function mobileApkUrl(): ?string {
        if (is_file(__DIR__ . '/../../../../public/downloads/kbforms.apk')) {
            return '/downloads/kbforms.apk';
        }
        $url = trim((string) (getenv('KBF_MOBILE_APK_URL') ?: ''));
        return $url !== '' ? $url : null;
    }

    /** Refuse la requête si le CAPTCHA est actif et le jeton absent/invalide. */
    private function checkCaptcha(array $data): bool {
        // App mobile officielle (secret partagé) → pas de CAPTCHA.
        if (\Core\MobileClient::isTrusted()) {
            return true;
        }
        $token = $data['recaptcha_token'] ?? $data['g-recaptcha-response'] ?? null;
        if (\Core\Recaptcha::verify($token, $_SERVER['REMOTE_ADDR'] ?? null)) {
            return true;
        }
        http_response_code(400);
        $out = ["success" => false, "error" => "Vérification anti-robot échouée. Cochez la case et réessayez."];
        if (\Core\Recaptcha::debugEnabled()) {
            $out['debug'] = [
                'token_present'   => $token !== null && $token !== '',
                'token_len'       => is_string($token) ? strlen($token) : 0,
                'siteverify'      => \Core\Recaptcha::$lastResponse,
            ];
        }
        echo json_encode($out);
        return false;
    }

    /** Chemin interne sûr (commence par un seul "/") ou null. */
    private function safeNext($value): ?string {
        $value = is_string($value) ? $value : '';
        return ($value !== '' && $value[0] === '/' && substr($value, 0, 2) !== '//') ? $value : null;
    }

    // ── GET /auth/google — démarre le flux OAuth ────────────────────────────
    public function googleStart() {
        $g = new GoogleAuthService();
        if (!$g->isEnabled()) {
            header('Location: /login?error=google_disabled');
            return;
        }
        $state = bin2hex(random_bytes(16));
        $opts  = ['expires' => time() + 600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax'];
        setcookie('kbf_gstate', $state, $opts);

        // Mémoriser la destination post-connexion (ex. page d'invitation) le
        // temps de l'aller-retour vers Google.
        $next = $this->safeNext($_GET['next'] ?? '');
        setcookie('kbf_gnext', $next ?? '', $next
            ? $opts
            : ['expires' => time() - 3600, 'path' => '/']);

        header('Location: ' . $g->authUrl($state));
    }

    // ── GET /auth/google/callback — retour de Google ────────────────────────
    public function googleCallback() {
        $err   = $_GET['error']  ?? null;
        $code  = $_GET['code']   ?? null;
        $state = $_GET['state']  ?? null;
        $cookieState = $_COOKIE['kbf_gstate'] ?? null;
        $next = $this->safeNext($_COOKIE['kbf_gnext'] ?? '');

        // Consommer les cookies
        setcookie('kbf_gstate', '', ['expires' => time() - 3600, 'path' => '/']);
        setcookie('kbf_gnext', '', ['expires' => time() - 3600, 'path' => '/']);

        if ($err) { header('Location: /login?error=google_denied'); return; }
        if (!$code || !$state || !$cookieState || !hash_equals($cookieState, $state)) {
            header('Location: /login?error=google_state');
            return;
        }

        $res = (new GoogleAuthService())->handleCallback($code);
        if (!$res['success']) {
            header('Location: /login?error=google_failed');
            return;
        }

        // Remettre le JWT au navigateur (auth stockée en localStorage côté client),
        // puis rediriger vers la destination mémorisée (page d'invitation…) ou le dashboard.
        $dest = json_encode($next ?? '/dashboard');
        $token = json_encode($res['token']);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Connexion…</title>'
           . '<script>try{localStorage.setItem("kbf_token",' . $token . ');'
           . 'localStorage.removeItem("kbf_user");}catch(e){}'
           . 'location.replace(' . $dest . ');</script>'
           . '<p style="font-family:system-ui;padding:2rem">Connexion en cours…</p>';
    }

    // ── Register ──────────────────────────────────────────────────────────────
    public function register() {
        $data = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!$this->checkCaptcha($data)) return;
        $id = $this->service->register($data);

        // Connexion immédiate : évite un 2e appel /login (et un 2e CAPTCHA).
        $out = [
            "success" => true,
            "message" => "User registered successfully",
            "user_id" => $id,
        ];
        $user = $this->service->getUser((int) $id);
        if ($user) {
            // array + : les clés token/refresh_token/refresh_expires_at ne sont
            // pas encore présentes dans $out, donc elles sont bien ajoutées.
            $out += $this->service->issueTokens($user);
        } else {
            $out["token"] = null;
        }

        echo json_encode($out);
    }

    // ── Login ─────────────────────────────────────────────────────────────────
    public function login() {
        $data  = json_decode(file_get_contents("php://input"), true) ?? [];
        if (!$this->checkCaptcha($data)) return;
        $tokens = $this->service->loginTokens($data['email'] ?? '', $data['password'] ?? '');
        if ($tokens) {
            echo json_encode([
                "success" => true,
                "message" => "Login successful",
            ] + $tokens); // token, refresh_token, refresh_expires_at
        } else {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Invalid credentials"]);
        }
    }

    // ── POST /auth/forgot-password ───────────────────────────────────────────
    // Body : { "email": "..." }  → envoie un code de vérification par email.
    public function forgotPassword() {
        $data  = json_decode(file_get_contents("php://input"), true) ?? [];
        $email = trim((string)($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Email invalide"]);
            return;
        }
        echo json_encode($this->service->requestPasswordReset($email));
    }

    // ── POST /auth/reset-password ────────────────────────────────────────────
    // Body : { "email": "...", "code": "123456", "password": "nouveau" }
    public function resetPassword() {
        $data     = json_decode(file_get_contents("php://input"), true) ?? [];
        $email    = trim((string)($data['email']    ?? ''));
        $code     = trim((string)($data['code']     ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $code === '' || $password === '') {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "email, code et password sont requis"]);
            return;
        }

        $result = $this->service->resetPassword($email, $code, $password);
        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
    }

    // ── POST /auth/refresh ────────────────────────────────────────────────────
    // Chemin principal (mobile hors-ligne) : body { "refresh_token": "..." }
    //   → { token, refresh_token, refresh_expires_at }  (rotation à usage unique).
    // Rétro-compat : à défaut, un JWT d'accès encore valide en
    //   Authorization: Bearer <jwt>  → { token }.
    public function refresh() {
        $data         = json_decode(file_get_contents("php://input"), true) ?? [];
        $refreshToken = trim((string)($data['refresh_token'] ?? ''));

        if ($refreshToken !== '') {
            $pair = $this->service->redeemRefreshToken($refreshToken);
            if ($pair) {
                echo json_encode(["success" => true] + $pair);
                return;
            }
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Refresh token invalide ou expiré, veuillez vous reconnecter"]);
            return;
        }

        // Rétro-compat : Bearer encore valide
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $token = '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
            $token = $m[1];
        }
        if (!$token) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "refresh_token requis"]);
            return;
        }

        $newToken = $this->service->refresh($token);
        if ($newToken) {
            echo json_encode(["success" => true, "token" => $newToken]);
        } else {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Session expirée, veuillez vous reconnecter"]);
        }
    }

    // ── GET /users/{id} ───────────────────────────────────────────────────────
    public function getUser($id) {
        $id = (int)$id;
        AuthMiddleware::requireOwnership($id);

        $user = $this->service->getUser($id);
        if (!$user) {
            http_response_code(404);
            echo json_encode(["success" => false, "error" => "User not found"]);
            return;
        }
        echo json_encode(["success" => true, "user" => $user]);
    }

    // ── PUT /users/{id} ───────────────────────────────────────────────────────
    public function updateUser($id) {
        $id   = (int)$id;
        $data = json_decode(file_get_contents("php://input"), true) ?? [];

        AuthMiddleware::requireOwnership($id);

        // Le payload contient-il des champs de profil ? (sinon : changement de
        // mot de passe seul → on ne passe pas par updateUser qui exige nom/prénom)
        $profileKeys = ['first_name', 'last_name', 'name', 'organization', 'industry',
                        'company_size', 'country', 'phone', 'website', 'job_title', 'avatar'];
        $wantsProfile = (bool) array_intersect($profileKeys, array_keys($data));

        if ($wantsProfile) {
            $result = $this->service->updateUser($id, $data);
            if (!$result['success']) {
                http_response_code(400);
                echo json_encode($result);
                return;
            }
        }

        $pwdResult = $this->service->changePassword($id, $data);
        if ($pwdResult !== null && !$pwdResult['success']) {
            http_response_code(400);
            echo json_encode($pwdResult);
            return;
        }

        if (!$wantsProfile && $pwdResult === null) {
            http_response_code(400);
            echo json_encode(["success" => false, "error" => "Aucun champ à mettre à jour"]);
            return;
        }

        echo json_encode([
            "success"          => true,
            "message"          => "User updated successfully",
            "password_changed" => $pwdResult !== null && $pwdResult['success'],
        ]);
    }

    // ── DELETE /users/{id} ────────────────────────────────────────────────────
    public function deleteUser($id) {
        $id = (int)$id;
        AuthMiddleware::requireOwnership($id);

        $result = $this->service->deleteUser($id);
        if (!$result['success']) {
            http_response_code(500);
        }
        echo json_encode($result);
    }
    // ── GET /users — liste pour gestion des rôles ────────────────────────────
    // Admin : tous les utilisateurs
    // Enterprise : membres de la même organisation
    public function listUsers() {
        // FIX: utiliser getUser() (stdClass) au lieu de getCurrentUser()
        $currentUser = \Core\AuthMiddleware::getUser();
        if (!$currentUser) {
            http_response_code(401);
            echo json_encode(["success" => false, "error" => "Non authentifié"]);
            return;
        }

        $model = new \Modules\Identity\Models\UserModel();

        // stdClass → accès par propriété (->), pas tableau ([])
        if ($currentUser->account_type === 'admin') {
            $users = $model->listAll((int)$currentUser->user_id);
        } elseif ($currentUser->account_type === 'enterprise') {
            $me = $model->findById((int)$currentUser->user_id);
            $org = $me['organization'] ?? null;
            if (!$org) {
                echo json_encode([]);
                return;
            }
            $users = $model->listByOrganization($org, (int)$currentUser->user_id);
        } else {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Accès refusé"]);
            return;
        }

        echo json_encode($users);
    }

}