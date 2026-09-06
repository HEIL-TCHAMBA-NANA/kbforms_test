<?php
namespace Core;

use PDO;
use PDOException;

class Database {
    private static ?PDO $connection = null;

    public static function getConnection(): PDO {
        if (self::$connection !== null) {
            return self::$connection;
        }

        // Ordre de priorité des paramètres de connexion :
        //   1. Variables d'environnement (KBF_DB_HOST, KBF_DB_NAME, …)
        //   2. app/config/database.php  (retourne un tableau — NON versionné)
        //   3. Valeurs par défaut ci-dessous (poste de dev XAMPP)
        // Permet de déployer (hébergeur mutualisé, conteneur…) sans éditer
        // ce fichier source.
        $cfg = [
            'host'     => '127.0.0.1',
            'port'     => null,   // null = port par défaut (3306)
            'dbname'   => 'kbforms',
            'username' => 'kbforms',
            'password' => 'K&Bgroup237*',
            'charset'  => 'utf8mb4',
            'socket'   => null,   // si renseigné, prioritaire sur host
        ];

        $path = __DIR__ . '/../../config/database.php';
        if (is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $cfg = array_merge($cfg, $loaded);
            }
        }

        foreach ([
            'KBF_DB_HOST'    => 'host',
            'KBF_DB_PORT'    => 'port',
            'KBF_DB_NAME'    => 'dbname',
            'KBF_DB_USER'    => 'username',
            'KBF_DB_PASS'    => 'password',
            'KBF_DB_SOCKET'  => 'socket',
            'KBF_DB_CHARSET' => 'charset',
        ] as $env => $k) {
            $v = getenv($env);
            if ($v !== false && $v !== '') {
                $cfg[$k] = $v;
            }
        }

        if (!empty($cfg['socket'])) {
            $dsn = "mysql:unix_socket={$cfg['socket']};dbname={$cfg['dbname']};charset={$cfg['charset']}";
        } else {
            $port = !empty($cfg['port']) ? ";port=" . (int) $cfg['port'] : '';
            $dsn  = "mysql:host={$cfg['host']}{$port};dbname={$cfg['dbname']};charset={$cfg['charset']}";
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        // TLS vers un MySQL managé (Aiven, TiDB Cloud…). Activé si KBF_DB_SSL
        // est vrai, ou si KBF_DB_SSL_CA pointe vers un fichier CA.
        //   KBF_DB_SSL_CA        → chemin du certificat CA (vérification complète)
        //   KBF_DB_SSL_NO_VERIFY → '1' pour ne pas vérifier le cert serveur
        //                          (dépannage / pilote — préférer un CA réel)
        $sslCa       = getenv('KBF_DB_SSL_CA') ?: '';
        $sslOn       = $sslCa !== '' || filter_var(getenv('KBF_DB_SSL') ?: '', FILTER_VALIDATE_BOOL);
        $sslNoVerify = filter_var(getenv('KBF_DB_SSL_NO_VERIFY') ?: '', FILTER_VALIDATE_BOOL);
        if ($sslOn && defined('PDO::MYSQL_ATTR_SSL_CA')) {
            if ($sslCa !== '' && is_file($sslCa)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            }
            if ($sslNoVerify && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
                // Sans CA fourni, forcer quand même la négociation TLS.
                if (!isset($options[PDO::MYSQL_ATTR_SSL_CA])) {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = '/etc/ssl/certs/ca-certificates.crt';
                }
            }
        }

        try {
            self::$connection = new PDO($dsn, $cfg['username'], $cfg['password'], $options);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode([
                "error" => "Database connection failed",
                "message" => $e->getMessage()
            ]));
        }

        return self::$connection;
    }
}
