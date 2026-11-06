<?php

use App\Hermes\HermesClient;

/*
 * Interface (lot 07) : chaque page répond 200 authentifiée (TOTP), 302 vers /connexion sinon, dans les états du
 * client démo ; aucune donnée fictive des maquettes ni « [À fournir » dans le HTML.
 */

function pagesConsole(): array
{
    return [
        'accueil' => '/',
        'planifiées' => '/planifiees',
        'tâche en échec' => '/planifiees/c3d4e5f6a1b2',
        'tâche désactivée' => '/planifiees/f6a1b2c3d4e5',
        'consommation' => '/consommation',
        'consommation 7 j' => '/consommation?periode=7j',
        'sessions' => '/consommation/sessions',
        'pôles' => '/poles',
        'profil' => '/poles/assistant',
        'coffre' => '/vault',
        'compte' => '/compte',
        'journal' => '/compte/journal',
    ];
}

function etatDemo(string $etat): void
{
    config(['hermes-os.pont.demo_etat' => $etat]);
    app()->forgetInstance(HermesClient::class);
}

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
});

it('sert chaque page en 200 avec TOTP, dans chaque état du client démo', function (string $etat) {
    etatDemo($etat);

    foreach (pagesConsole() as $nom => $url) {
        $html = $this->connecteAvecTotp($this->user)->get($url)->assertOk()->getContent();

        expect($html)
            ->not->toContain('[À fournir')
            ->not->toContain('203.0.113.')
            ->not->toContain('198.51.100.')
            ->not->toContain('192.0.2.')
            // Wordmark DA-7 : « hermes-os » en texte, lien vers l'accueil (composants.md § 1).
            ->toContain('<span class="marque-nom">hermes-os</span>');
    }
})->with(['nominal', 'tout_tourne', 'gateway_arrete', 'pont_injoignable']);

it('redirige vers la connexion sans session', function () {
    foreach (pagesConsole() as $url) {
        $this->get($url)->assertRedirect('/connexion');
    }
});

it('refuse une session ouverte sans code TOTP', function () {
    $this->actingAs($this->user)->get('/planifiees')->assertRedirect('/connexion');
});

it('renvoie 404 pour une ressource inconnue du pont', function (string $url) {
    $this->connecteAvecTotp($this->user)->get($url)->assertNotFound();
})->with([
    '/planifiees/000000000000',
    '/poles/inconnu',
]);

it('ne sert plus le kanban ni le terminal (404), même connecté', function (string $url) {
    $this->connecteAvecTotp($this->user)->get($url)->assertNotFound();
})->with(['/kanban', '/terminal']);

it('pose les titres de page attendus', function (string $url, string $titre) {
    $this->connecteAvecTotp($this->user)->get($url)->assertOk()->assertSee('<title>'.$titre.'</title>', false);
})->with([
    ['/', 'Accueil — hermes-os'],
    ['/planifiees', 'Planifiées — hermes-os'],
    ['/poles', 'Pôles — hermes-os'],
]);
