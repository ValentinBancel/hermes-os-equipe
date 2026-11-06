<?php

namespace App\Hermes\Exceptions;

use RuntimeException;

/**
 * Action refusée : le code TOTP a été confirmé il y a plus de 15 minutes (ADR 0003).
 * L'interface redemande le code puis rejoue l'action.
 */
class TotpAReconfirmer extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Code de vérification à saisir de nouveau avant cette action.');
    }
}
