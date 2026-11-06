<?php

use App\Hermes\HermesClient;
use App\Livewire\Vault;
use App\Models\AuditLog;
use Livewire\Livewire;

/*
 * Coffre (vault) : liste des métadonnées, ajout / modification (drop + create) / suppression via la confirmation,
 * valeurs secrètes jamais gardées dans la confirmation ni journalisées. Pôles : organigramme Direction → Hermes → managers.
 * Missions : page supprimée.
 */

beforeEach(function () {
    [$this->user, $this->secret] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
    $this->actingAs($this->user);
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->subMinutes(2)->getTimestamp(),
    ]);
});

it('liste les coffres par profil sans valeur secrète', function () {
    $this->get('/vault')->assertOk()
        ->assertSee('Coffre')
        ->assertSee('intranet')
        ->assertSee('vault_d3d000000004')
        ->assertSee('2FA');
});

it('ajoute un identifiant : le secret part au pont mais ni dans la confirmation ni dans le journal', function () {
    $page = Livewire::test(Vault::class)
        ->call('nouveau')
        ->set('label', 'forum pro')
        ->set('origin', 'https://pro.example.test')
        ->set('identifier', 'v@exemple.fr')
        ->set('secret.password', 'mot-de-passe-tres-secret')
        ->call('demanderEnregistrement');

    $confirmation = $page->get('confirmation');
    expect($confirmation['action'])->toBe('vault.create')
        ->and(json_encode($confirmation))->not->toContain('mot-de-passe-tres-secret');

    $page->call('confirmer');
    expect($page->get('resultat')['ok'])->toBeTrue()
        ->and($page->get('secret'))->toBe([])
        ->and($page->get('edition'))->toBeNull();

    $ligne = AuditLog::where('event', 'action.vault.create')->where('status', 'ok')->firstOrFail();
    expect(json_encode($ligne->toArray()))->not->toContain('mot-de-passe-tres-secret')
        ->toContain('password');
});

it('refuse un ajout invalide côté console', function () {
    $page = Livewire::test(Vault::class)
        ->call('nouveau')
        ->set('label', 'x')
        ->set('origin', 'example.test')
        ->set('identifier', 'v@exemple.fr')
        ->call('demanderEnregistrement')
        ->call('confirmer');

    expect($page->get('resultat')['ok'])->toBeFalse();
});

it('modifie un élément (remplacement) et supprime un élément après confirmation', function () {
    $page = Livewire::test(Vault::class)
        ->call('modifier', 'default', 'vault_d3d000000002')
        ->assertSet('label', 'intranet')
        ->assertSet('identifier', 'camille.martin@example.test')
        ->set('label', 'intranet pro')
        ->call('demanderEnregistrement');

    expect($page->get('confirmation')['action'])->toBe('vault.update')
        ->and($page->get('confirmation')['donnees']['handle'])->toBe('vault_d3d000000002');
    $page->call('confirmer');
    expect($page->get('resultat')['ok'])->toBeTrue();

    $page->call('demanderSuppression', 'assistant', 'vault_d3d000000004');
    expect($page->get('confirmation')['action'])->toBe('vault.delete');
    $page->call('confirmer');
    expect($page->get('resultat')['ok'])->toBeTrue();
});

it('refuse un élément inconnu ou pris dans un autre coffre', function () {
    Livewire::test(Vault::class)
        ->call('demanderSuppression', 'default', 'vault_d3d000000004')
        ->assertSet('confirmation', null);
});

it('montre les pôles : direction, Hermes, puis une section par pôle avec son manager', function () {
    $html = $this->get('/poles')->assertOk()->getContent();

    expect($html)->toContain('aria-label="Organigramme"')
        ->and(strpos($html, 'Direction'))->toBeLessThan(strpos($html, 'Orchestrateur'))
        ->and(strpos($html, 'Orchestrateur'))->toBeLessThan(strpos($html, 'Manager'))
        ->and(substr_count($html, '<small>Manager'))->toBeGreaterThan(1)
        ->and($html)->not->toContain('case-org');
});

it('n’a plus de page Missions', function () {
    $this->get('/missions')->assertNotFound();
    $this->get('/')->assertDontSee('/missions');
});
