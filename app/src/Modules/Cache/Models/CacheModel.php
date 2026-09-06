<?php
namespace Modules\Cache\Models;

use Core\Database;
use PDO;

class CacheModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Retourne le payload JSON si le cache est valide, null sinon.
     */
    public function get(string $token): ?string
    {
        $stmt = $this->db->prepare("
            SELECT payload FROM form_cache
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row['payload'] : null;
    }

    /**
     * Insère ou remplace une entrée dans le cache.
     *
     * @param int $ttlSeconds  Durée de vie en secondes (défaut 5 minutes).
     */
    public function set(string $token, string $payload, int $ttlSeconds = 300): void
    {
        $stmt = $this->db->prepare("
            REPLACE INTO form_cache (token, payload, cached_at, expires_at)
            VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))
        ");
        $stmt->execute([$token, $payload, $ttlSeconds]);
    }

    /**
     * Invalide le cache d'un token (après modification du formulaire).
     */
    public function invalidate(string $token): void
    {
        $stmt = $this->db->prepare("DELETE FROM form_cache WHERE token = ?");
        $stmt->execute([$token]);
    }

    /**
     * Purge toutes les entrées expirées.
     */
    public function purgeExpired(): int
    {
        $stmt = $this->db->prepare("DELETE FROM form_cache WHERE expires_at <= NOW()");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
