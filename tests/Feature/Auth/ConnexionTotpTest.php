<?php

use App\Models\AuditLog;
use App\Models\User;
use Laravel\Fortify\Fortify;

/*
 * Connexion et TOTP obligatoire (ADR 0003) : aucune page servie sans TOTP confirmé.
 */

it('affiche une page de connexion neutre : titre « Connexion », aucune mention de hermes ni marque (ADR 0004, DA-N3)', function () {
    // DA-VALIDEE.md § Anglaise 6 : « Connexion et écrans publics : aucune anglaise, aucune marque » (la marque non verbale
    // du thème B est retirée en DA-N3).
    $html = $this->get('/connexion')
        ->assertOk()
        ->assertSee('<title>Connexion</title>', false)
        ->assertDontSee('class="marque"', false)
        ->assertDontSee('anglaise', false)
        ->assertDontSee('Console privée', false)
        ->assertDontSee('/messagerie', false)
        ->getContent();

    expect(stripos($html, 'hermes'))->toBeFalse();
});

it('garde neutres les écrans de code et de code de secours (ADR 0004)', function () {
    [$user] = $this->compteEnrole();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);

    $code = $this->get('/connexion/code')->assertOk()->assertSee('<title>Code de vérification</title>', false)->getContent();
    expect(stripos($code, 'hermes'))->toBeFalse();

    $this->post('/connexion/code', ['recovery_code' => 'faux-code']);
    $secours = $this->get('/connexion/code')->assertOk()->assertSee('<title>Code de secours</title>', false)->getContent();
    expect(stripos($secours, 'hermes'))->toBeFalse();
});

it('relie l’erreur générique de connexion aux deux champs (A3)', function () {
    $this->post('/connexion', ['email' => 'inconnu@example.test', 'password' => 'mauvais-mot-de-passe']);
    $html = $this->get('/connexion')->assertOk()->getContent();

    expect($html)->toContain('<div id="erreurs" role="alert"')
        ->and(preg_match_all('/aria-invalid="true" aria-describedby="erreurs"/', $html))->toBe(2);
});

it('redirige un visiteur vers la connexion', function () {
    $this->get('/')->assertRedirect('/connexion');
});

it('force l\'enrôlement TOTP à la première connexion', function () {
    $user = User::factory()->create();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);

    // Aucune page avant la confirmation TOTP : tout renvoie vers l'enrôlement.
    $this->get('/')->assertRedirect(route('totp.enrolement'));
    $this->get('/securite/codes')->assertRedirect(route('totp.enrolement'));

    $this->get('/securite/totp')
        ->assertOk()
        ->assertSee('aria-label="QR code d\'enrôlement"><svg', false)
        ->assertSee('Activer la double authentification');

    expect($user->fresh()->two_factor_secret)->not->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('ne régénère pas le secret à chaque affichage de l\'enrôlement', function () {
    $user = User::factory()->create();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);

    $this->get('/securite/totp');
    $secret = $user->fresh()->two_factor_secret;
    $this->get('/securite/totp');

    expect($user->fresh()->two_factor_secret)->toBe($secret);
});

it('confirme l\'enrôlement avec un code valide puis affiche les codes de récupération une fois', function () {
    $user = User::factory()->create();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    $this->get('/securite/totp');

    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);

    $this->post('/securite/totp', ['code' => $this->codeTotp($secret)])
        ->assertRedirect(route('totp.codes'));

    $codes = $user->fresh()->recoveryCodes();
    expect($codes)->toHaveCount(8);

    $this->get('/securite/codes')->assertOk()->assertSee($codes[0]);
    // Plus affichés ensuite.
    $this->get('/securite/codes')->assertRedirect(route('accueil'));

    $this->get('/')->assertOk()->assertSee('<title>Accueil — hermes-os</title>', false);

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(AuditLog::where('event', 'totp.enrolement_confirme')->count())->toBe(1);
});

it('refuse un code d\'enrôlement invalide', function () {
    $user = User::factory()->create();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    $this->get('/securite/totp');

    $this->from('/securite/totp')
        ->post('/securite/totp', ['code' => '000000'])
        ->assertRedirect('/securite/totp')
        ->assertSessionHasErrors('code');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and(AuditLog::where('event', 'totp.enrolement_echec')->count())->toBe(1);
    $this->get('/')->assertRedirect(route('totp.enrolement'));
});

it('demande le code TOTP à un compte enrôlé, sans ouvrir de session avant', function () {
    [$user, $secret] = $this->compteEnrole();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertRedirect('/connexion/code');

    $this->assertGuest();
    $this->get('/')->assertRedirect('/connexion');
    $this->get('/connexion/code')->assertOk()->assertSee('Code de vérification');

    $this->post('/connexion/code', ['code' => $this->codeTotp($secret)])->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    $this->get('/')->assertOk()->assertSee('<title>Accueil — hermes-os</title>', false);
});

it('refuse un code TOTP invalide', function () {
    [$user] = $this->compteEnrole();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);

    $this->post('/connexion/code', ['code' => '000000'])->assertRedirect('/connexion/code');

    $this->assertGuest();
    expect(AuditLog::where('event', 'totp.echec')->count())->toBe(1);
});

it('accepte un code de récupération, une seule fois', function () {
    [$user] = $this->compteEnrole();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    $this->post('/connexion/code', ['recovery_code' => 'code-recup-1'])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    $this->get('/')->assertOk();

    expect($user->fresh()->recoveryCodes())->not->toContain('code-recup-1');
});

it('déconnecte une session ouverte sans TOTP sur un compte enrôlé', function () {
    [$user] = $this->compteEnrole();

    $this->actingAs($user)->withSession([
        'hermes.connecte_a' => now()->getTimestamp(),
    ])->get('/')->assertRedirect('/connexion');

    $this->assertGuest();
});

it('ignore « se souvenir de moi »', function () {
    $user = User::factory()->create();

    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long', 'remember' => 'on'])
        ->assertCookieMissing(Auth::guard('web')->getRecallerName());

    expect($user->fresh()->remember_token)->not->toBeNull(); // jeton de la factory, inchangé
});

it('déconnecte et journalise', function () {
    [$user] = $this->compteEnrole();

    $this->connecteAvecTotp($user)->post('/deconnexion')->assertRedirect('/');

    $this->assertGuest();
    expect(AuditLog::where('event', 'deconnexion')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('affiche la clé TOTP groupée par 4 et copiable (dc m6 L3)', function () {
    $user = User::factory()->create();
    $this->post('/connexion', ['email' => $user->email, 'password' => 'mot-de-passe-de-test-long']);
    $this->get('/securite/totp')->assertOk();
    $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
    $groupes = implode(' ', str_split($secret, 4));

    $this->get('/securite/totp')
        ->assertSee('<span class="cle-totp">'.$groupes.'</span>', false)
        ->assertSee('data-texte="'.$secret.'"', false)
        ->assertSee('data-libelle="Copier la clé"', false);
});
