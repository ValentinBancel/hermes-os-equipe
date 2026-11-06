<?php

namespace App\Auth;

use App\Audit\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Verrouillage du compte après N échecs (mot de passe ou TOTP confondus), pendant M minutes.
 *
 * Clé = identifiant (e-mail) normalisé, indépendante de l'IP : un attaquant qui change d'IP
 * ne contourne pas le verrou. Contrepartie assumée : quelqu'un qui connaît l'e-mail peut
 * bloquer le compte 15 minutes (compte unique, e-mail non public).
 */
final class Verrouillage
{
    public function __construct(private readonly Journal $journal) {}

    public function secondesRestantes(string $identifiant): int
    {
        $fin = Cache::get($this->cleVerrou($identifiant));

        return is_int($fin) ? max(0, $fin - now()->getTimestamp()) : 0;
    }

    public function estVerrouille(string $identifiant): bool
    {
        return $this->secondesRestantes($identifiant) > 0;
    }

    public function echec(string $identifiant): void
    {
        $minutes = (int) config('hermes-os.auth.minutes_verrouillage');
        $echecs = RateLimiter::hit($this->cleCompteur($identifiant), $minutes * 60);

        if ($echecs < (int) config('hermes-os.auth.echecs_avant_verrouillage')) {
            return;
        }

        $fin = now()->addMinutes($minutes);
        Cache::put($this->cleVerrou($identifiant), $fin->getTimestamp(), $fin);
        RateLimiter::clear($this->cleCompteur($identifiant));

        $this->journal->ecrire('connexion.verrouillage', 'blocked', User::query()->where('email', mb_strtolower(trim($identifiant)))->first(), identifiant: $identifiant, contexte: [
            'echecs' => $echecs,
            'minutes' => $minutes,
        ]);
    }

    public function reussite(string $identifiant): void
    {
        RateLimiter::clear($this->cleCompteur($identifiant));
    }

    private function cleCompteur(string $identifiant): string
    {
        return 'hermes-os:echecs:'.$this->empreinte($identifiant);
    }

    private function cleVerrou(string $identifiant): string
    {
        return 'hermes-os:verrou:'.$this->empreinte($identifiant);
    }

    private function empreinte(string $identifiant): string
    {
        return hash('sha256', Str::lower(trim($identifiant)));
    }
}
