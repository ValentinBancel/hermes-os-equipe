<?php

namespace App\Http\Middleware;

use App\Audit\Journal;
use App\Auth\AppareilsDeConfiance;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durée de session : 30 min d'inactivité, 12 h maximum depuis la connexion (ADR 0003) ; sur appareil de confiance
 * (session ouverte sans code, réseau de confiance), 8 h d'inactivité et 7 jours maximum.
 * Vérifié à chaque requête authentifiée, indépendamment du driver de session.
 */
class SessionLimitee
{
    public function __construct(private readonly Journal $journal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $maintenant = now()->getTimestamp();
        $connecteA = $request->session()->get('hermes.connecte_a');
        $derniereActivite = $request->session()->get('hermes.derniere_activite', $connecteA);

        $appareil = $request->session()->has(AppareilsDeConfiance::CLE_SESSION);
        $maxSecondes = $appareil
            ? (int) config('hermes-os.auth.appareil.jours_session_max') * 86400
            : (int) config('hermes-os.auth.heures_session_max') * 3600;
        $inactiviteSecondes = $appareil
            ? (int) config('hermes-os.auth.appareil.heures_inactivite') * 3600
            : (int) config('hermes-os.auth.minutes_inactivite') * 60;
        $motif = match (true) {
            // Filet de sécurité : la désactivation ferme déjà les sessions du compte (L3).
            ! $user->estActif() => 'compte_desactive',
            ! is_int($connecteA) => 'session_sans_horodatage',
            $maintenant - $connecteA > $maxSecondes => 'duree_maximale',
            $maintenant - (int) $derniereActivite > $inactiviteSecondes => 'inactivite',
            default => null,
        };

        if ($motif !== null) {
            $this->journal->ecrire('session.expiree', 'expired', $user, contexte: ['motif' => $motif]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Session expirée.'], 401)
                : redirect()->route('login')->with('status', 'Session expirée, reconnectez-vous.');
        }

        $request->session()->put('hermes.derniere_activite', $maintenant);
        $user->noterActivite();

        return $next($request);
    }
}
