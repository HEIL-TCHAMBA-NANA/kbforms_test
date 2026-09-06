<?php
namespace Modules\Sharing\Entities;

/**
 * Représente les paramètres de partage public d'un formulaire.
 */
class ShareLink
{
    public int     $formId;
    public string  $token;
    public string  $url;
    public bool    $isProtected;

    public function __construct(int $formId, string $token, string $url, bool $isProtected)
    {
        $this->formId      = $formId;
        $this->token       = $token;
        $this->url         = $url;
        $this->isProtected = $isProtected;
    }

    public function toArray(): array
    {
        return [
            'form_id'      => $this->formId,
            'token'        => $this->token,
            'url'          => $this->url,
            'is_protected' => $this->isProtected,
        ];
    }
}
