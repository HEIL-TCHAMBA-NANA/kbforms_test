<?php
namespace Modules\Form\Services;

use Modules\Form\Models\ThemeModel;

class ThemeService
{
    private ThemeModel $model;

    // ✅ FIX : "Plus Jakarta Sans" ajouté — il était proposé dans le frontend
    // mais absent de la liste blanche, causant un 422 systématique
    private const ALLOWED_FONTS = [
        'Inter', 'Plus Jakarta Sans', 'Roboto', 'Open Sans', 'Lato',
        'Montserrat', 'Poppins', 'Raleway', 'Nunito', 'Source Sans Pro', 'Ubuntu',
    ];

    public function __construct()
    {
        $this->model = new ThemeModel();
    }

    /**
     * Retourne le thème visuel du formulaire.
     * Inclut les valeurs par défaut si non définies.
     */
    public function getTheme(int $formId): array
    {
        $theme = $this->model->getTheme($formId);

        if ($theme === null) {
            return ['success' => false, 'error' => 'Form not found'];
        }

        return [
            'success'      => true,
            'theme_color'  => $theme['theme_color']  ?? '#4F46E5',
            'header_image' => $theme['header_image']  ?? null,
            'font_family'  => $theme['font_family']   ?? 'Inter',
            'font_size'    => $theme['font_size']      ? (int)$theme['font_size'] : 16,
        ];
    }

    /**
     * Met à jour les paramètres visuels (patch partiel).
     * Valide chaque champ fourni avant d'écrire.
     */
    public function updateTheme(int $formId, array $input): array
    {
        $fields = [];
        $errors = [];

        // US-026 — Couleur
        if (array_key_exists('theme_color', $input)) {
            $color = $input['theme_color'];
            if ($color !== null && !preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                $errors[] = 'theme_color must be a valid HEX color (e.g. #4F46E5) or null';
            } else {
                $fields['theme_color'] = $color;
            }
        }

        // US-027 — Image d'en-tête
        if (array_key_exists('header_image', $input)) {
            $img = $input['header_image'];
            if ($img !== null && $img !== '') {
                $isBase64 = preg_match('/^data:image\/(png|jpeg|jpg|gif|webp);base64,/', $img);
                $isUrl    = filter_var($img, FILTER_VALIDATE_URL) !== false;
                if (!$isBase64 && !$isUrl) {
                    $errors[] = "L'image doit être une URL ou une image importée valide.";
                } elseif ($isBase64 && strlen($img) > 6_000_000) { // ~4,5 Mo décodés
                    $errors[] = "Image trop lourde. Choisissez-en une plus légère.";
                } else {
                    $fields['header_image'] = $img;
                }
            } else {
                // Champ vide ou null → on efface l'image
                $fields['header_image'] = null;
            }
        }

        // US-028 — Police
        if (array_key_exists('font_family', $input)) {
            $font = $input['font_family'];
            if ($font !== null && !in_array($font, self::ALLOWED_FONTS, true)) {
                $errors[] = 'font_family must be one of: ' . implode(', ', self::ALLOWED_FONTS);
            } else {
                $fields['font_family'] = $font;
            }
        }

        // US-028 — Taille de police
        if (array_key_exists('font_size', $input)) {
            $size = $input['font_size'];
            // Accepter aussi les floats/strings numériques venant du frontend
            if ($size !== null) {
                $size = (int)$size;
                if ($size < 10 || $size > 32) {
                    $errors[] = 'font_size must be an integer between 10 and 32, or null';
                } else {
                    $fields['font_size'] = $size;
                }
            } else {
                $fields['font_size'] = null;
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        if (empty($fields)) {
            return ['success' => false, 'error' => 'No valid field provided'];
        }

        $ok = $this->model->updateTheme($formId, $fields);
        if (!$ok) {
            return ['success' => false, 'error' => 'Form not found or update failed'];
        }

        // Rafraîchir le cache public pour que le nouveau thème / bannière
        // s'affiche immédiatement sur /f/{token}.
        try {
            $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
            if ($form && !empty($form['share_link'])
                && preg_match('#/f/([a-zA-Z0-9]+)$#', $form['share_link'], $m)) {
                (new \Modules\Cache\Services\CacheService())->invalidateForm($m[1]);
            }
        } catch (\Throwable $e) {
            // best effort
        }

        return array_merge(['success' => true], $fields);
    }
}