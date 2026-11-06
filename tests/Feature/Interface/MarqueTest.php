<?php

/*
 * P3 : marque configurable. HERMES_OS_CLIENT vide = logotype seul ; renseigné = « pour <client> » sous le wordmark.
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
});

it('n’affiche aucune mention client par défaut', function () {
    expect(config('hermes-os.client'))->toBe('');

    $this->connecteAvecTotp($this->user)->get('/')->assertOk()->assertDontSee('text-second">pour ', false);
});

it('affiche « pour <client> » quand HERMES_OS_CLIENT est renseigné, échappé', function () {
    config(['hermes-os.client' => 'Atelier <Dupont>']);

    $this->connecteAvecTotp($this->user)->get('/')->assertOk()
        ->assertSee('pour Atelier &lt;Dupont&gt;', false)
        ->assertDontSee('Atelier <Dupont>', false);
});
