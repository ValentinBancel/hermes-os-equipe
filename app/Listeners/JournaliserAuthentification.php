<?php

namespace App\Listeners;

use App\Audit\Journal;
use App\Auth\AppareilsDeConfiance;
use App\Auth\Verrouillage;
use App\Interface\Navigateur;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Journal d'audit et compteur de verrouillage branchés sur les événements d'authentification
 * (découverte automatique des listeners de Laravel).
 */
final class JournaliserAuthentification
{
    public function __construct(
        private readonly Journal $journal,
        private readonly Verrouillage $verrouillage,
    ) {}

    public function handleFailed(Failed $event): void
    {
        // Seul l'identifiant saisi est conservé : jamais le mot de passe.
        $identifiant = (string) ($event->credentials['email'] ?? '');

        $this->journal->ecrire('connexion.echec', 'failed', $this->utilisateur($event->user) ?? $this->compteDe($identifiant), $identifiant);
        $this->verrouillage->echec($identifiant);
    }

    public function handleLogin(Login $event): void
    {
        $user = $this->utilisateur($event->user);
        $maintenant = now()->getTimestamp();

        if (request()->hasSession()) {
            request()->session()->put([
                'hermes.connecte_a' => $maintenant,
                'hermes.derniere_activite' => $maintenant,
            ]);
        }

        // Posée par ConnexionAvecAppareilDeConfiance quand le code a été sauté.
        $appareil = request()->hasSession() ? request()->session()->get(AppareilsDeConfiance::CLE_SESSION) : null;
        $this->journal->ecrire('connexion.reussie', user: $user, contexte: is_int($appareil) ? ['appareil_de_confiance' => $appareil] : []);

        if ($user !== null) {
            $this->verrouillage->reussite($user->email);
        }
    }

    public function handleLogout(Logout $event): void
    {
        $this->journal->ecrire('deconnexion', user: $this->utilisateur($event->user));
    }

    public function handleChallenged(TwoFactorAuthenticationChallenged $event): void
    {
        $this->journal->ecrire('totp.demande', user: $this->utilisateur($event->user));
    }

    public function handleTotpFailed(TwoFactorAuthenticationFailed $event): void
    {
        $user = $this->utilisateur($event->user);

        $this->journal->ecrire('totp.echec', 'failed', $user);

        if ($user !== null) {
            $this->verrouillage->echec($user->email);
        }
    }

    public function handleTotpValide(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put('hermes.totp_confirme_a', now()->getTimestamp());
            if (request()->routeIs('two-factor.login.store')) {
                request()->session()->forget(AppareilsDeConfiance::CLE_SESSION);
            }
        }

        $user = $this->utilisateur($event->user);
        $this->journal->ecrire('totp.reussi', user: $user);

        // Case « Faire confiance à ce PC » de l'écran de code : seulement depuis un réseau de confiance.
        $confiance = app(AppareilsDeConfiance::class);
        if ($user !== null && request()->routeIs('two-factor.login.store') && request()->boolean('confiance')
            && $confiance->ipDeConfiance($user, request()->ip())) {
            $appareil = $confiance->creer($user, request(), Navigateur::libelle((string) request()->userAgent()));
            $this->journal->ecrire('appareil.confiance_ajoute', user: $user, contexte: ['appareil' => $appareil->getKey(), 'source' => 'connexion']);
        }
    }

    public function handleCodeRecuperation(RecoveryCodeReplaced $event): void
    {
        $this->journal->ecrire('totp.code_recuperation_utilise', user: $this->utilisateur($event->user));
    }

    public function handleEnrolement(TwoFactorAuthenticationEnabled $event): void
    {
        $this->journal->ecrire('totp.enrolement_debut', user: $this->utilisateur($event->user));
    }

    public function handleEnrolementConfirme(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->journal->ecrire('totp.enrolement_confirme', user: $this->utilisateur($event->user));
    }

    private function utilisateur(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }

    /** Compte visé par un échec de connexion (Fortify::authenticateUsing ne le transmet pas) : journal interne uniquement. */
    private function compteDe(string $identifiant): ?User
    {
        return $identifiant === '' ? null : User::query()->where('email', mb_strtolower(trim($identifiant)))->first();
    }
}
