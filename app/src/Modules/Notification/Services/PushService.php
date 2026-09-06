<?php
namespace Modules\Notification\Services;

use Modules\Notification\Models\DeviceTokenModel;

/**
 * Notifications push via Firebase Cloud Messaging (API HTTP v1).
 *
 * Auth : compte de service Firebase (app/config/fcm.php) → JWT RS256 →
 * jeton OAuth2 (scope firebase.messaging) → POST .../messages:send.
 * Même principe que GoogleSheetsService.
 *
 * Best-effort : aucune méthode ne lève d'exception. Sans configuration valide,
 * `isConfigured()` renvoie false et `notify*()` ne fait rien.
 */
class PushService
{
    private DeviceTokenModel $devices;
    private ?array $sa = null;
    private ?string $cachedToken = null;
    private int $cachedTokenExp = 0;

    private const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    public function __construct()
    {
        $this->devices = new DeviceTokenModel();
        $this->sa      = $this->loadServiceAccount();
    }

    public function isConfigured(): bool
    {
        return $this->sa !== null
            && !empty($this->sa['private_key'])
            && !empty($this->sa['client_email'])
            && !empty($this->sa['project_id']);
    }

    // ── Enregistrement d'appareil ──────────────────────────────────────────
    public function registerDevice(int $userId, string $token, string $platform): array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 512) {
            return ['success' => false, 'error' => 'Jeton FCM invalide.'];
        }
        $platform = in_array($platform, DeviceTokenModel::VALID_PLATFORMS, true) ? $platform : 'android';
        $ok = $this->devices->upsert($userId, $token, $platform);
        return $ok
            ? ['success' => true, 'registered' => true, 'push_enabled' => $this->isConfigured()]
            : ['success' => false, 'error' => "Échec de l'enregistrement du jeton."];
    }

    public function unregisterDevice(int $userId, string $token): array
    {
        $removed = $this->devices->deleteForUser($userId, trim($token));
        return ['success' => true, 'removed' => $removed];
    }

    // ── Déclencheur métier : nouvelle assignation ──────────────────────────
    public function notifyAssignment(int $toUserId, string $formTitle, ?string $note = null): void
    {
        $body = $note ? trim($note) : "Un formulaire vous a été assigné.";
        $this->sendToUser(
            $toUserId,
            "Nouvelle enquête assignée",
            mb_substr($formTitle . ($note ? " — " . $body : ''), 0, 240),
            ['type' => 'assignment', 'form_title' => $formTitle]
        );
    }

    // ── Envoi à tous les appareils d'un utilisateur (best-effort) ──────────
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        if (!$this->isConfigured()) {
            return;
        }
        $tokens = $this->devices->tokensForUser($userId);
        if (!$tokens) {
            return;
        }
        $access = $this->accessToken();
        if (!$access) {
            error_log('[PushService] jeton OAuth2 indisponible — envoi ignoré.');
            return;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $this->sa['project_id'] . '/messages:send';
        foreach ($tokens as $tok) {
            $payload = json_encode(['message' => [
                'token'        => $tok,
                'notification' => ['title' => $title, 'body' => $body],
                'data'         => array_map('strval', $data),
                'android'      => ['priority' => 'high'],
            ]]);
            $res = $this->httpPost($url, $payload, $access);

            // Jeton mort → on le purge pour ne pas réessayer indéfiniment.
            $status = $res['status'] ?? 0;
            $fcmErr = $res['data']['error']['status'] ?? '';
            if ($status === 404 || in_array($fcmErr, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
                $this->devices->deleteToken($tok);
                error_log("[PushService] jeton purgé ($fcmErr) pour user $userId");
            } elseif ($status < 200 || $status >= 300) {
                error_log("[PushService] echec envoi user $userId — HTTP $status " . substr(json_encode($res['data'] ?? []), 0, 200));
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    private function loadServiceAccount(): ?array
    {
        $cfgPath = __DIR__ . '/../../../../config/fcm.php';
        if (is_file($cfgPath)) {
            $cfg = include $cfgPath;
            if (is_array($cfg)) {
                if (!empty($cfg['service_account']) && is_array($cfg['service_account'])) {
                    return $cfg['service_account'];
                }
                if (!empty($cfg['service_account_json']) && is_file($cfg['service_account_json'])) {
                    return json_decode(file_get_contents($cfg['service_account_json']), true) ?: null;
                }
            }
        }
        $envPath = $_ENV['KBF_FCM_SERVICE_ACCOUNT_JSON'] ?? getenv('KBF_FCM_SERVICE_ACCOUNT_JSON') ?: null;
        if ($envPath && is_file($envPath)) {
            return json_decode(file_get_contents($envPath), true) ?: null;
        }
        return null;
    }

    private function accessToken(): ?string
    {
        if ($this->cachedToken && $this->cachedTokenExp > time() + 30) {
            return $this->cachedToken;
        }
        if (!$this->isConfigured()) {
            return null;
        }

        $now = time();
        $b64 = static fn($b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
        $header  = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $b64(json_encode([
            'iss'   => $this->sa['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URI,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));
        $sigInput = "$header.$payload";
        if (!openssl_sign($sigInput, $signature, $this->sa['private_key'], 'SHA256')) {
            error_log('[PushService] openssl_sign a échoué (clé privée invalide ?).');
            return null;
        }
        $jwt = $sigInput . '.' . $b64($signature);

        $res = $this->httpPost(self::TOKEN_URI, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]), null, 'application/x-www-form-urlencoded');

        $token = $res['data']['access_token'] ?? null;
        if ($token) {
            $this->cachedToken    = $token;
            $this->cachedTokenExp = $now + (int) ($res['data']['expires_in'] ?? 3600);
        }
        return $token;
    }

    private function httpPost(string $url, string $body, ?string $bearer, string $contentType = 'application/json'): array
    {
        $ch = curl_init($url);
        $headers = ["Content-Type: $contentType"];
        if ($bearer) {
            $headers[] = "Authorization: Bearer $bearer";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw    = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);

        if ($err) {
            error_log("[PushService] cURL: $err");
            return ['status' => 0, 'data' => [], 'error' => $err];
        }
        return ['status' => $status, 'data' => json_decode($raw, true) ?? []];
    }
}
