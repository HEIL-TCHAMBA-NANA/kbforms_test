<?php
namespace Modules\Mail;

/**
 * Client SMTP minimal, sans dépendance (le projet n'utilise pas Composer).
 *
 * Gère :
 *   - connexion claire, implicite SSL (port 465) ou STARTTLS (port 587)
 *   - EHLO / HELO
 *   - AUTH LOGIN et AUTH PLAIN
 *   - un destinataire (ou plusieurs), corps texte + HTML optionnel (multipart)
 *
 * Lève une \RuntimeException à la moindre erreur de protocole.
 */
final class SmtpClient
{
    /** @var resource */
    private $sock;
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg + [
            'host'       => 'localhost',
            'port'       => 25,
            'encryption' => '',      // '', 'ssl', 'tls'
            'username'   => '',
            'password'   => '',
            'timeout'    => 15,
            'from_email' => 'no-reply@localhost',
            'from_name'  => 'KBForms',
            'debug'      => false,
        ];
    }

    /**
     * @param string       $to      email destinataire
     * @param string       $subject sujet (UTF-8)
     * @param string       $text    corps texte brut (UTF-8)
     * @param string|null  $html    corps HTML optionnel (UTF-8)
     */
    public function send(string $to, string $subject, string $text, ?string $html = null): void
    {
        $enc  = strtolower((string) $this->cfg['encryption']);
        $host = $this->cfg['host'];
        $port = (int) $this->cfg['port'];
        $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;

        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);

        $errno = 0; $errstr = '';
        $this->sock = @stream_socket_client(
            $remote, $errno, $errstr, (float) $this->cfg['timeout'],
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->sock) {
            throw new \RuntimeException("Connexion SMTP impossible ($remote) : $errstr");
        }
        stream_set_timeout($this->sock, (int) $this->cfg['timeout']);

        $this->expect(220);

        $ehloName = $this->ehloName();
        $this->cmd("EHLO $ehloName", 250);

        if ($enc === 'tls') {
            $this->cmd('STARTTLS', 220);
            $ok = @stream_socket_enable_crypto(
                $this->sock, true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($ok !== true) {
                throw new \RuntimeException('Échec de la négociation STARTTLS');
            }
            $this->cmd("EHLO $ehloName", 250);
        }

        if ($this->cfg['username'] !== '') {
            $this->authenticate();
        }

        $from = $this->cfg['from_email'];
        $this->cmd("MAIL FROM:<{$from}>", 250);
        foreach ((array) $to as $rcpt) {
            $this->cmd("RCPT TO:<{$rcpt}>", [250, 251]);
        }

        $this->cmd('DATA', 354);
        $this->writeLine($this->buildMessage((array) $to, $subject, $text, $html));
        $this->writeLine('.');
        $this->expect(250);

        $this->cmd('QUIT', 221);
        fclose($this->sock);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authenticate(): void
    {
        $u = $this->cfg['username'];
        $p = $this->cfg['password'];

        // AUTH LOGIN (le plus largement supporté)
        $resp = $this->cmd('AUTH LOGIN', 334);
        $this->cmd(base64_encode($u), 334);
        $this->cmd(base64_encode($p), 235);
    }

    private function ehloName(): string
    {
        $name = gethostname() ?: 'localhost';
        // RFC : un nom de domaine ; à défaut, un littéral d'adresse.
        return preg_match('/^[A-Za-z0-9.\-]+$/', $name) ? $name : '[127.0.0.1]';
    }

    private function buildMessage(array $to, string $subject, string $text, ?string $html): string
    {
        $fromName = $this->encodeHeader($this->cfg['from_name']);
        $headers = [
            'From: ' . $fromName . ' <' . $this->cfg['from_email'] . '>',
            'To: ' . implode(', ', $to),
            'Subject: ' . $this->encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . ($this->cfg['host'] ?: 'localhost') . '>',
            'MIME-Version: 1.0',
        ];

        if ($html === null || $html === '') {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = chunk_split(base64_encode($text));
        } else {
            $boundary = 'kbf_' . bin2hex(random_bytes(12));
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
            $body =
                "--$boundary\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode($text)) . "\r\n" .
                "--$boundary\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: base64\r\n\r\n" .
                chunk_split(base64_encode($html)) . "\r\n" .
                "--$boundary--\r\n";
        }

        // Protection « dot-stuffing » : une ligne composée uniquement de "."
        $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
        return preg_replace('/^\./m', '..', $message);
    }

    private function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s)
            ? '=?UTF-8?B?' . base64_encode($s) . '?='
            : $s;
    }

    /** Envoie une commande puis vérifie le code de réponse attendu. */
    private function cmd(string $line, $expected): string
    {
        $this->writeLine($line);
        return $this->expect($expected);
    }

    private function writeLine(string $line): void
    {
        if ($this->cfg['debug']) error_log('[SMTP >] ' . $line);
        if (fwrite($this->sock, $line . "\r\n") === false) {
            throw new \RuntimeException('Écriture SMTP impossible');
        }
    }

    /** Lit une réponse multi-lignes et compare au(x) code(s) attendu(s). */
    private function expect($expected): string
    {
        $expected = (array) $expected;
        $data = '';
        do {
            $line = fgets($this->sock, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new \RuntimeException(
                    $meta['timed_out'] ? 'Timeout SMTP' : 'Connexion SMTP fermée par le serveur'
                );
            }
            if ($this->cfg['debug']) error_log('[SMTP <] ' . rtrim($line));
            $data .= $line;
            $more = isset($line[3]) && $line[3] === '-';
        } while ($more);

        $code = (int) substr($data, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('Réponse SMTP inattendue : ' . trim($data));
        }
        return $data;
    }
}
