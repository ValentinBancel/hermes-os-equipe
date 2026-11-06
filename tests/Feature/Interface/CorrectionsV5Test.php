<?php

use App\Hermes\HermesClient;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Lot 11b — corrections des audits v5 (decisions.md § v5, V5-1 à V5-13).
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    $this->etat = function (string $etat = 'nominal') {
        config(['hermes-os.pont.demo_etat' => $etat]);
        app()->forgetInstance(HermesClient::class);

        return $this->connecteAvecTotp($this->user);
    };
});

it('V5-2 : en pont injoignable, les statuts gardent leur couleur (aucun grisé forcé sur .etat)', function () {
    // Pôles de démo (L6) : la pastille « en cours » (session active) reste affichée, même figée.
    $html = ($this->etat)('pont_injoignable')->get('/poles')->assertOk()->getContent();
    expect($html)->toContain('class="fige"')
        ->toContain('class="pastille-action" href="'.route('consommation.sessions').'">en cours');

    $css = File::get(resource_path('css/app.css'));
    expect($css)->not->toContain('.fige *')
        ->not->toMatch('/\.fige[^{]*\.(etat|pastille)/')
        ->toContain('.fige :is(.compteurs b, .chiffre-grand b)');
});

it('V5-7 : les erreurs de validation sont en français (aucun « field »), reliées au champ', function () {
    Http::fake(fn () => Http::response('', 200)); // Have I Been Pwned : rien de compromis.
    $user = User::factory()->create(['password' => 'provisoire-provisoire-un', 'mot_de_passe_provisoire' => true]);
    $this->post('/connexion', ['email' => $user->email, 'password' => 'provisoire-provisoire-un']);

    $html = $this->followingRedirects()->from('/securite/mot-de-passe')
        ->post('/securite/mot-de-passe', ['password' => 'mon-vrai-mot-de-passe-quarante', 'password_confirmation' => 'autre-chose-quarante'])
        ->assertOk()->getContent();

    expect($html)->toContain('La confirmation du nouveau mot de passe ne correspond pas.')
        ->not->toContain('field')
        ->not->toContain('The ')
        // m7 : erreur rattachée aux deux champs.
        ->toContain('aria-describedby="mdp-aide  erreurs "')
        ->toMatch('/name="password_confirmation"[^>]*aria-invalid="true" aria-describedby="erreurs"/');

    expect(__('validation.digits', ['attribute' => __('validation.attributes.code'), 'digits' => 6]))
        ->toBe('Le champ code doit contenir 6 chiffres.');
});

it('V5-7 : lang/fr/validation.php couvre toutes les règles employées par l’application', function () {
    $fr = require lang_path('fr/validation.php');
    foreach (['required', 'string', 'confirmed', 'digits', 'email', 'integer', 'regex', 'not_regex', 'boolean'] as $regle) {
        expect($fr)->toHaveKey($regle);
    }
    expect($fr['min'])->toHaveKey('string')
        ->and($fr['max'])->toHaveKey('string')
        ->and($fr['between'])->toHaveKey('numeric')
        ->and($fr['password'])->toHaveKey('uncompromised')
        ->and($fr['attributes']['password'])->toBe('nouveau mot de passe');
    expect(File::exists(base_path('vendor/laravel-lang')))->toBeFalse();
});

it('V5-4/V5-8/V5-10 : aucune taille en dur, aucun titre tronqué, aucune couleur en dur dans les vues', function () {
    $vues = collect(File::allFiles(resource_path('views')))->map(fn ($f) => $f->getContents())->implode("\n");

    expect($vues)->not->toMatch('/text-\[[0-9.]+rem\]/')
        ->not->toMatch('/#[0-9a-fA-F]{6}\b/')
        ->not->toMatch('/\b(truncate|line-clamp-\d)\b/')
        ->and(File::get(resource_path('css/app.css')))->not->toContain('monospace');
});

it('V5-5 : « OK » de l’historique d’une planifiée est un statut vert (mot + couleur, DA-7 fiche-ligne .ok)', function () {
    ($this->etat)();
    $html = $this->get(route('planifiees.fiche', 'a1b2c3d4e5f6'))->assertOk()->getContent();
    expect($html)->toMatch('/<div class="fiche-ligne"[^>]*>.*?<span class="ok">OK<\/span>/s');
});

it('V5-13 : l’échec de la frise et la ligne « Erreurs 24 h » portent la même heure', function () {
    Livewire::withoutLazyLoading();
    ($this->etat)();
    $client = app(HermesClient::class);
    $journal = collect($client->erreurs()->get('entrees', []))->firstWhere('niveau', 'ERROR');
    $historique = collect($client->planifiee('c3d4e5f6a1b2')->get('historique', []))->firstWhere('livraison', 'failed');

    expect($journal)->not->toBeNull()
        ->and(substr($historique['demarre_a'], 0, 16))->toBe(substr($journal['quand'], 0, 16));
});
