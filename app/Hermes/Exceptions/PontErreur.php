<?php

namespace App\Hermes\Exceptions;

use RuntimeException;

/**
 * Le pont a répondu par un refus métier : ressource introuvable (404), argument invalide (422)…
 * Le message vient du pont (en français, sans donnée sensible).
 */
class PontErreur extends RuntimeException
{
    public function __construct(public readonly int $statut, string $message)
    {
        parent::__construct($message, $statut);
    }

    public function introuvable(): bool
    {
        return $this->statut === 404;
    }
}
