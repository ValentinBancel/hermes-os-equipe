<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/*
 * Commandes artisan : hermes-os:compte et hermes-os:reinitialiser-2fa (ADR 0003).
 */

beforeEach(function () {
    // Have I Been Pwned : aucun mot de passe compromis, sauf ceux listés dans $this->compromis.
    $this->compromis = [];
    Http::fake(function (Request $requete) {
        $prefixe = substr($requete->url(), -5);
        $suffixes = collect($this->compromis)
            ->map(fn ($mdp) => strtoupper(sha1($mdp)))
            ->filter(fn ($sha1) => str_starts_with($sha1, $prefixe))
            ->map(fn ($sha1) => substr($sha1, 5).':42');

        return Http::response($suffixes->implode("\r\n"), 200);
    });
});

const MDP_VALIDE = 'un-mot-de-passe-tres-long-42';

it('crée le compte unique', function () {
    $this->artisan('hermes-os:compte', ['--email' => 'Camille@Example.test', '--nom' => 'Camille'])
        ->expectsQuestion('Mot de passe (16 caractères minimum)', MDP_VALIDE)
        ->expectsQuestion('Confirmer le mot de passe', MDP_VALIDE)
        ->expectsOutputToContain('Compte créé pour camille@example.test')
        ->assertSuccessful();

    $user = User::sole();
    expect($user->email)->toBe('camille@example.test')
        ->and(Hash::check(MDP_VALIDE, $user->password))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull()
        ->and(AuditLog::where('event', 'compte.cree')->count())->toBe(1);
});

it('crée aussi les comptes suivants (L3)', function () {
    User::factory()->create(['email' => 'premier@example.test']);

    $this->artisan('hermes-os:compte', ['--email' => 'Autre@example.test', '--nom' => 'Autre', '--provisoire' => true])
        ->expectsOutputToContain('Compte créé pour autre@example.test')
        ->assertSuccessful();

    $autre = User::where('email', 'autre@example.test')->sole();
    expect(User::count())->toBe(2)
        ->and($autre->mot_de_passe_provisoire)->toBeTrue()
        ->and($autre->cree_par)->toBeNull();
});

it('refuse un e-mail déjà utilisé, quelle que soit la casse', function () {
    User::factory()->create(['email' => 'camille@example.test']);

    $this->artisan('hermes-os:compte', ['--email' => ' Camille@Example.test ', '--nom' => 'C', '--provisoire' => true])
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('refuse un mot de passe de moins de 16 caractères', function () {
    $this->artisan('hermes-os:compte', ['--email' => 'v@example.test', '--nom' => 'V'])
        ->expectsQuestion('Mot de passe (16 caractères minimum)', 'court-123')
        ->expectsQuestion('Confirmer le mot de passe', 'court-123')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('refuse une confirmation différente', function () {
    $this->artisan('hermes-os:compte', ['--email' => 'v@example.test', '--nom' => 'V'])
        ->expectsQuestion('Mot de passe (16 caractères minimum)', MDP_VALIDE)
        ->expectsQuestion('Confirmer le mot de passe', MDP_VALIDE.'x')
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('refuse un mot de passe compromis (Have I Been Pwned)', function () {
    $this->compromis = [MDP_VALIDE];

    $this->artisan('hermes-os:compte', ['--email' => 'v@example.test', '--nom' => 'V'])
        ->expectsQuestion('Mot de passe (16 caractères minimum)', MDP_VALIDE)
        ->expectsQuestion('Confirmer le mot de passe', MDP_VALIDE)
        ->assertFailed();

    expect(User::count())->toBe(0);
});

it('réinitialise le TOTP et ferme les sessions', function () {
    [$user] = $this->compteEnrole();
    DB::table('sessions')->insert([
        'id' => 'session-de-test', 'user_id' => $user->id, 'ip_address' => null, 'user_agent' => null,
        'payload' => '', 'last_activity' => now()->getTimestamp(),
    ]);

    $this->artisan('hermes-os:reinitialiser-2fa', ['--force' => true])
        ->expectsOutputToContain('1 session(s) fermée(s)')
        ->assertSuccessful();

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and(AuditLog::where('event', 'totp.reinitialise')->count())->toBe(1);

    // Réenrôlement forcé à la connexion suivante.
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])->assertRedirect('/');
    $this->get('/')->assertRedirect(route('totp.enrolement'));
});

it('demande confirmation avant de réinitialiser', function () {
    [$user] = $this->compteEnrole();

    $this->artisan('hermes-os:reinitialiser-2fa')
        ->expectsConfirmation("Réinitialiser le TOTP de {$user->email} ?", 'no')
        ->assertFailed();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('rend le journal d\'audit non modifiable', function () {
    $ligne = AuditLog::create(['event' => 'test']);

    expect(fn () => $ligne->update(['event' => 'autre']))->toThrow(LogicException::class)
        ->and(fn () => $ligne->delete())->toThrow(LogicException::class);
});
