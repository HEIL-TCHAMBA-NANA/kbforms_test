<?php
namespace Core;

require_once __DIR__ . '/../../src/Modules/Identity/Libraries/php-jwt/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

/**
 * AuthMiddleware
 *
 * Usage dans Router.php :
 *   $router->add("GET", "/forms/{user_id}", [$formController, "listForms"], true);
 *   // Le 4e paramètre `true` active la protection JWT.
 *
 * Depuis un contrôleur, récupérer le payload décodé :
 *   $user = AuthMiddleware::getUser();  // stdClass avec user_id, email, account_type
 *
 * M4 — jeton agent : structurellement différent (agent_id/form_id, pas de
 * user_id). AuthMiddleware::getAgent() est le symétrique de getUser() ; les
 * deux ne sont jamais non-null en même temps. Un jeton agent est revalidé en
 * base à CHAQUE requête (formulaire toujours publié, agent non révoqué) —
 * pas seulement à la connexion : dépublier/révoquer coupe l'accès immédiatement.
 */
class AuthMiddleware {

    private static string $secret = "kbforms_super_secret_key_2026_with_extra_entropy";

    /** Payload décodé du token courant (disponible après authenticate()) */
    private static ?object $currentUser = null;

    /** Jeton agent revalidé (agent_id, form_id, display_name) — M4. */
    private static ?object $currentAgent = null;

    /**
     * Vérifie le JWT dans le header Authorization: Bearer <token>.
     * Termine la requête avec 401 si invalide ou absent.
     *
     * @return object  Le payload décodé (user_id, email, account_type, exp, …
     *                  ou agent_id, form_id, exp pour un jeton agent)
     */
    public static function authenticate(): object {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            self::abort(401, "Missing or malformed Authorization header");
        }

        return self::decodeAndValidate(substr($header, 7));
    }

    /**
     * Comme authenticate(), mais n'exige pas de jeton : sans en-tête
     * Authorization, ne fait rien (requête anonyme autorisée). Un en-tête
     * présent mais invalide/périmé/révoqué est en revanche rejeté (401/403) —
     * jamais silencieusement traité comme anonyme.
     *
     * Utilisé sur les routes publiques qu'un agent (M4) peut aussi appeler,
     * ex. POST /responses : anonyme si pas de jeton, attribué à l'agent sinon.
     */
    public static function optionalAuthenticate(): void {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return;
        }
        self::decodeAndValidate(substr($header, 7));
    }

    private static function decodeAndValidate(string $token): object {
        try {
            $decoded = JWT::decode($token, new Key(self::$secret, 'HS256'));
        } catch (ExpiredException $e) {
            self::abort(401, "Token expired");
        } catch (SignatureInvalidException $e) {
            self::abort(401, "Invalid token signature");
        } catch (\Exception $e) {
            self::abort(401, "Invalid token");
        }

        if (isset($decoded->agent_id)) {
            return self::validateAgentToken($decoded);
        }

        self::$currentUser  = $decoded;
        self::$currentAgent = null;
        return $decoded;
    }

    /** Jeton agent (M4) : revalidé en base à chaque requête, pas qu'au login. */
    private static function validateAgentToken(object $decoded): object {
        $agentId = (int) $decoded->agent_id;
        $formId  = (int) ($decoded->form_id ?? 0);

        $agent = (new \Modules\Agent\Models\FormAgentModel())->findById($agentId);
        if (!$agent || (int) $agent['form_id'] !== $formId) {
            self::abort(401, "Jeton agent invalide");
        }
        if ((int) $agent['is_revoked'] === 1) {
            self::abort(403, "Cette enquête n'est plus disponible.", 'form_unavailable');
        }
        $form = (new \Modules\Form\Models\FormModel())->getFormById($formId);
        if (!$form || (int) ($form['is_published'] ?? 0) !== 1) {
            self::abort(403, "Cette enquête n'est plus disponible.", 'form_unavailable');
        }

        self::$currentAgent = (object) [
            'agent_id'     => $agentId,
            'form_id'      => $formId,
            'display_name' => $agent['display_name'],
        ];
        self::$currentUser = null;
        return self::$currentAgent;
    }

    /**
     * Retourne le payload de l'utilisateur authentifié.
     * À appeler seulement après authenticate()/optionalAuthenticate().
     * Null si le jeton courant est un jeton agent (M4).
     */
    public static function getUser(): ?object {
        return self::$currentUser;
    }

    /**
     * Symétrique de getUser() pour un jeton agent (M4) : {agent_id, form_id,
     * display_name}. Null si le jeton courant est un jeton utilisateur normal
     * (ou si aucun jeton n'a été fourni).
     */
    public static function getAgent(): ?object {
        return self::$currentAgent;
    }

    /**
     * Vérifie qu'un champ du payload correspond à une valeur attendue.
     * Ex: AuthMiddleware::requireField('account_type', 'enterprise');
     */
    public static function requireField(string $field, string $expected): void {
        $user = self::$currentUser;
        if (!$user || ($user->$field ?? null) !== $expected) {
            self::abort(403, "Forbidden: insufficient privileges");
        }
    }

    /**
     * Vérifie que l'utilisateur authentifié est bien le propriétaire de la ressource.
     * Ex: AuthMiddleware::requireOwnership($userIdFromRoute);
     */
    public static function requireOwnership(int $resourceOwnerId): void {
        $user = self::$currentUser;
        if (!$user || (int)$user->user_id !== $resourceOwnerId) {
            self::abort(403, "Forbidden: you do not own this resource");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    private static function abort(int $code, string $message, ?string $errCode = null): never {
        http_response_code($code);
        $out = ["success" => false, "error" => $message];
        if ($errCode !== null) {
            $out['code'] = $errCode;
        }
        echo json_encode($out);
        exit();
    }
}
