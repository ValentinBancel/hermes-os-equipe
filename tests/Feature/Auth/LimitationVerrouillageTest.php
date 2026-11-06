<?php

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/*
 * Limitations (5/min) et verrouillage (15 min après 10 échecs), ADR 0003.
 */

function echecConnexion(User $user): TestResponse
{
    return test()->post('/connexion', ['email' => $user->email, 'password' => 'mauvais-mot-de-passe']);
}

it('limite la connexion à 5 tentatives par minute', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        echecConnexion($user)->assertSessionHasErrors('email');
    }

    echecConnexion($user)->assertStatus(429);
});

it('limite le code TOTP à 5 tentatives par minute', function () {
    [$user] = $this->compteEnrole();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);

    foreach (range(1, 5) as $_) {
        $this->post('/connexion/code', ['code' => '000000'])->assertRedirect('/connexion/code');
        // Le contrôleur Fortify garde login.id en session après un échec : on reste à l'étape du code.
    }

    $this->post('/connexion/code', ['code' => '000000'])->assertStatus(429);
});

it('verrouille le compte 15 minutes après 10 échecs, même avec le bon mot de passe', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        echecConnexion($user);
    }
    $this->travel(61)->seconds();
    foreach (range(1, 5) as $_) {
        echecConnexion($user);
    }
    $this->travel(61)->seconds();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertSessionHasErrors(['email' => "Trop d'échecs : compte verrouillé, réessayez dans 14 min."]);
    $this->assertGuest();

    expect(AuditLog::where('event', 'connexion.verrouillage')->count())->toBe(1)
        ->and(AuditLog::where('event', 'connexion.refusee_verrouillage')->count())->toBe(1);

    $this->travel(15)->minutes();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('compte les échecs TOTP dans le verrouillage', function () {
    [$user, $secret] = $this->compteEnrole();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    foreach (range(1, 2) as $lot) {
        foreach (range(1, 5) as $_) {
            $this->post('/connexion/code', ['code' => '000000']);
        }
        $this->travel(61)->seconds();
    }

    $this->post('/connexion/code', ['code' => $this->codeTotp($secret)])
        ->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('remet le compteur à zéro après une connexion réussie', function () {
    $user = User::factory()->create();

    foreach (range(1, 4) as $_) {
        echecConnexion($user);
    }
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    $this->post('/deconnexion');
    $this->travel(61)->seconds();

    foreach (range(1, 5) as $_) {
        echecConnexion($user);
    }
    $this->travel(61)->seconds();

    // 4 + 5 = 9 échecs sans remise à zéro, 5 avec : dans les deux cas pas encore verrouillé ;
    // un échec de plus verrouillerait sans remise à zéro.
    echecConnexion($user);
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertRedirect('/');
});

it('journalise les échecs sans le mot de passe', function () {
    $user = User::factory()->create();

    echecConnexion($user);

    $ligne = AuditLog::where('event', 'connexion.echec')->sole();
    expect($ligne->identifier)->toBe($user->email)
        ->and($ligne->status)->toBe('failed')
        ->and(json_encode($ligne->toArray()))->not->toContain('mauvais-mot-de-passe');
});
