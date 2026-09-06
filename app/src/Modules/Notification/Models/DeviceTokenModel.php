<?php
namespace Modules\Notification\Models;

use Core\Database;
use PDO;

/**
 * Jetons d'enregistrement FCM des appareils mobiles (table device_tokens).
 * Un jeton est unique : s'il réapparaît pour un autre compte (appareil prêté,
 * changement de session), il est réattribué.
 */
class DeviceTokenModel
{
    private PDO $db;

    public const VALID_PLATFORMS = ['android', 'ios'];

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function upsert(int $userId, string $token, string $platform): bool
    {
        if (!in_array($platform, self::VALID_PLATFORMS, true)) {
            $platform = 'android';
        }
        $stmt = $this->db->prepare("
            INSERT INTO device_tokens (user_id, token, platform)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                user_id      = VALUES(user_id),
                platform     = VALUES(platform),
                last_seen_at = current_timestamp()
        ");
        return $stmt->execute([$userId, $token, $platform]);
    }

    /** Retrait volontaire (déconnexion). Restreint au propriétaire du jeton. */
    public function deleteForUser(int $userId, string $token): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM device_tokens WHERE user_id = ? AND token = ?"
        );
        $stmt->execute([$userId, $token]);
        return $stmt->rowCount() > 0;
    }

    /** Purge d'un jeton signalé invalide par FCM (peu importe le propriétaire). */
    public function deleteToken(string $token): void
    {
        $stmt = $this->db->prepare("DELETE FROM device_tokens WHERE token = ?");
        $stmt->execute([$token]);
    }

    /** @return string[] jetons de l'utilisateur */
    public function tokensForUser(int $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT token FROM device_tokens WHERE user_id = ? ORDER BY last_seen_at DESC"
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    public function countForUser(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM device_tokens WHERE user_id = ?");
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }
}
