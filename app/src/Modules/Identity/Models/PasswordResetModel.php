<?php
namespace Modules\Identity\Models;

use Core\Database;
use PDO;

class PasswordResetModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    /**
     * Invalide les codes en cours pour cet email puis en crée un nouveau.
     * L'expiration est calculée côté MySQL (NOW()) pour éviter tout décalage
     * de fuseau entre PHP et le serveur de base.
     */
    public function create(string $email, string $codeHash, int $ttlMinutes = 15): int {
        $this->db->prepare("UPDATE password_resets SET used = 1 WHERE email = ? AND used = 0")
                 ->execute([$email]);

        $stmt = $this->db->prepare("
            INSERT INTO password_resets (email, code_hash, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
        ");
        $stmt->execute([$email, $codeHash, $ttlMinutes]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Dernier code non utilisé et non expiré pour cet email, ou null.
     */
    public function findValid(string $email): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM password_resets
            WHERE email = ? AND used = 0 AND expires_at > NOW()
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function incrementAttempts(int $id): void {
        $this->db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?")
                 ->execute([$id]);
    }

    public function markUsed(int $id): void {
        $this->db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
                 ->execute([$id]);
    }

    public function purgeExpired(): void {
        $this->db->prepare("DELETE FROM password_resets WHERE expires_at < (NOW() - INTERVAL 1 DAY)")
                 ->execute();
    }
}
