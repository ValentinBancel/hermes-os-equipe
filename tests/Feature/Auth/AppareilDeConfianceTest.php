<?php

use App\Auth\AppareilsDeConfiance;
use App\Livewire\Compte;
use App\Models\AppareilDeConfiance;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Livewire\Livewire;

/*
 * Appareil de confiance (ADR 0003, amendement) : code TOTP sauté à la connexion seulement si cookie d'appareil valide
 * ET IP dans un réseau de confiance ; mot de passe toujours exigé ; actions toujours sous code.
 */

const MDP = 'mot-de-passe-de-test-long';

function reseau(User $user, string $plage = '127.0.0.1/32'): void
{
    $user->reseauxDeConfiance()->create(['libelle' => 'Maison', 'plage' => $plage]);
}

beforeEach(function () {
    $this->withoutMiddleware(EncryptCookies::class);
});

it('propose la case « faire confiance » seulement depuis un réseau de confiance', function () {
    [$user] = $this->compteEnrole();
    $this->post('/connexion', ['email' => $user->email, 'password' => MDP]);
    $this->get('/connexion/code')->assertOk()->assertDontSee('Faire confiance à ce PC');

    reseau($user);
    $this->get('/connexion/code')->assertOk()->assertSee('Faire confiance à ce PC pendant 30 jours');
});

it('saute le code depuis un appareil et un réseau de confiance, mot de passe exigé', function () {
    [$user, $secret] = $this->compteEnrole();
    reseau($user);

    $this->post('/connexion', ['email' => $user->email, 'password' => MDP])->assertRedirect('/connexion/code');
    $r = $this->post('/connexion/code', ['code' => $this->codeTotp($secret), 'confiance' => '1']);
    $valeur = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === AppareilsDeConfiance::COOKIE)->getValue();
    expect(AppareilDeConfiance::count())->toBe(1)
        ->and(AppareilDeConfiance::first()->jeton_hash)->not->toContain(explode('|', $valeur)[1]);

    $this->post('/deconnexion');
    $this->assertGuest();

    // Mauvais mot de passe : refus, même avec le cookie.
    $this->withUnencryptedCookie(AppareilsDeConfiance::COOKIE, $valeur)
        ->post('/connexion', ['email' => $user->email, 'password' => 'mauvais-mot-de-passe-long'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->withUnencryptedCookie(AppareilsDeConfiance::COOKIE, $valeur)
        ->post('/connexion', ['email' => $user->email, 'password' => MDP])->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
    $this->get('/')->assertOk();
    expect(session(AppareilsDeConfiance::CLE_SESSION))->toBe(AppareilDeConfiance::first()->id)
        ->and(session('hermes.totp_confirme_a'))->toBeNull()
        ->and(AuditLog::where('event', 'connexion.reussie')->latest('id')->value('context'))->toBe(['appareil_de_confiance' => AppareilDeConfiance::first()->id]);
});

it('redemande le code hors réseau de confiance, ou avec un jeton faux ou expiré', function () {
    [$user, $secret] = $this->compteEnrole();
    reseau($user);
    $this->post('/connexion', ['email' => $user->email, 'password' => MDP]);
    $r = $this->post('/connexion/code', ['code' => $this->codeTotp($secret), 'confiance' => '1']);
    $valeur = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === AppareilsDeConfiance::COOKIE)->getValue();
    $this->post('/deconnexion');

    // Jeton altéré.
    $faux = explode('|', $valeur)[0].'|'.str_repeat('a', 64);
    $this->withUnencryptedCookie(AppareilsDeConfiance::COOKIE, $faux)
        ->post('/connexion', ['email' => $user->email, 'password' => MDP])->assertRedirect('/connexion/code');
    $this->assertGuest();

    // Hors réseau.
    $user->reseauxDeConfiance()->update(['plage' => '203.0.113.0/24']);
    $this->withUnencryptedCookie(AppareilsDeConfiance::COOKIE, $valeur)
        ->post('/connexion', ['email' => $user->email, 'password' => MDP])->assertRedirect('/connexion/code');
    $this->assertGuest();

    // Expiré (30 jours).
    $user->reseauxDeConfiance()->update(['plage' => '127.0.0.1/32']);
    $this->travel(31)->days();
    $this->withUnencryptedCookie(AppareilsDeConfiance::COOKIE, $valeur)
        ->post('/connexion', ['email' => $user->email, 'password' => MDP])->assertRedirect('/connexion/code');
    $this->assertGuest();
});

it('ne crée pas d’appareil sans réseau de confiance, même case cochée', function () {
    [$user, $secret] = $this->compteEnrole();
    $this->post('/connexion', ['email' => $user->email, 'password' => MDP]);
    $this->post('/connexion/code', ['code' => $this->codeTotp($secret), 'confiance' => '1'])->assertRedirect('/');

    expect(AppareilDeConfiance::count())->toBe(0);
});

it('ferme la session sans code si l’appareil est révoqué ou si le réseau est retiré', function () {
    [$user] = $this->compteEnrole();
    reseau($user);
    $appareil = app(AppareilsDeConfiance::class)->creer($user, request(), 'Firefox · Windows');
    $maintenant = now()->getTimestamp();
    $session = ['hermes.connecte_a' => $maintenant, 'hermes.derniere_activite' => $maintenant, AppareilsDeConfiance::CLE_SESSION => $appareil->id];

    $this->actingAs($user)->withSession($session)->get('/')->assertOk();

    $user->reseauxDeConfiance()->delete();
    $this->get('/')->assertRedirect('/connexion');
    $this->assertGuest();
    expect(AuditLog::where('event', 'session.appareil_invalide')->value('context'))->toBe(['motif' => 'hors_reseau']);

    reseau($user);
    $appareil->delete();
    $this->actingAs($user)->withSession($session)->get('/')->assertRedirect('/connexion');
    expect(AuditLog::where('event', 'session.appareil_invalide')->latest('id')->value('context'))->toBe(['motif' => 'revoque']);
});

it('garde 8 h d’inactivité et 7 jours maximum sur appareil de confiance, 30 min ailleurs', function () {
    [$user] = $this->compteEnrole();
    reseau($user);
    $appareil = app(AppareilsDeConfiance::class)->creer($user, request(), 'Firefox · Windows');
    $maintenant = now()->getTimestamp();
    $this->actingAs($user)->withSession(['hermes.connecte_a' => $maintenant, 'hermes.derniere_activite' => $maintenant, AppareilsDeConfiance::CLE_SESSION => $appareil->id]);

    $this->travel(7)->hours();
    $this->get('/')->assertOk();
    foreach (range(1, 24) as $_) {
        $this->travel(7)->hours();
        $this->get('/');
    }
    $this->assertGuest();
    expect(AuditLog::where('event', 'session.expiree')->value('context'))->toBe(['motif' => 'duree_maximale']);
});

it('demande toujours le code pour une action sur appareil de confiance', function () {
    [$user] = $this->compteEnrole();
    reseau($user);
    $appareil = app(AppareilsDeConfiance::class)->creer($user, request(), 'Firefox · Windows');
    $maintenant = now()->getTimestamp();
    $this->actingAs($user)->withSession(['hermes.connecte_a' => $maintenant, 'hermes.derniere_activite' => $maintenant, AppareilsDeConfiance::CLE_SESSION => $appareil->id]);

    Livewire::test(Compte::class)->call('demanderNouveauxCodes')->assertSet('etapeCode', true);
});

it('gère réseaux et appareils depuis la page Compte, sous code', function () {
    [$user] = $this->compteEnrole();
    $this->connecteAvecTotp($user);

    Livewire::test(Compte::class)
        ->assertSee('Aucun réseau')
        ->set('libelleReseau', 'Maison')
        ->call('demanderAjoutReseau')
        ->assertSet('etapeCode', false)
        ->call('confirmer')
        ->assertSee('Réseau « Maison » ajouté.');
    expect($user->reseauxDeConfiance()->pluck('plage')->all())->toBe(['127.0.0.1/32']);

    Livewire::test(Compte::class)->call('demanderConfianceAppareil')->call('confirmer')->assertSee('Cet appareil est de confiance');
    $appareil = $user->appareilsDeConfiance()->sole();

    Livewire::test(Compte::class)->call('demanderRevocationAppareil', $appareil->id)->call('confirmer')->assertSee('Appareil révoqué.');
    expect($user->appareilsDeConfiance()->count())->toBe(0);

    $reseau = $user->reseauxDeConfiance()->sole();
    Livewire::test(Compte::class)->call('demanderRetraitReseau', $reseau->id)->call('confirmer');
    expect($user->reseauxDeConfiance()->count())->toBe(0)
        ->and(AuditLog::whereIn('event', ['confiance.reseau_ajoute', 'appareil.confiance_ajoute', 'appareil.revoque', 'confiance.reseau_retire'])->count())->toBe(4);
});

it('n’accepte pas la révocation d’un appareil d’un autre compte', function () {
    [$user] = $this->compteEnrole();
    $autre = User::factory()->create();
    $autre->reseauxDeConfiance()->create(['libelle' => 'X', 'plage' => '127.0.0.1/32']);
    $appareil = app(AppareilsDeConfiance::class)->creer($autre, request(), 'Chrome');
    $this->connecteAvecTotp($user);

    Livewire::test(Compte::class)->call('demanderRevocationAppareil', $appareil->id)->assertStatus(404);
    expect(AppareilDeConfiance::count())->toBe(1);
});

it('calcule la plage : /32 en IPv4, préfixe /64 en IPv6', function () {
    $s = app(AppareilsDeConfiance::class);
    expect($s->plagePour('90.12.34.56'))->toBe('90.12.34.56/32')
        ->and($s->plagePour('2a01:cb00:1234:5678:abcd:ef01:2345:6789'))->toBe('2a01:cb00:1234:5678::/64')
        ->and($s->plagePour('pas-une-ip'))->toBeNull();
});

it('révoque les appareils à la réinitialisation TOTP', function () {
    [$user] = $this->compteEnrole();
    reseau($user);
    app(AppareilsDeConfiance::class)->creer($user, request(), 'Chrome');

    $this->artisan('hermes-os:reinitialiser-2fa', ['--force' => true])->assertSuccessful();
    expect(AppareilDeConfiance::count())->toBe(0);
});
