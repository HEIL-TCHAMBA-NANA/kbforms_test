<?php
namespace Modules\Cache\Services;

use Modules\Cache\Models\CacheModel;

/**
 * Cache DB pour les formulaires publics (TECH-003).
 *
 * Usage dans FormService::getPublicForm() :
 *
 *   $cache = new CacheService();
 *   $hit   = $cache->getForm($token);
 *   if ($hit !== null) { return $hit; }
 *
 *   // ... construire $result ...
 *
 *   $cache->setForm($token, $result);
 *   return $result;
 *
 * Invalider après une modification (publish, ajout question, etc.) :
 *   $cache->invalidateForm($token);
 */
class CacheService
{
    private CacheModel $model;

    /** Durée de vie par défaut : 5 minutes. */
    private int $ttl;

    public function __construct(int $ttlSeconds = 300)
    {
        $this->model = new CacheModel();
        $this->ttl   = $ttlSeconds;
    }

    /**
     * Retourne le formulaire depuis le cache, ou null si absent/expiré.
     */
    public function getForm(string $token): ?array
    {
        $payload = $this->model->get($token);
        if ($payload === null) return null;

        $data = json_decode($payload, true);
        return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
    }

    /**
     * Met en cache le tableau résultat d'un formulaire public.
     */
    public function setForm(string $token, array $data): void
    {
        $this->model->set($token, json_encode($data), $this->ttl);
    }

    /**
     * Invalide le cache pour ce token.
     * À appeler après toute modification du formulaire ou de ses questions.
     */
    public function invalidateForm(string $token): void
    {
        $this->model->invalidate($token);
    }

    /**
     * Purge les entrées expirées (à appeler périodiquement ou via cron).
     */
    public function purgeExpired(): int
    {
        return $this->model->purgeExpired();
    }
}
