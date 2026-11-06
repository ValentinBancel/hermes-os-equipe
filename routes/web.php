<?php

use App\Http\Controllers\Auth\EnrolementTotpController;
use App\Http\Controllers\Auth\NouveauMotDePasseController;
use App\Livewire\Accueil;
use App\Livewire\Compte;
use App\Livewire\Consommation;
use App\Livewire\Journal;
use App\Livewire\Messagerie;
use App\Livewire\Planifiee;
use App\Livewire\Planifiees;
use App\Livewire\Poles;
use App\Livewire\Profil;
use App\Livewire\Sessions;
use App\Livewire\Vault;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

/*
 * Routes d'authentification déclarées à la main (Fortify::ignoreRoutes) : seules celles-ci
 * existent. Pas d'inscription, pas de réinitialisation par e-mail, pas de passkeys (ADR 0003).
 * Les noms de route `login`, `two-factor.login`… sont ceux qu'attend Fortify.
 */
Route::middleware('guest')->group(function () {
    Route::get('/connexion', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthenticatedSessionController::class, 'store'])
        ->middleware(['throttle:login', 'connexion.protegee'])
        ->name('login.store');

    Route::get('/connexion/code', [TwoFactorAuthenticatedSessionController::class, 'create'])->name('two-factor.login');
    Route::post('/connexion/code', [TwoFactorAuthenticatedSessionController::class, 'store'])
        ->middleware(['throttle:two-factor', 'connexion.protegee'])
        ->name('two-factor.login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // Mot de passe provisoire : à remplacer avant tout, y compris avant l'enrôlement TOTP.
    Route::get('/securite/mot-de-passe', [NouveauMotDePasseController::class, 'show'])->name('mot-de-passe.changer');
    Route::post('/securite/mot-de-passe', [NouveauMotDePasseController::class, 'store'])
        ->middleware(['throttle:two-factor'])
        ->name('mot-de-passe.enregistrer');

    Route::middleware('mdp.provisoire')->group(function () {
        // Enrôlement TOTP forcé : seul écran accessible tant que le TOTP n'est pas confirmé.
        Route::get('/securite/totp', [EnrolementTotpController::class, 'show'])->name('totp.enrolement');
        Route::post('/securite/totp', [EnrolementTotpController::class, 'store'])
            ->middleware(['throttle:two-factor', 'connexion.protegee'])
            ->name('totp.confirmer');
    });

    /*
     * Pages de la console (ux/02-arborescence.md). Aucune action d'écriture en GET : les actions passent
     * par une boîte de confirmation (POST Livewire), TOTP < 15 min, journal d'audit.
     */
    Route::middleware(['mdp.provisoire', 'totp'])->group(function () {
        Route::get('/securite/codes', [EnrolementTotpController::class, 'codes'])->name('totp.codes');

        Route::livewire('/', Accueil::class)->name('accueil');

        Route::livewire('/planifiees', Planifiees::class)->name('planifiees');
        Route::livewire('/planifiees/{id}', Planifiee::class)->name('planifiees.fiche')
            ->where('id', '[a-f0-9]{12}');
        Route::livewire('/planifiees/{id}/sorties/{sortie}', Planifiee::class)->name('planifiees.sortie')
            ->where(['id' => '[a-f0-9]{12}', 'sortie' => '\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.md']);

        Route::livewire('/consommation', Consommation::class)->name('consommation');
        Route::livewire('/consommation/sessions', Sessions::class)->name('consommation.sessions');

        Route::livewire('/poles', Poles::class)->name('poles');
        Route::livewire('/vault', Vault::class)->name('vault');

        // Messagerie avec les managers (ADR 0008) : désactivable par HERMES_OS_MESSAGERIE=false.
        if (config('hermes-os.messagerie')) {
            Route::livewire('/messagerie', Messagerie::class)->name('messagerie');
            Route::livewire('/messagerie/{interlocuteur}', Messagerie::class)->name('messagerie.interlocuteur')
                ->where('interlocuteur', '[a-z0-9][a-z0-9_-]{0,63}');
            Route::livewire('/messagerie/{interlocuteur}/{conversation}', Messagerie::class)->name('messagerie.conversation')
                ->where(['interlocuteur' => '[a-z0-9][a-z0-9_-]{0,63}', 'conversation' => 'c_[a-f0-9]{16}']);
        }
        Route::livewire('/poles/{profil}', Profil::class)->name('poles.profil')
            ->where('profil', '[a-z0-9][a-z0-9_-]{0,63}');

        Route::livewire('/compte', Compte::class)->name('compte');
        Route::livewire('/compte/journal', Journal::class)->name('compte.journal');
    });
});
