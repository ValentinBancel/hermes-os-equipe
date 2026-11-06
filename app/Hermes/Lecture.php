<?php

namespace App\Hermes;

use Carbon\CarbonImmutable;

/**
 * Résultat d'une lecture du pont : données JSON décodées + fraîcheur.
 *
 * `perimee` = le pont n'a pas répondu, ce sont les dernières données connues (âge dans `recupereeA`).
 * L'interface affiche alors « Données d'il y a 2 min — pont injoignable » (ux/03-contenus.md).
 */
final readonly class Lecture
{
    /**
     * @param  array<string, mixed>  $donnees
     */
    public function __construct(
        public array $donnees,
        public CarbonImmutable $recupereeA,
        public bool $perimee = false,
        public ?string $raison = null,
    ) {}

    public function ageSecondes(): int
    {
        return max(0, (int) $this->recupereeA->diffInSeconds(CarbonImmutable::now(), absolute: true));
    }

    public function get(string $cle, mixed $defaut = null): mixed
    {
        return data_get($this->donnees, $cle, $defaut);
    }
}
