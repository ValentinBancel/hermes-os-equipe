<?php

use App\Hermes\HermesClient;
use App\Livewire\Accueil;
use App\Models\PontReleve;
use App\Pont\Appairage;
use Livewire\Livewire;

/*
 * Coffre et messagerie absents du Hermes relié (pont sans coffre, ou relié sans --messagerie) :
 * l'entrée disparaît de la nav et l'URL renvoie vers l'accueil au lieu d'une 404.
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    config(['hermes-os.pont.driver' => 'distant', 'hermes-os.pont.attente_action_secondes' => 0]);
    app()->forgetInstance(HermesClient::class);
    $this->actingAs($this->user);
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->getTimestamp(),
    ]);
    $code = app(Appairage::class)->generer(null)['code'];
    app(Appairage::class)->echanger($code, ['nom' => 'Hermes Équipe', 'hote' => 'hermes.example', 'version' => '0.24.1', 'profils' => ['dev']]);
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'donnees' => ['ok' => true], 'recu_le' => now()]);
});

it('masque Coffre et Messagerie quand le pont ne les fournit pas', function () {
    PontReleve::query()->create(['cle' => '/v1/vault', 'statut' => 404, 'donnees' => ['erreur' => 'Introuvable.'], 'recu_le' => now()]);

    Livewire::test(Accueil::class)
        ->assertDontSeeHtml('href="'.route('vault').'"')
        ->assertDontSeeHtml('href="'.route('messagerie').'"')
        ->assertSeeHtml('href="'.route('poles').'"');

    $this->get('/vault')->assertRedirect(route('accueil'));
    $this->get('/messagerie')->assertRedirect(route('accueil'));
});

it('affiche Coffre et Messagerie quand le pont les fournit', function () {
    PontReleve::query()->create(['cle' => '/v1/vault', 'statut' => 200, 'donnees' => ['profils' => [], 'elements' => []], 'recu_le' => now()]);
    PontReleve::query()->create(['cle' => '/v1/messagerie', 'statut' => 200, 'donnees' => ['interlocuteurs' => [], 'conversations' => []], 'recu_le' => now()]);

    Livewire::test(Accueil::class)
        ->assertSeeHtml('href="'.route('vault').'"')
        ->assertSeeHtml('href="'.route('messagerie').'"');
});

it('garde les entrées en mode démonstration', function () {
    config(['hermes-os.pont.driver' => 'demo']);
    app()->forgetInstance(HermesClient::class);

    Livewire::test(Accueil::class)
        ->assertSeeHtml('href="'.route('vault').'"')
        ->assertSeeHtml('href="'.route('messagerie').'"');
});
