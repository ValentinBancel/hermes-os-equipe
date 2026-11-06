<?php

namespace App\Http\Middleware;

use App\Audit\Journal;
use App\Auth\Verrouillage;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Devant POST /connexion, POST /connexion/code et POST /securite/totp :
 * - refuse toute tentative tant que le compte est verrouillé (10 échecs → 15 min) ;
 * - ignore « se souvenir de moi » : le cookie de rappel contournerait la durée maximale de 12 h.
 */
class ProtegerConnexion
{
    public function __construct(
        private readonly Verrouillage $verrouillage,
        private readonly Journal $journal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->merge(['remember' => false]);
        $request->session()->put('login.remember', false);

        $etapeCode = ! $request->routeIs('login.store');
        $compte = match (true) {
            $request->user() instanceof User => $request->user(),
            $request->routeIs('two-factor.login.store') => User::query()->find($request->session()->get('login.id')),
            default => null,
        };

        // m1 secu : compte désactivé entre le mot de passe et le code → le défi en attente est abandonné et on renvoie
        // vers l'écran de connexion (message neutre, identique à un échec d'identifiants).
        if ($compte !== null && $request->routeIs('two-factor.login.store') && ! $compte->estActif()) {
            $request->session()->forget(['login.id', 'login.remember']);

            return redirect()->route('login')->withErrors(['email' => __('auth.failed')]);
        }

        $identifiant = $compte?->email ?? (string) $request->input('email');
        // m2 secu : user_id résolu comme pour `connexion.echec` (null si l'identifiant est inconnu ; journal interne).
        $compte ??= $identifiant === '' ? null : User::query()->where('email', mb_strtolower(trim($identifiant)))->first();

        if ($identifiant && $this->verrouillage->estVerrouille($identifiant)) {
            $this->journal->ecrire('connexion.refusee_verrouillage', 'blocked', $compte, identifiant: $identifiant);

            $minutes = (int) ceil($this->verrouillage->secondesRestantes($identifiant) / 60);

            throw ValidationException::withMessages([
                $etapeCode ? 'code' : 'email' => "Trop d'échecs : compte verrouillé, réessayez dans {$minutes} min.",
            ]);
        }

        return $next($request);
    }
}
