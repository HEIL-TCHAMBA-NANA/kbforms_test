<?php
namespace Modules\Identity\Services;

require_once __DIR__ . '/../Libraries/php-jwt/autoload.php';

use Modules\Identity\Entities\User;
use Modules\Identity\Models\UserModel;
use Core\AppUrl;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

/**
 * « Continuer avec Google » — OAuth 2.0 Authorization Code flow + vérification
 * de l'ID token (OpenID Connect) contre les clés publiques de Google.
 *
 * Config : app/config/google.php  (client_id, client_secret, redirect_uri?).
 * Aucune dépendance : cURL + le php-jwt déjà vendu pour l'auth maison.
 */
final class GoogleAuthService
{
    private const AUTH_ENDPOINT  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const CERTS_ENDPOINT = 'https://www.googleapis.com/oauth2/v3/certs';
    private const ISSUERS        = ['https://accounts.google.com', 'accounts.google.com'];

    private array $cfg;
    private UserModel $users;
    private AuthService $auth;

    public function __construct()
    {
        $this->cfg   = self::loadConfig();
        $this->users = new UserModel();
        $this->auth  = new AuthService();
    }

    public static function loadConfig(): array
    {
        $defaults = [
            'client_id'            => '',
            'client_secret'        => '',
            'redirect_uri'         => null,
            'default_account_type' => 'individual',
        ];
        $path = __DIR__ . '/../../../../config/google.php';
        if (is_file($path)) {
            $c = include $path;
            if (is_array($c)) $defaults = array_merge($defaults, $c);
        }
        foreach (['GOOGLE_CLIENT_ID' => 'client_id', 'GOOGLE_CLIENT_SECRET' => 'client_secret',
                  'GOOGLE_REDIRECT_URI' => 'redirect_uri'] as $env => $key) {
            $v = getenv($env);
            if ($v !== false && $v !== '') $defaults[$key] = $v;
        }
        return $defaults;
    }

    public function isEnabled(): bool
    {
        $id = (string) $this->cfg['client_id'];
        $secret = (string) $this->cfg['client_secret'];
        return $id !== '' && $secret !== ''
            && !str_starts_with($id, 'xxxx')
            && str_contains($id, '.apps.googleusercontent.com');
    }

    /**
     * URI de redirection : DOIT correspondre EXACTEMENT à un URI enregistré
     * dans la console Google. Priorité :
     *   1. google.php['redirect_uri']            (override explicite)
     *   2. KBF_BASE_URL / app.php['base_url']    (déterministe en production)
     *   3. hôte réel de la requête               (dev : http://localhost/...)
     * On n'applique jamais la détection d'IP LAN ici (Google refuse les IP).
     */
    public function redirectUri(): string
    {
        if (!empty($this->cfg['redirect_uri'])) {
            return $this->cfg['redirect_uri'];
        }
        $override = getenv('KBF_BASE_URL') ?: (AppUrl::configuredBaseUrl() ?: null);
        if ($override) {
            return rtrim($override, '/') . '/auth/google/callback';
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . '/auth/google/callback';
    }

    /** URL de consentement Google vers laquelle rediriger l'utilisateur. */
    public function authUrl(string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id'     => $this->cfg['client_id'],
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $state,
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * Échange le code contre un ID token, le vérifie, puis connecte ou crée
     * l'utilisateur. Renvoie ['success'=>bool, 'token'=>?string, 'error'=>?string].
     */
    public function handleCallback(string $code): array
    {
        if (!$this->isEnabled()) {
            return ['success' => false, 'error' => 'Connexion Google non configurée.'];
        }

        $tok = $this->httpPost(self::TOKEN_ENDPOINT, [
            'code'          => $code,
            'client_id'     => $this->cfg['client_id'],
            'client_secret' => $this->cfg['client_secret'],
            'redirect_uri'  => $this->redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (!$tok['ok'] || empty($tok['data']['id_token'])) {
            error_log('[GoogleAuth] token exchange failed: ' . json_encode($tok['data'] ?? $tok['error']));
            return ['success' => false, 'error' => "Échec de l'échange avec Google."];
        }

        $claims = $this->verifyIdToken($tok['data']['id_token']);
        if ($claims === null) {
            return ['success' => false, 'error' => "Jeton Google invalide."];
        }

        $email = strtolower(trim($claims['email'] ?? ''));
        if ($email === '' || ($claims['email_verified'] ?? false) !== true) {
            return ['success' => false, 'error' => "Email Google non vérifié."];
        }

        $user = $this->users->findByEmail($email);
        $isNew = false;
        if (!$user) {
            $this->createUser($email, $claims);
            $user = $this->users->findByEmail($email);
            $isNew = true;
            if (!$user) {
                return ['success' => false, 'error' => "Création du compte impossible."];
            }
        }

        return [
            'success' => true,
            'token'   => $this->auth->issueToken($user),
            'is_new'  => $isNew,
            'email'   => $email,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function verifyIdToken(string $idToken): ?array
    {
        $certs = $this->httpGet(self::CERTS_ENDPOINT);
        if (!$certs['ok'] || empty($certs['data']['keys'])) {
            error_log('[GoogleAuth] cannot fetch Google certs');
            return null;
        }
        try {
            JWT::$leeway = 60;
            $keys = JWK::parseKeySet($certs['data']);
            $decoded = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            error_log('[GoogleAuth] id_token verify failed: ' . $e->getMessage());
            return null;
        }
        if (($decoded['aud'] ?? null) !== $this->cfg['client_id']) return null;
        if (!in_array($decoded['iss'] ?? '', self::ISSUERS, true)) return null;

        return $decoded;
    }

    private function createUser(string $email, array $claims): void
    {
        $first = trim($claims['given_name'] ?? '');
        $last  = trim($claims['family_name'] ?? '');
        if ($first === '' && $last === '') {
            $name = trim($claims['name'] ?? (explode('@', $email)[0]));
            $parts = preg_split('/\s+/', $name, 2);
            $first = $parts[0] ?? $name;
            $last  = $parts[1] ?? $parts[0] ?? $name;
        } elseif ($last === '') {
            $last = $first;
        } elseif ($first === '') {
            $first = $last;
        }

        // Mot de passe inutilisable (colonne NOT NULL) : le compte se connecte via Google.
        $randomHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);

        $user = new User(
            null, $first, $last, $email, $randomHash,
            (string) $this->cfg['default_account_type'],
            null, null, null, null, null, null, null,
            date('Y-m-d H:i:s')
        );
        $this->users->create($user);
    }

    private function httpPost(string $url, array $form): array
    {
        return $this->request('POST', $url, http_build_query($form),
            ['Content-Type: application/x-www-form-urlencoded']);
    }

    private function httpGet(string $url): array
    {
        return $this->request('GET', $url, null, ['Accept: application/json']);
    }

    private function request(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['ok' => false, 'error' => $err ?: 'curl error', 'data' => null];
        }
        $data = json_decode($raw, true);
        return ['ok' => $code >= 200 && $code < 300, 'error' => $err, 'data' => $data];
    }
}
