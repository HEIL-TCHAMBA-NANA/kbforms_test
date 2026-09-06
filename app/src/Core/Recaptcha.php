<?php
namespace Core;

/**
 * Vérification Google reCAPTCHA v2 (case « Je ne suis pas un robot »).
 *
 * Config : app/config/recaptcha.php  → ['site_key' => ..., 'secret_key' => ...]
 * (ou variables d'env RECAPTCHA_SITE_KEY / RECAPTCHA_SECRET_KEY).
 *
 * Si non configuré → verify() renvoie toujours true (fonctionnalité inactive).
 */
final class Recaptcha
{
    private const VERIFY_ENDPOINT = 'https://www.google.com/recaptcha/api/siteverify';

    /** Dernière réponse brute de siteverify (diagnostic, si 'debug' => true). */
    public static ?array $lastResponse = null;

    public static function debugEnabled(): bool
    {
        return (bool) (self::config()['debug'] ?? false);
    }

    public static function config(): array
    {
        static $cfg = null;
        if ($cfg !== null) {
            return $cfg;
        }
        $cfg = ['site_key' => '', 'secret_key' => ''];
        $path = __DIR__ . '/../../config/recaptcha.php';
        if (is_file($path) && is_array($c = include $path)) {
            $cfg = array_merge($cfg, $c);
        }
        foreach (['RECAPTCHA_SITE_KEY' => 'site_key', 'RECAPTCHA_SECRET_KEY' => 'secret_key'] as $env => $k) {
            $v = getenv($env);
            if ($v !== false && $v !== '') {
                $cfg[$k] = $v;
            }
        }
        return $cfg;
    }

    public static function siteKey(): string
    {
        return (string) self::config()['site_key'];
    }

    public static function isEnabled(): bool
    {
        $c = self::config();
        return $c['site_key'] !== '' && $c['secret_key'] !== '';
    }

    /**
     * @param string|null $token  valeur de g-recaptcha-response envoyée par le client
     * @param string|null $ip     IP du client (optionnel)
     */
    public static function verify(?string $token, ?string $ip = null): bool
    {
        if (!self::isEnabled()) {
            return true; // CAPTCHA désactivé
        }
        if ($token === null || $token === '') {
            return false;
        }

        $ch = curl_init(self::VERIFY_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POSTFIELDS     => http_build_query(array_filter([
                'secret'   => self::config()['secret_key'],
                'response' => $token,
                'remoteip' => $ip,
            ])),
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        // Panne réseau vers Google → on laisse passer (fail-open) pour ne pas
        // bloquer toutes les connexions ; une réponse valide "success:false"
        // reste, elle, refusée (fail-closed).
        if ($raw === false || $code !== 200) {
            error_log('[Recaptcha] verify transport error: ' . ($cerr ?: "HTTP $code"));
            return true;
        }

        $data = json_decode($raw, true);
        self::$lastResponse = is_array($data) ? $data : ['_raw' => $raw];
        if (self::debugEnabled()) {
            error_log('[Recaptcha] siteverify -> ' . json_encode(self::$lastResponse));
        }
        return is_array($data) && ($data['success'] ?? false) === true;
    }
}
