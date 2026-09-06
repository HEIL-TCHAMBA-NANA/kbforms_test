<?php
namespace Core;

use Modules\Collaboration\Models\CollaborationModel;

/**
 * Contrôle d'accès à un formulaire, centralisé.
 *
 * Historiquement, le cœur de l'API (`FormController`, `QuestionController`,
 * `SectionController`, analytics, export, webhooks, collaborateurs, Google
 * Sheets…) ne vérifiait PAS que le formulaire visé appartenait bien à
 * l'utilisateur du jeton — n'importe quel compte pouvait lire/modifier/
 * supprimer les enquêtes et réponses d'un autre en devinant un identifiant
 * numérique. Les modules récents (agents, assignations, listes de choix,
 * groupes répétables) faisaient déjà leur propre contrôle ; ce garde-fou
 * unifie le reste.
 *
 * Trois niveaux, alignés sur les rôles collaborateur (`viewer`/`editor`/
 * `admin`) et le propriétaire :
 *   - isMember  : propriétaire OU n'importe quel collaborateur (lecture)
 *   - canEdit   : propriétaire OU collaborateur editor/admin (structure)
 *   - canAdmin  : propriétaire OU collaborateur admin (cycle de vie,
 *                 réglages, collaborateurs, intégrations)
 */
final class FormGuard
{
    /** 'owner' si propriétaire, sinon le rôle collaborateur, sinon null. */
    public static function role(int $formId, int $userId): ?string
    {
        if ($formId <= 0 || $userId <= 0) {
            return null;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT 1 FROM forms WHERE id = ? AND user_id = ?");
        $stmt->execute([$formId, $userId]);
        if ($stmt->fetchColumn()) {
            return 'owner';
        }
        return (new CollaborationModel())->getRole($formId, $userId);
    }

    public static function isMember(int $formId, int $userId): bool
    {
        return self::role($formId, $userId) !== null;
    }

    public static function canEdit(int $formId, int $userId): bool
    {
        return in_array(self::role($formId, $userId), ['owner', 'editor', 'admin'], true);
    }

    public static function canAdmin(int $formId, int $userId): bool
    {
        return in_array(self::role($formId, $userId), ['owner', 'admin'], true);
    }

    /**
     * Applique un niveau (`member` | `edit` | `admin`) et termine la requête
     * en 403 si l'accès est refusé. Renvoie true si l'accès est accordé.
     */
    public static function enforce(string $level, int $formId, int $userId): bool
    {
        $ok = match ($level) {
            'member' => self::isMember($formId, $userId),
            'edit'   => self::canEdit($formId, $userId),
            'admin'  => self::canAdmin($formId, $userId),
            default  => true,
        };
        if (!$ok) {
            http_response_code(403);
            echo json_encode(["success" => false, "error" => "Vous n'avez pas accès à ce formulaire."]);
        }
        return $ok;
    }
}
