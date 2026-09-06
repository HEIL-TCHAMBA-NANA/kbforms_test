<?php
namespace Modules\Form\Models;

use Core\Database;
use PDO;

class ThemeModel
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    /**
     * Retourne les paramètres visuels d'un formulaire.
     */
    public function getTheme(int $formId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT theme_color, header_image, font_family, font_size
            FROM forms WHERE id = ?
        ");
        $stmt->execute([$formId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Met à jour uniquement les colonnes visuelles fournies (patch partiel).
     * Les clés non fournies dans $fields ne sont pas écrasées.
     *
     * @param array $fields  Sous-ensemble de [theme_color, header_image, font_family, font_size]
     */
    public function updateTheme(int $formId, array $fields): bool
    {
        $allowed = ['theme_color', 'header_image', 'font_family', 'font_size'];
        $set     = [];
        $values  = [];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $fields)) {
                $set[]    = "$col = ?";
                $values[] = $fields[$col];
            }
        }

        if (empty($set)) return false;

        // Le thème vit sur la table `forms` (pas de trigger possible sans
        // récursion) → on incrémente forms.version ici même.
        $set[] = "version = version + 1";

        $values[] = $formId;
        $stmt = $this->db->prepare(
            "UPDATE forms SET " . implode(', ', $set) . " WHERE id = ?"
        );
        return $stmt->execute($values);
    }
}
