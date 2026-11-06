<?php

namespace App\Hermes\Exceptions;

use RuntimeException;

/**
 * Plus de 20 actions par minute : refus jusqu'à la fin de la fenêtre.
 */
class TropDActions extends RuntimeException
{
    public function __construct(public readonly int $secondesAvantReessai)
    {
        parent::__construct("Trop d'actions en une minute. Réessayez dans {$secondesAvantReessai} s.");
    }
}
