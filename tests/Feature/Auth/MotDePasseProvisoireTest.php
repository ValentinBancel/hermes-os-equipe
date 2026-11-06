<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
 * Compte créé avec un mot de passe provisoire : changement obligatoire avant tout, puis enrôlement TOTP.
 */

const MDP_PROVISOIRE = 'provisoire-provisoire-1';
const MDP_NOUVEAU = 'mon-vrai-mot-de-passe-42';
const MDP_COURT = 'court';

beforeEach(function () {
    Http::fake(fn () => Http::response('', 200)); // Have I Been Pwned : rien de compromis.
});

it('crée un compte avec un mot de passe provisoire affiché une seule fois', function () {
    $this->artisan('hermes-os:compte', ['--email' => 'Camille.Martin@Example.TEST', '--nom' => 'Camille', '--provisoire' => true])
        ->expectsOutputToContain('Mot de passe provisoire :')
        ->assertSuccessful();

    $user = User::sole();
    expect($user->email)->toBe('camille.martin@example.test')
        ->and($user->mot_de_passe_provisoire)->toBeTrue()
        ->and(AuditLog::where('event', 'compte.cree')->count())->toBe(1);
});

it('impose le changement du mot de passe provisoire avant le TOTP', function () {
    $user = User::factory()->create(['password' => MDP_PROVISOIRE, 'mot_de_passe_provisoire' => true]);

    $this->post('/connexion', ['email' => $user->email, 'password' => MDP_PROVISOIRE])->assertRedirect('/');

    $this->get('/')->assertRedirect(route('mot-de-passe.changer'));
    $this->get('/securite/totp')->assertRedirect(route('mot-de-passe.changer'));
    $this->get('/securite/mot-de-passe')->assertOk()->assertSee('<title>Nouveau mot de passe — hermes-os</title>', false);

    // Refusé : identique au provisoire.
    $this->post('/securite/mot-de-passe', ['password' => MDP_PROVISOIRE, 'password_confirmation' => MDP_PROVISOIRE])
        ->assertSessionHasErrors('password');

    // Refusé : trop court.
    $this->post('/securite/mot-de-passe', ['password' => MDP_COURT, 'password_confirmation' => MDP_COURT])
        ->assertSessionHasErrors('password');

    $this->post('/securite/mot-de-passe', ['password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU])
        ->assertRedirect(route('totp.enrolement'));

    $user->refresh();
    expect($user->mot_de_passe_provisoire)->toBeFalse()
        ->and(Hash::check(MDP_NOUVEAU, $user->password))->toBeTrue()
        ->and(AuditLog::where('event', 'compte.mot_de_passe_change')->count())->toBe(1);

    // Ensuite : enrôlement TOTP forcé, comme avant.
    $this->get('/')->assertRedirect(route('totp.enrolement'));
    $this->get('/securite/mot-de-passe')->assertRedirect(route('accueil'));
});
