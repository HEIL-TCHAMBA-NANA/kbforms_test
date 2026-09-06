<?php
namespace Modules\Sharing\Services;

use Modules\Sharing\Models\ShareModel;

class ShareService
{
    private ShareModel $model;

    public function __construct()
    {
        $this->model = new ShareModel();
    }

    /**
     * Active ou désactive le mot de passe sur le lien public.
     *
     * @param string|null $password  Mot de passe en clair, ou null pour lever la restriction.
     */
    public function setPassword(int $formId, ?string $password): bool
    {
        $hash = $password !== null ? password_hash($password, PASSWORD_BCRYPT) : null;
        return $this->model->setPasswordHash($formId, $hash);
    }

    /**
     * Vérifie le mot de passe soumis pour un token donné.
     *
     * @return string  'ok' | 'wrong_password' | 'no_password_set'
     */
    public function verifyPassword(string $token, string $candidate): string
    {
        $hash = $this->model->getPasswordHashByToken($token);

        if ($hash === null) {
            return 'no_password_set';
        }

        return password_verify($candidate, $hash) ? 'ok' : 'wrong_password';
    }

    public function isProtected(string $token): bool
    {
        return $this->model->getPasswordHashByToken($token) !== null;
    }
}
