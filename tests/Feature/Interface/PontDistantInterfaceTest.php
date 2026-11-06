<?php

use App\Hermes\DemoClient;
use App\Hermes\HermesClient;
use App\Interface\Temps;
use App\Livewire\Accueil;
use App\Livewire\Compte;
use App\Livewire\Planifiee;
use App\Models\AuditLog;
use App\Models\PontAction;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Pont\Appairage;
use Livewire\Livewire;

/*
 * L4 — pont distant côté interface : panneau « Hermes relié » (code d'appairage, délier) et action en file
 * suivie par wire:poll jusqu'au résultat du pont.
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    config(['hermes-os.pont.driver' => 'distant', 'hermes-os.pont.attente_action_secondes' => 0, 'app.url' => 'https://console.example']);
    app()->forgetInstance(HermesClient::class);
    $this->actingAs($this->user);
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->getTimestamp(),
    ]);
});

function relierDepuisInterface(): PontInstance
{
    $code = app(Appairage::class)->generer(null)['code'];
    app(Appairage::class)->echanger($code, ['nom' => 'Hermes Équipe', 'hote' => 'hermes.example', 'version' => '0.24.1', 'profils' => ['dev', 'cto']]);

    return PontInstance::active();
}

it('affiche la commande d’installation et génère un code d’appairage après confirmation', function () {
    $page = Livewire::test(Compte::class)
        ->assertSee('Aucun Hermes relié')
        ->assertSee('curl -fsSL https://console.example/pont/installer.sh | sh')
        ->call('demanderCodeAppairage')
        ->call('confirmer');

    // Le code part en événement navigateur, une seule fois : ni propriété Livewire, ni HTML rendu par le serveur.
    $evenement = collect(data_get($page->effects, 'dispatches'))->firstWhere('name', 'code-appairage');
    $code = (string) data_get($evenement, 'params.code');
    expect($code)->toMatch('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/')
        ->and(data_get($evenement, 'params.expireA') - now()->getTimestamp())->toBeGreaterThan(590)->toBeLessThanOrEqual(600)
        ->and($page->html())->not->toContain($code)
        ->and(json_encode($page->snapshot))->not->toContain($code);
    expect(AuditLog::query()->where('event', 'pont.code')->exists())->toBeTrue();
});

it('affiche l’instance reliée et la délie', function () {
    relierDepuisInterface();
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'donnees' => ['ok' => true], 'recu_le' => now()]);

    Livewire::test(Compte::class)
        ->assertSee('Hermes Équipe')
        ->assertSee('hermes.example · 0.24.1')
        ->assertSee('2 profils')
        ->assertSee('Relier un autre Hermes')
        ->call('demanderDeliaison')
        // Confirmation nommée, rendue à la place du bouton « Délier », validée par un bouton rouge plein (dc M2).
        ->assertSeeHtmlInOrder(['class="panneau-relie verre"', 'etat-vide confirmation-panneau', 'Délier Hermes Équipe ?', 'bouton-danger bouton-danger-plein', 'Journal d’audit'])
        ->assertDontSeeHtml('id="btn-delier"')
        ->call('confirmer')
        ->assertSee('Hermes délié')
        ->assertDispatched('code-appairage-efface');

    expect(PontInstance::active())->toBeNull()
        ->and(AuditLog::query()->where('event', 'pont.delie')->exists())->toBeTrue();
});

it('met une action en file, la suit par wire:poll et clôt le journal au résultat', function () {
    relierDepuisInterface();
    // Relevés = ceux que pousserait le pont : on reprend le jeu de démonstration pour garder des formes réelles.
    $demo = new DemoClient;
    foreach (['/v1/sante', '/v1/cron/c3d4e5f6a1b2'] as $cle) {
        PontReleve::query()->create(['cle' => $cle, 'statut' => 200, 'donnees' => $demo->lire($cle)->donnees, 'recu_le' => now()]);
    }

    $page = Livewire::test(Planifiee::class, ['id' => 'c3d4e5f6a1b2'])
        ->call('demanderLancement')
        ->call('confirmer')
        ->assertSee('en attente de réponse')
        ->assertSeeHtml('wire:poll.2s="suivreAction"');

    $file = PontAction::query()->where('action', '!=', 'lecture')->sole();
    expect($file->action)->toBe('cron.run')
        ->and($file->acteur)->toBe($this->user->email)
        ->and(AuditLog::query()->where('event', 'action.cron.run')->value('status'))->toBe('pending');

    $page->call('suivreAction')->assertSet('enAttente.id', $file->id);

    $file->update(['statut' => 'terminee', 'resultat' => ['ok' => true, 'code' => 200, 'donnees' => ['ok' => true, 'code' => 0, 'sortie' => 'lancé']]]);
    $page->call('suivreAction')->assertSet('enAttente', null)->assertDontSeeHtml('wire:poll.2s="suivreAction"');

    expect($page->get('resultat')['ok'])->toBeTrue()
        ->and(AuditLog::query()->where('event', 'action.cron.run')->value('status'))->toBe('ok');
});

it('affiche sur l’accueil les données relevées par le pont, sans plus rien mettre en file une fois tout relevé', function () {
    // Heure fixe : la frise ne lit le détail que des tâches passées aujourd'hui (démo : dernière exécution il y a
    // 10 h), donc avant 10 h à Paris aucune clé /v1/cron/… ne serait demandée.
    $this->travelTo(now('Europe/Paris')->setTime(15, 0));
    relierDepuisInterface();
    $demo = new DemoClient;

    // Comme le pont : chaque clé demandée (action « lecture ») devient un relevé, jusqu'à ce que plus rien ne manque.
    foreach (range(1, 3) as $_) {
        Livewire::withoutLazyLoading()->test(Accueil::class);
        foreach (PontAction::query()->where('action', 'lecture')->get() as $a) {
            [$chemin, $requete] = array_pad(explode('?', $a->donnees['chemin'], 2), 2, '');
            parse_str($requete, $q);
            PontReleve::query()->updateOrCreate(['cle' => $a->donnees['chemin']],
                ['statut' => 200, 'donnees' => $demo->lire($chemin, $q)->donnees, 'recu_le' => now()]);
        }
        PontAction::query()->delete();
    }

    Livewire::withoutLazyLoading()->test(Accueil::class)
        ->assertDontSee('Pont injoignable')
        ->assertDontSee('Donnée en cours de récupération');
    expect(PontAction::query()->count())->toBe(0)
        ->and(PontReleve::query()->where('cle', 'like', '/v1/cron/%')->count())->toBeGreaterThan(0);
});

it('nomme l’instance remplacée dans la confirmation, sans avertissement rouge (dc M2)', function () {
    relierDepuisInterface();

    Livewire::test(Compte::class)
        ->call('demanderCodeAppairage')
        ->assertSee('Remplacer Hermes Équipe ?')
        ->assertDontSee('Relier un Hermes</b>', false)
        ->assertSeeHtmlInOrder(['etat-vide confirmation-panneau', 'class="bouton"'])
        ->assertDontSeeHtml('bouton-danger-plein')
        ->assertDontSeeHtml('id="btn-generer"');
});

it('grise les mesures d’une instance hors ligne et ne donne rien pour vérifié (dc M1)', function () {
    relierDepuisInterface();
    $releve = now()->subMinutes(5);
    PontInstance::active()->update(['latence_ms' => 42, 'dernier_releve_le' => $releve]);
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'recu_le' => $releve,
        'donnees' => ['ok' => true, 'verifications' => [['nom' => 'Gateway', 'ok' => true], ['nom' => 'Base de sessions', 'ok' => true]]]]);

    $html = Livewire::test(Compte::class)->assertSee('Hors ligne')->html();

    expect($html)->toContain('instance-mesures fige')
        ->toContain('dernier relevé à '.Temps::heure($releve))
        ->toContain('Non vérifié')
        ->not->toMatch('/class="ok">OK</');
});

it('en ligne : vérifications OK et relevé relatif', function () {
    relierDepuisInterface()->update(['dernier_releve_le' => now()]);
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'recu_le' => now(),
        'donnees' => ['ok' => true, 'verifications' => [['nom' => 'Gateway', 'ok' => true]]]]);

    $html = Livewire::test(Compte::class)->assertSee('En ligne')->html();

    expect($html)->not->toContain('Non vérifié')->not->toContain('instance-mesures fige')->toContain('relevé ');
});

it('commande de liaison copiable, coupée avant « | », code d’appairage dans sa zone (dc m1, m2, m3, M4)', function () {
    Livewire::test(Compte::class)
        ->assertSeeHtml('data-texte="curl -fsSL https://console.example/pont/installer.sh | sh"')
        ->assertSeeHtml("https://console.example/pont/installer.sh\n  | sh</div>")
        ->assertSee('Copier la commande')
        ->assertSee('(générez-le ci-dessous)')
        ->assertSeeHtml('class="code-appairage code-expire"')
        ->assertSeeHtml('id="btn-copier-code"');
});

it('« Code expiré à HH:MM » reçoit le fuseau d’affichage de l’application (celui de « dernier relevé à »), pas celui du navigateur (dc m9)', function () {
    config(['hermes-os.fuseau' => 'America/Montreal']);

    Livewire::test(Compte::class)->assertSeeHtml('x-data="codeAppairage" data-fuseau="America/Montreal"');
});
