<?php

namespace App\Hermes;

/**
 * Réponse du pont à une action (sortie de la CLI `hermes`, déjà masquée par le pont).
 */
final readonly class ResultatAction
{
    public function __construct(
        public Action $action,
        public bool $ok,
        public ?int $code,
        public string $sortie = '',
        public string $erreur = '',
        /** Mode distant : identifiant de l'action encore en file (le pont n'a pas répondu à temps), suivie par wire:poll. */
        public ?string $attente = null,
        /** Ligne du journal à clore quand l'action en file aboutit. */
        public ?int $journal = null,
    ) {}

    /**
     * @param  array<string, mixed>  $reponse
     */
    public static function depuisReponse(Action $action, array $reponse): self
    {
        return new self(
            action: $action,
            ok: ($reponse['ok'] ?? false) === true,
            code: is_int($reponse['code'] ?? null) ? $reponse['code'] : null,
            sortie: (string) ($reponse['sortie'] ?? ''),
            erreur: (string) ($reponse['erreur'] ?? ''),
        );
    }
}
