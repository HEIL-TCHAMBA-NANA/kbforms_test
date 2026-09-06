<?php
namespace Modules\Sharing\Models;

use Core\Database;
use PDO;

class ShareModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Définit ou supprime le mot de passe sur le lien public d'un formulaire.
     *
     * @param string|null $passwordHash  Hash bcrypt, ou null pour lever la restriction.
     */
    public function setPasswordHash(int $formId, ?string $passwordHash): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE forms SET share_password_hash = ? WHERE id = ?"
        );
        return $stmt->execute([$passwordHash, $formId]);
    }

    /**
     * Retourne le hash bcrypt associé au token de partage.
     * Null si le formulaire n'existe pas, n'est pas publié, ou n'est pas protégé.
     */
    public function getPasswordHashByToken(string $token): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT share_password_hash FROM forms WHERE share_link LIKE ? AND is_published = 1"
        );
        $stmt->execute(['%/f/' . $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ($row && $row['share_password_hash']) ? $row['share_password_hash'] : null;
    }
}
