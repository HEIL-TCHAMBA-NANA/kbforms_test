<?php
namespace Core;

/**
 * Construit l'URL de base publique de l'application (schéma + hôte + port),
 * utilisée pour les liens envoyés hors de la machine : invitations par email,
 * liens de partage /f/{token}, etc.
 *
 * Ordre de priorité :
 *   1. Override explicite  → env KBF_BASE_URL  ou  config/app.php['base_url']
 *      (ex. "https://forms.mondomaine.com" ou un tunnel ngrok)
 *   2. Hôte de la requête s'il n'est pas une adresse de bouclage
 *      (ex. un téléphone qui ouvre http://192.168.1.32 → on garde tel quel)
 *   3. Sinon (le créateur navigue sur localhost) → IP LAN détectée
 *      automatiquement, avec le port de la requête.
 *
 * L'IP LAN est recalculée à chaque requête : si on change de réseau Wi-Fi,
 * les liens suivent sans configuration.
 */
final class AppUrl
{
    private static ?string $cachedLanIp = null;

    /**
     * URL de base forcée explicitement (env KBF_BASE_URL ou config/app.php),
     * ou null si aucune. Sert quand on a besoin d'une valeur déterministe
     * sans jamais tomber sur l'IP LAN (ex. redirect_uri OAuth).
     */
    public static function configuredBaseUrl(): ?string
    {
        $override = getenv('KBF_BASE_URL') ?: (self::config()['base_url'] ?? null);
        return $override ? rtrim($override, '/') : null;
    }

    public static function base(): string
    {
        // 1. Override explicite
        $override = self::configuredBaseUrl();
        if ($override) {
            return $override;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? '';

        // Découper hôte / port
        $port = null;
        if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {        // [IPv6]:port
            $hostname = '[' . $m[1] . ']'; $port = $m[2];
        } elseif (substr_count($host, ':') === 1) {
            [$hostname, $port] = explode(':', $host, 2);
        } else {
            $hostname = $host;
        }

        // 2. Hôte de la requête déjà exploitable ?
        if ($hostname !== '' && !self::isLoopback($hostname)) {
            return $scheme . '://' . $host;
        }

        // 3. Repli sur l'IP LAN
        $lan = self::lanIp();
        if ($lan === null) {
            return $scheme . '://' . ($host ?: 'localhost');
        }
        return $scheme . '://' . $lan . ($port ? ':' . $port : '');
    }

    /**
     * Remplace l'hôte d'une URL déjà construite par l'hôte courant de base(),
     * en conservant le chemin (utile pour rafraîchir un share_link stocké avec
     * un ancien "localhost" ou une IP périmée). Le token du lien est préservé.
     */
    public static function rewriteHost(?string $url): ?string
    {
        if (!$url) {
            return $url;
        }
        $path  = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        return self::base() . $path . ($query ? '?' . $query : '');
    }

    /** IP LAN de l'interface de sortie par défaut, ou null. */
    public static function lanIp(): ?string
    {
        if (self::$cachedLanIp !== null) {
            return self::$cachedLanIp ?: null;
        }
        self::$cachedLanIp = ''; // marque "déjà tenté"

        // Astuce : "connecter" un socket UDP vers une IP publique ne fait
        // transiter aucun paquet mais fixe l'adresse locale de sortie.
        foreach (['udp://8.8.8.8:53', 'udp://1.1.1.1:53'] as $probe) {
            $s = @stream_socket_client($probe, $e, $es, 1);
            if ($s) {
                $name = @stream_socket_get_name($s, false);
                @fclose($s);
                if ($name) {
                    $ip = self::stripPort($name);
                    if (self::isUsableLan($ip)) {
                        return self::$cachedLanIp = $ip;
                    }
                }
            }
        }

        // Repli : hostname -I (liste d'adresses), on prend la première LAN
        // "vraie" en écartant docker/bridge (172.16/12) si une 192.168/10 existe.
        if (function_exists('shell_exec')) {
            $out = @shell_exec('hostname -I 2>/dev/null');
            if (is_string($out)) {
                $ips = array_values(array_filter(preg_split('/\s+/', trim($out))));
                $preferred = array_filter($ips, static fn ($ip) =>
                    self::isUsableLan($ip) && (str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')));
                $any = array_filter($ips, [self::class, 'isUsableLan']);
                $pick = $preferred[array_key_first($preferred)] ?? ($any[array_key_first($any)] ?? null);
                if ($pick) {
                    return self::$cachedLanIp = $pick;
                }
            }
        }

        // Dernier repli
        $h = @gethostbyname(@gethostname() ?: '');
        if ($h && self::isUsableLan($h)) {
            return self::$cachedLanIp = $h;
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private static function config(): array
    {
        static $cfg = null;
        if ($cfg === null) {
            $path = __DIR__ . '/../../config/app.php';
            $cfg = is_file($path) && is_array($c = include $path) ? $c : [];
        }
        return $cfg;
    }

    private static function stripPort(string $name): string
    {
        if (preg_match('/^\[(.+)\]:\d+$/', $name, $m)) return $m[1];  // IPv6
        $pos = strrpos($name, ':');
        return $pos !== false ? substr($name, 0, $pos) : $name;
    }

    private static function isLoopback(string $host): bool
    {
        $host = trim($host, '[]');
        return $host === 'localhost'
            || $host === '127.0.0.1'
            || str_starts_with($host, '127.')
            || $host === '::1'
            || $host === '0.0.0.0';
    }

    private static function isUsableLan(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        if (self::isLoopback($ip)) {
            return false;
        }
        // Écarter le bridge Docker par défaut
        if (str_starts_with($ip, '172.17.') || str_starts_with($ip, '172.18.')) {
            return false;
        }
        return true;
    }
}
