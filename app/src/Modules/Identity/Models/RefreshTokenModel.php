<?php
namespace Modules\Identity\Models;

use Core\Database;
use PDO;

/**
 * Refresh tokens longue durée pour l'app mobile (session hors-ligne).
 *
 * - Le jeton en clair n'est renvoyé qu'une fois, à l'émission.
 * - En base on ne stocke que son SHA-256 (`token_hash`).
 * - Rotation à usage unique : `redeem` révoque l'ancien et en émet un nouveau
 *   (géré par AuthService).
 */
class RefreshTokenModel {
    private PDO $db;

    /** Durée de vie par défaut d'un refresh token, en jours. */
    public const TTL_DAYS = 30;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    private function hash(string $rawToken): string {
        return hash('sha256', $rawToken);
    }

    /**
     * Émet un nouveau refresh token pour un utilisateur.
     * @return array{token:string, expires_at:string} jeton en clair + expiration ISO.
     */
    public function issue(int $userId, int $ttlDays = self::TTL_DAYS): array {
        $rawToken = bin2hex(random_bytes(32)); // 64 hex chars, opaque
        $stmt = $this->db->prepare("
            INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))
        ");
        $stmt->execute([$userId, $this->hash($rawToken), $ttlDays]);

        $expiresAt = $this->db
            ->query("SELECT expires_at FROM refresh_tokens WHERE id = " . (int)$this->db->lastInsertId())
            ->fetchColumn();

        return ['token' => $rawToken, 'expires_at' => (string)$expiresAt];
    }

    /** Ligne valide (non révoquée, non expirée) pour ce jeton en clair, ou null. */
    public function findValid(string $rawToken): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM refresh_tokens
            WHERE token_hash = ? AND revoked = 0 AND expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$this->hash($rawToken)]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function revoke(int $id): void {
        $this->db->prepare("UPDATE refresh_tokens SET revoked = 1, last_used_at = NOW() WHERE id = ?")
                 ->execute([$id]);
    }

    /** Révoque tous les refresh tokens actifs d'un utilisateur (déconnexion globale). */
    public function revokeAllForUser(int $userId): void {
        $this->db->prepare("UPDATE refresh_tokens SET revoked = 1 WHERE user_id = ? AND revoked = 0")
                 ->execute([$userId]);
    }

    /** Purge les jetons révoqués ou expirés depuis plus de 7 jours. */
    public function purge(): void {
        $this->db->prepare("
            DELETE FROM refresh_tokens
            WHERE (revoked = 1 OR expires_at < NOW())
              AND created_at < (NOW() - INTERVAL 7 DAY)
        ")->execute();
    }
}
