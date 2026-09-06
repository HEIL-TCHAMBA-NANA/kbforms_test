<?php
namespace Core;

/**
 * Reconnaissance de l'application mobile officielle KBForms via un secret
 * partagé.
 *
 * Config  : app/config/mobile.php  → ['client_secret' => '...']
 *           (ou variable d'env KBF_MOBILE_CLIENT_SECRET)
 * En-tête : X-KBF-Client-Secret: <secret>
 *
 * Usage : contourner le CAPTCHA sur /login et /register — le widget
 * reCAPTCHA v2 « case à cocher » n'a pas sa place dans une app native.
 * Si aucun secret n'est configuré, isTrusted() renvoie toujours false
 * (fonctionnalité inactive, le CAPTCHA reste requis).
 */
final class MobileClient
{
    public static function secret(): string
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $cached = '';
        $path = __DIR__ . '/../../config/mobile.php';
        if (is_file($path) && is_array($c = include $path)) {
            $cached = (string) ($c['client_secret'] ?? '');
        }
        $env = getenv('KBF_MOBILE_CLIENT_SECRET');
        if ($env !== false && $env !== '') {
            $cached = $env;
        }
        return $cached;
    }

    /** True si la requête HTTP courante porte le bon secret client mobile. */
    public static function isTrusted(): bool
    {
        $expected = self::secret();
        if ($expected === '') {
            return false;
        }
        $got = $_SERVER['HTTP_X_KBF_CLIENT_SECRET'] ?? '';
        return is_string($got) && $got !== '' && hash_equals($expected, $got);
    }
}
