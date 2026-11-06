<?php

namespace App\Auth;

use App\Audit\Journal;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Reconfirmation du code TOTP dans une session ouverte (actions sensibles) : même contrôle qu'à la connexion
 * (5 essais / min, verrouillage après 10 échecs, journal). Le code n'est jamais journalisé.
 */
final class CodeTotp
{
    public function __construct(private Verrouillage $verrouillage, private Journal $journal) {}

    /** null si le code est bon (session marquée « TOTP confirmé »), sinon le message d'erreur à afficher. */
    public function verifier(User $user, string $saisie, string $contexte): ?string
    {
        if ($this->verrouillage->estVerrouille($user->email)) {
            return 'Trop d’échecs : compte verrouillé, réessayer plus tard.';
        }

        $cle = 'totp|action|'.$user->getKey();
        if (RateLimiter::tooManyAttempts($cle, (int) config('hermes-os.auth.tentatives_par_minute'))) {
            return 'Trop de tentatives. Réessayer dans '.RateLimiter::availableIn($cle).' s.';
        }
        RateLimiter::hit($cle, 60);

        $code = preg_replace('/\D/', '', $saisie) ?? '';
        $secret = $user->two_factor_secret ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null;

        if (strlen($code) !== 6 || $secret === null || ! app(TwoFactorAuthenticationProvider::class)->verify($secret, $code)) {
            $this->journal->ecrire('totp.echec', 'failed', $user, contexte: ['contexte' => $contexte]);
            $this->verrouillage->echec($user->email);

            return 'Code incorrect ou expiré.';
        }

        session()->put('hermes.totp_confirme_a', now()->getTimestamp());
        $this->journal->ecrire('totp.reussi', user: $user, contexte: ['contexte' => $contexte]);

        return null;
    }
}
