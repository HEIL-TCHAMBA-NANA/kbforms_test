<?php
namespace Modules\Mail;

/**
 * Point d'entrée unique pour l'envoi d'emails de l'application.
 *
 * Choisit le transport selon app/config/mail.php :
 *   driver = 'smtp' → SmtpClient (recommandé : marche partout, aucune install système)
 *   driver = 'mail' → fonction mail() de PHP (nécessite un MTA / sendmail sur la machine)
 *   driver = 'log'  → n'envoie rien, écrit seulement dans les logs (défaut sûr)
 *
 * Ne lève jamais d'exception : renvoie ['ok' => bool, 'transport' => string, 'error' => ?string].
 * Chaque tentative est journalisée dans app/logs/mail.log (+ /tmp en repli).
 */
final class MailService
{
    private array $cfg;

    public function __construct(?array $cfg = null)
    {
        $this->cfg = $cfg ?? self::loadConfig();
    }

    public static function loadConfig(): array
    {
        $defaults = [
            'driver'     => 'log',
            'host'       => '',
            'port'       => 587,
            'encryption' => 'tls',
            'username'   => '',
            'password'   => '',
            'from_email' => 'no-reply@kbforms.local',
            'from_name'  => 'KBForms',
            'timeout'    => 15,
            'debug'      => false,
        ];

        $path = __DIR__ . '/../../../config/mail.php';
        if (is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $defaults = array_merge($defaults, $loaded);
            }
        }

        // Surcharge par variables d'environnement (utile en conteneur / CI)
        foreach ([
            'MAIL_DRIVER' => 'driver', 'MAIL_HOST' => 'host', 'MAIL_PORT' => 'port',
            'MAIL_ENCRYPTION' => 'encryption', 'MAIL_USERNAME' => 'username',
            'MAIL_PASSWORD' => 'password', 'MAIL_FROM_EMAIL' => 'from_email',
            'MAIL_FROM_NAME' => 'from_name',
        ] as $env => $key) {
            $v = getenv($env);
            if ($v !== false && $v !== '') {
                $defaults[$key] = $key === 'port' ? (int) $v : $v;
            }
        }

        return $defaults;
    }

    /**
     * @return array{ok:bool, transport:string, error:?string}
     */
    public function send(string $to, string $subject, string $text, ?string $html = null): array
    {
        $driver = strtolower((string) $this->cfg['driver']);
        $result = ['ok' => false, 'transport' => $driver, 'error' => null];

        try {
            if ($driver === 'smtp') {
                if (empty($this->cfg['host'])) {
                    throw new \RuntimeException('driver=smtp mais aucun host configuré');
                }
                (new SmtpClient($this->cfg))->send($to, $subject, $text, $html);
                $result['ok'] = true;

            } elseif ($driver === 'mail') {
                $headers =
                    'From: ' . $this->cfg['from_name'] . ' <' . $this->cfg['from_email'] . ">\r\n" .
                    "MIME-Version: 1.0\r\n" .
                    'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
                    'Content-Transfer-Encoding: 8bit';
                $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
                $result['ok'] = (bool) $ok;
                if (!$ok) {
                    $result['error'] = 'La fonction mail() a échoué (MTA absent ?)';
                }

            } else { // 'log' ou inconnu
                $result['transport'] = 'log';
                $result['ok'] = true; // pas d'échec : c'est un no-op volontaire
            }
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        $this->log($to, $subject, $result, $text);
        return $result;
    }

    private function log(string $to, string $subject, array $result, string $text): void
    {
        $status = $result['ok'] ? 'OK' : 'ÉCHEC';
        $line = sprintf(
            "%s  [%s/%s]  -> %s  | %s%s\n",
            date('c'),
            $result['transport'],
            $status,
            $to,
            $subject,
            $result['error'] ? '  | erreur: ' . $result['error'] : ''
        );

        // En mode 'log' (ou en cas d'échec), on garde aussi le corps pour pouvoir
        // récupérer un code de vérification sans serveur mail.
        if ($result['transport'] === 'log' || !$result['ok']) {
            $line .= "----- corps -----\n" . $text . "\n-----------------\n";
        }

        foreach ([
            __DIR__ . '/../../../logs/mail.log',
            sys_get_temp_dir() . '/kbforms-mail.log',
        ] as $file) {
            if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) {
                break;
            }
        }
        error_log('[mail] ' . trim($line));
    }
}
