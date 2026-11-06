<?php

namespace App\Interface;

use App\Models\PontInstance;
use App\Models\PontReleve;

/**
 * Pages optionnelles selon le Hermes relié. Un pont distant sans coffre (`--sans-coffre`, Hermes sans vault)
 * ou sans messagerie (pont relié sans `--messagerie`) répond 404 sur ces lectures : la nav masque alors l'entrée
 * et la page renvoie vers l'accueil au lieu d'afficher une 404.
 */
final class Capacites
{
    public static function coffre(): bool
    {
        return self::disponible('/v1/vault');
    }

    public static function messagerie(): bool
    {
        return (bool) config('hermes-os.messagerie') && self::disponible('/v1/messagerie');
    }

    private static function disponible(string $cle): bool
    {
        if (config('hermes-os.pont.driver') !== 'distant' || PontInstance::active() === null) {
            return true;
        }

        // Avant le premier relevé on ne sait rien : on garde l'entrée (la page affiche « récupération en cours »).
        if (! PontReleve::query()->where('cle', '/v1/sante')->exists()) {
            return true;
        }

        return PontReleve::query()->where('cle', $cle)->where('statut', 200)->exists();
    }
}
