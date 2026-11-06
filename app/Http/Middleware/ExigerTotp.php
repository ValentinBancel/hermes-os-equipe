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
 * Aucune page n'est servie sans TOTP (ADR 0003) :
 * - compte non enrôlé → écran d'enrôlement (seul écran accessible) ;
 * - compte enrôlé mais session ouverte sans code TOTP → déconnexion ;
 * - session ouverte par appareil de confiance (sans code) : revérifiée à chaque requête — appareil révoqué ou expiré,
 *   ou IP sortie des réseaux de confiance → déconnexion.
 */
class ExigerTotp
{
    public function __construct(private readonly Journal $journal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at === null) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Enrôlement TOTP requis.'], 403)
                : redirect()->route('totp.enrolement');
        }

        $session = $request->session();
        if ($session->has(AppareilsDeConfiance::CLE_SESSION)) {
            $motif = app(AppareilsDeConfiance::class)->motifInvalidite($request, $user, $session->get(AppareilsDeConfiance::CLE_SESSION));
            if ($motif !== null) {
                $this->journal->ecrire('session.appareil_invalide', 'blocked', $user, contexte: ['motif' => $motif]);

                return $this->deconnecter($request, 'Appareil ou réseau plus de confiance : code requis.');
            }

            return $next($request);
        }

        if (! is_int($session->get('hermes.totp_confirme_a'))) {
            $this->journal->ecrire('session.sans_totp', 'blocked', $user);

            return $this->deconnecter($request);
        }

        return $next($request);
    }

    private function deconnecter(Request $request, ?string $statut = null): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Code TOTP requis.'], 401);
        }

        return $statut !== null ? redirect()->route('login')->with('status', $statut) : redirect()->route('login');
    }
}
