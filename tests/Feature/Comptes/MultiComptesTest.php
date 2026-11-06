<?php

use App\Hermes\HermesClient;
use App\Interface\Audit;
use App\Interface\Presence;
use App\Livewire\Compte;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * L3 — plusieurs comptes : ajout avec mot de passe provisoire, désactivation/réactivation, présence, sessions.
 */

beforeEach(function () {
    [$this->user, $this->secret] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
});

function totpRecent(int $minutes = 2): void
{
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->subMinutes($minutes)->getTimestamp(),
    ]);
}

function sessionEnBase(User $user, string $id, string $agent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1'): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->getKey(), 'ip_address' => '203.0.113.7', 'user_agent' => $agent,
        'payload' => '', 'last_activity' => now()->subHour()->getTimestamp(),
    ]);
}

/** Mot de passe provisoire envoyé au navigateur par l'événement « mdp-provisoire » (jamais une propriété). */
function mdpProvisoire($page): string
{
    $evenement = collect(data_get($page->effects, 'dispatches'))->firstWhere('name', 'mdp-provisoire');

    return (string) data_get($evenement, 'params.motDePasse');
}

it('ajoute un compte avec un mot de passe provisoire de 20 caractères, affiché une fois et jamais journalisé', function () {
    $this->actingAs($this->user);
    totpRecent();
    Log::spy();

    $page = Livewire::test(Compte::class)
        ->set('nouveauNom', 'Thomas Roche')
        ->set('nouvelEmail', ' Thomas.Roche@Example.test ')
        ->call('demanderAjoutCompte')
        ->assertSet('etapeCode', false);

    expect($page->get('confirmation')['action'])->toBe('comptes.ajouter')
        ->and($page->get('confirmation')['titre'])->toBe('Créer le compte')
        ->and(User::count())->toBe(1);

    $page->call('confirmer')
        ->assertDispatched('mdp-provisoire', nom: 'Thomas Roche', email: 'thomas.roche@example.test');

    $nouveau = User::where('email', 'thomas.roche@example.test')->sole();
    $mdp = mdpProvisoire($page);

    expect(mb_strlen($mdp))->toBe(20)
        ->and(Hash::check($mdp, $nouveau->password))->toBeTrue()
        ->and($nouveau->mot_de_passe_provisoire)->toBeTrue()
        ->and($nouveau->cree_par)->toBe($this->user->id)
        ->and($nouveau->two_factor_secret)->toBeNull();

    // M1 secu : ni dans l'état Livewire renvoyé au navigateur (snapshot), ni dans le HTML rendu par le serveur.
    expect(json_encode($page->snapshot))->not->toContain($mdp)
        ->and($page->html())->not->toContain($mdp);
    $page->assertDontSee('<p class="flash', false)
        ->call('$refresh')->assertDontSee($mdp);
    expect(json_encode($page->snapshot))->not->toContain($mdp);
    $this->get('/compte')->assertOk()->assertDontSee($mdp);

    $ligne = AuditLog::where('event', 'compte.cree')->sole();
    expect($ligne->user_id)->toBe($this->user->id)
        ->and($ligne->context['cible'])->toBe($nouveau->id)
        ->and($ligne->context['cible_email'])->toBe('thomas.roche@example.test')
        ->and(json_encode(AuditLog::all()->toArray()))->not->toContain($mdp);

    Log::shouldNotHaveReceived('log', fn (...$args) => str_contains(json_encode($args), $mdp));
    foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $niveau) {
        Log::shouldNotHaveReceived($niveau, fn (...$args) => str_contains(json_encode($args), $mdp));
    }
});

it('exige un code TOTP de moins de 15 min pour ajouter un compte', function () {
    $this->actingAs($this->user);
    totpRecent(30);

    $page = Livewire::test(Compte::class)
        ->set('nouveauNom', 'Inès')->set('nouvelEmail', 'ines@example.test')
        ->call('demanderAjoutCompte')
        ->assertSet('etapeCode', true)
        ->call('confirmer');

    expect(User::count())->toBe(1);

    $page->set('code', $this->codeTotp($this->secret))->call('confirmer');
    expect(User::where('email', 'ines@example.test')->exists())->toBeTrue();
});

it('refuse un e-mail déjà utilisé et garde le compte à créer verrouillé', function () {
    $this->actingAs($this->user);
    totpRecent();

    Livewire::test(Compte::class)
        ->set('nouveauNom', 'Doublon')->set('nouvelEmail', mb_strtoupper($this->user->email))
        ->call('demanderAjoutCompte')
        ->assertHasErrors(['nouvelEmail' => 'unique'])
        ->assertSet('confirmation', null);

    $page = Livewire::test(Compte::class)
        ->set('nouveauNom', 'Karim')->set('nouvelEmail', 'karim@example.test')
        ->call('demanderAjoutCompte');

    // Le navigateur peut changer le formulaire après coup, pas le compte réellement créé.
    $page->set('nouvelEmail', 'pirate@example.test')->call('confirmer');
    expect(User::where('email', 'karim@example.test')->exists())->toBeTrue()
        ->and(User::where('email', 'pirate@example.test')->exists())->toBeFalse();

    expect(fn () => $page->set('compteAAjouter', ['nom' => 'x', 'email' => 'x@example.test']))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('force le changement du mot de passe provisoire puis l’enrôlement TOTP à la première connexion', function () {
    $this->actingAs($this->user);
    totpRecent();
    $page = Livewire::test(Compte::class)
        ->set('nouveauNom', 'Sophie')->set('nouvelEmail', 'sophie@example.test')
        ->call('demanderAjoutCompte')->call('confirmer');
    $mdp = mdpProvisoire($page);

    auth()->logout();
    $this->flushSession();

    $this->post('/connexion', ['email' => 'sophie@example.test', 'password' => $mdp])->assertRedirect();
    $this->get('/')->assertRedirect(route('mot-de-passe.changer'));
});

it('désactive un compte : sessions fermées, appareils révoqués, action journalisée', function () {
    config(['session.driver' => 'database']);
    $autre = User::factory()->create(['email' => 'thomas@example.test']);
    sessionEnBase($autre, 'session-thomas');
    $autre->appareilsDeConfiance()->create(['jeton_hash' => hash('sha256', 'x'), 'libelle' => 'Safari', 'expire_le' => now()->addDays(30)]);

    $this->actingAs($this->user);
    totpRecent();
    Livewire::test(Compte::class)->call('demanderDesactivation', $autre->id)->call('confirmer');

    $autre->refresh();
    expect($autre->desactive_le)->not->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $autre->id)->count())->toBe(0)
        ->and($autre->appareilsDeConfiance()->count())->toBe(0);

    $ligne = AuditLog::where('event', 'compte.desactive')->sole();
    expect($ligne->user_id)->toBe($this->user->id)
        ->and($ligne->context['cible'])->toBe($autre->id);

});

it('refuse la connexion d’un compte désactivé avec le message des identifiants invalides', function () {
    $autre = User::factory()->create(['email' => 'thomas@example.test']);
    $autre->forceFill(['desactive_le' => now()])->save();

    $this->post('/connexion', ['email' => 'thomas@example.test', 'password' => 'mot-de-passe-de-test-long'])->assertSessionHasErrors('email');
    $messageRefus = session('errors')->first('email');
    $this->assertGuest();

    $this->flushSession();
    $this->post('/connexion', ['email' => 'personne@example.test', 'password' => 'mot-de-passe-de-test-long'])->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe($messageRefus)
        ->and(AuditLog::where('event', 'connexion.echec')->where('user_id', $autre->id)->exists())->toBeTrue();

    $autre->forceFill(['desactive_le' => null])->save();
    $this->flushSession();
    $this->post('/connexion', ['email' => 'thomas@example.test', 'password' => 'mot-de-passe-de-test-long']);
    $this->assertAuthenticatedAs($autre);
});

it('ferme la session en cours d’un compte désactivé à sa requête suivante', function () {
    $autre = User::factory()->create(['desactive_le' => null]);
    $this->connecteAvecTotp($autre);
    $autre->forceFill(['desactive_le' => now()])->save();

    $this->get('/')->assertRedirect('/connexion');
    $this->assertGuest();
    expect(AuditLog::where('event', 'session.expiree')->value('context'))->toBe(['motif' => 'compte_desactive']);
});

it('refuse de désactiver son propre compte ou le dernier compte actif', function () {
    $this->actingAs($this->user);
    totpRecent();

    Livewire::test(Compte::class)->call('demanderDesactivation', $this->user->id)
        ->assertSet('confirmation', null)
        ->assertSee('Impossible de désactiver son propre compte.');

    $autre = User::factory()->create();
    $page = Livewire::test(Compte::class)->call('demanderDesactivation', $autre->id);
    // Entre-temps, l'autre compte devient le seul actif (le compte courant est désactivé par ailleurs).
    $this->user->forceFill(['desactive_le' => now()])->save();
    $page->call('confirmer')->assertSee('Impossible de désactiver le dernier compte actif.');

    expect($autre->fresh()->estActif())->toBeTrue();
});

it('réactive un compte désactivé', function () {
    $autre = User::factory()->create(['email' => 'karim@example.test']);
    $autre->forceFill(['desactive_le' => now()])->save();
    $this->actingAs($this->user);
    totpRecent();

    Livewire::test(Compte::class)->assertSee('désactivé')
        ->call('demanderReactivation', $autre->id)->call('confirmer')
        ->assertSee('Compte de '.$autre->name.' réactivé.');

    expect($autre->fresh()->estActif())->toBeTrue()
        ->and(AuditLog::where('event', 'compte.reactive')->value('user_id'))->toBe($this->user->id);
});

it('ferme une autre session du compte, jamais celle d’un autre compte', function () {
    config(['session.driver' => 'database']);
    $autre = User::factory()->create();
    sessionEnBase($this->user, 'ma-session-telephone');
    sessionEnBase($autre, 'session-d-un-autre');
    $this->actingAs($this->user);
    totpRecent();

    $page = Livewire::test(Compte::class)->assertSee('Sessions ouvertes')->assertSee('Fermer');
    $page->call('demanderFermetureSession', 'session-d-un-autre')->assertSet('confirmation', null);
    $page->call('demanderFermetureSession', 'ma-session-telephone')->call('confirmer')->assertSee('Session fermée.');

    expect(DB::table('sessions')->where('id', 'ma-session-telephone')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'session-d-un-autre')->exists())->toBeTrue()
        ->and(AuditLog::where('event', 'compte.session_fermee')->value('user_id'))->toBe($this->user->id);
});

it('compte les connectés (activité < 5 min, comptes actifs) et note l’activité au plus une fois par minute', function () {
    User::factory()->create()->forceFill(['derniere_activite_le' => now()->subMinutes(2)])->save();
    User::factory()->create()->forceFill(['derniere_activite_le' => now()->subMinutes(6)])->save();
    User::factory()->create()->forceFill(['derniere_activite_le' => now(), 'desactive_le' => now()])->save();

    $this->connecteAvecTotp($this->user)->get('/')->assertOk();
    $premiere = $this->user->fresh()->derniere_activite_le;
    expect($premiere)->not->toBeNull()
        ->and(Presence::connectes())->toBe(2);

    $this->travel(30)->seconds();
    $this->get('/')->assertOk();
    expect($this->user->fresh()->derniere_activite_le->getTimestamp())->toBe($premiere->getTimestamp());

    $this->travel(31)->seconds();
    $this->get('/')->assertOk();
    expect($this->user->fresh()->derniere_activite_le->getTimestamp())->toBeGreaterThan($premiere->getTimestamp());
});

it('affiche connecté, dernière connexion ou jamais connecté dans la liste des comptes', function () {
    User::factory()->create(['name' => 'Karim Benali'])->forceFill(['derniere_activite_le' => now()->subDay()])->save();
    User::factory()->create(['name' => 'Sophie Lambert']);
    $this->actingAs($this->user);
    totpRecent();
    $this->user->forceFill(['derniere_activite_le' => now()])->save();

    Livewire::test(Compte::class)
        ->assertSeeInOrder(['Karim Benali', 'dernière connexion'])
        ->assertSeeInOrder(['Sophie Lambert', 'jamais connecté'])
        ->assertDontSee('connecté(e)')
        ->assertSee('connecté');
});

it('affiche les moments des sessions et de la désactivation avec <time datetime title> (qa m1)', function () {
    config(['session.driver' => 'database']);
    sessionEnBase($this->user, 'ma-session-telephone');
    User::factory()->create(['name' => 'Karim Benali'])->forceFill(['desactive_le' => now()->subDay()])->save();
    $this->actingAs($this->user);
    totpRecent();

    $html = Livewire::test(Compte::class)->html();
    expect(substr_count($html, '<time datetime='))->toBeGreaterThanOrEqual(2)
        ->and($html)->toMatch('/<time datetime="[^"]+" title="[^"]+"\s*>désactivé le /')
        ->and($html)->toMatch('/203\.0\.113\.7 · <time datetime="[^"]+" title="[^"]+"/');
});

it('ne laisse jamais zéro compte actif quand deux comptes se désactivent mutuellement (M2)', function () {
    $autre = User::factory()->create();
    $this->actingAs($this->user);
    totpRecent();
    $pageA = Livewire::test(Compte::class)->call('demanderDesactivation', $autre->id);

    $this->actingAs($autre);
    totpRecent();
    $pageB = Livewire::test(Compte::class)->call('demanderDesactivation', $this->user->id);

    $this->actingAs($this->user);
    $pageA->call('confirmer');
    $this->actingAs($autre);
    $pageB->call('confirmer')->assertSee('Impossible de désactiver');

    expect(User::query()->actifs()->count())->toBe(1);
});

it('abandonne le défi TOTP d’un compte désactivé entre le mot de passe et le code (secu m1)', function () {
    $this->post('/connexion', ['email' => $this->user->email, 'password' => 'mot-de-passe-de-test-long'])
        ->assertRedirect('/connexion/code');
    $this->user->forceFill(['desactive_le' => now()])->save();

    $this->post('/connexion/code', ['code' => $this->codeTotp($this->secret)])->assertRedirect('/connexion');
    $this->assertGuest();
    expect(session('login.id'))->toBeNull();
});

it('rattache au compte les lignes de verrouillage, comme connexion.echec (secu m2)', function () {
    foreach (range(1, 2) as $_) {
        foreach (range(1, 5) as $__) {
            $this->post('/connexion', ['email' => $this->user->email, 'password' => 'mauvais-mot-de-passe']);
        }
        $this->travel(61)->seconds();
    }
    $this->post('/connexion', ['email' => $this->user->email, 'password' => 'mot-de-passe-de-test-long']);

    expect(AuditLog::where('event', 'connexion.verrouillage')->value('user_id'))->toBe($this->user->id)
        ->and(AuditLog::where('event', 'connexion.refusee_verrouillage')->value('user_id'))->toBe($this->user->id);
});

it('garde le hachage leurre en cache : un seul calcul pour plusieurs e-mails inconnus (B1)', function () {
    Cache::forget('hermes-os:leurre-hash');
    $this->post('/connexion', ['email' => 'inconnu1@example.test', 'password' => 'x-mot-de-passe']);
    $premier = Cache::get('hermes-os:leurre-hash');
    $this->post('/connexion', ['email' => 'inconnu2@example.test', 'password' => 'x-mot-de-passe']);

    expect($premier)->toBeString()->not->toBeEmpty()
        ->and(Cache::get('hermes-os:leurre-hash'))->toBe($premier);
});

it('décrit les actions sur les comptes dans le journal', function () {
    expect(Audit::decrire(new AuditLog(['event' => 'compte.desactive', 'context' => ['cible_email' => 'a@b.c']])))
        ->toBe(['Compte', 'Compte désactivé : a@b.c']);
});
