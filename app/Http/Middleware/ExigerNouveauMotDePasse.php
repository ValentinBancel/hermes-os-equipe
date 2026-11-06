<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mot de passe provisoire (créé par `hermes-os:compte --provisoire`) : tant qu'il n'est pas remplacé,
 * le seul écran accessible est le changement de mot de passe (avant même l'enrôlement TOTP).
 */
class ExigerNouveauMotDePasse
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->mot_de_passe_provisoire) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Changement de mot de passe requis.'], 403)
                : redirect()->route('mot-de-passe.changer');
        }

        return $next($request);
    }
}
