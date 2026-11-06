<?php

namespace App\Auth;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

/**
 * Étape « défi TOTP » du pipeline de connexion Fortify : le mot de passe est toujours vérifié, puis le défi est sauté
 * uniquement pour un appareil de confiance connecté depuis un réseau de confiance (AppareilsDeConfiance).
 */
class ConnexionAvecAppareilDeConfiance extends RedirectIfTwoFactorAuthenticatable
{
    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);

        if ($user?->two_factor_secret && $user->two_factor_confirmed_at !== null) {
            $appareil = app(AppareilsDeConfiance::class)->reconnaitre($request, $user);
            if ($appareil === null) {
                return $this->twoFactorChallengeResponse($request, $user);
            }
            // Session marquée « ouverte par appareil de confiance » (conservée par la régénération de session) :
            // revérifiée à chaque requête par ExigerTotp, sans dispense de code pour les actions.
            $request->session()->put(AppareilsDeConfiance::CLE_SESSION, $appareil->getKey());
        }

        return $next($request);
    }
}
