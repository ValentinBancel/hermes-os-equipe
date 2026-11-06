<?php

use App\Hermes\HermesClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Lot 02 — intégration DA-7 « Épure » (design-system/, DA-VALIDEE.md) et purge de DA-N3 « Taille-douce » :
 * bloc du jour en verre, écrans publics sans marque, CSS sans couleur en dur ni police taille-douce.
 * (Nom de fichier conservé pour l'historique : il vérifie désormais l'absence de Taille-douce.)
 */

afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    $this->etat = function (string $etat = 'nominal') {
        config(['hermes-os.pont.demo_etat' => $etat]);
        app()->forgetInstance(HermesClient::class);

        return $this->connecteAvecTotp($this->user);
    };
});

it('affiche le bloc du jour DA-7 : légende « Situation au », verdict, 3 compteurs, frise et grille de sections', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 14:23:00', 'Europe/Paris'));
    Livewire::withoutLazyLoading();
    $html = ($this->etat)()->get('/')->assertOk()->getContent();

    expect($html)->toContain('class="jour verre"')
        ->toContain('Situation au jeudi 8 octobre')
        ->toContain('class="compteurs"')
        ->toContain('class="frise-maintenant')
        ->toContain('class="grille-sections"');
});

it('n’affiche ni marque ni « hermes » sur les écrans publics (ADR 0004)', function () {
    $html = $this->get('/connexion')->assertOk()->getContent();

    expect($html)->not->toContain('class="marque"')
        ->not->toContain('anglaise')
        ->and(stripos($html, 'hermes'))->toBeFalse();
});

it('a purgé Taille-douce : app.css branche DA-7 sans couleur en dur, aucune police ni classe taille-douce', function () {
    $css = File::get(resource_path('css/app.css'));

    expect($css)->toContain("@import '../../design-system/tokens.css';")
        ->toContain("@import '../../design-system/composants.css' layer(components);")
        ->not->toMatch('/#[0-9a-fA-F]{3,8}\b/')
        ->not->toContain('@font-face');

    expect(File::isDirectory(resource_path('fonts')))->toBeFalse();

    // Pages du lot 02 : aucune classe de l'ancien thème (guichet, guilloché, anglaise, ocre, sérif, signature).
    foreach (['livewire/accueil.blade.php', 'livewire/accueil-chargement.blade.php', 'livewire/planifiees.blade.php',
        'livewire/partials/table-taches.blade.php', 'livewire/compte.blade.php', 'components/coque.blade.php'] as $vue) {
        expect(File::get(resource_path('views/'.$vue)))->not->toMatch('/guichet|guilloch|anglaise|ocre|font-serif|signature/', $vue);
    }
});

it('trace l’histogramme Tokens en classes DA-7 (.histo-h-N), sans style en ligne (CSP)', function () {
    Livewire::withoutLazyLoading();
    $html = ($this->etat)()->get('/')->assertOk()->getContent();
    $main = str($html)->after('<main')->before('</main>')->toString();

    expect($main)->toMatch('/<i class="histo-h-\d{1,3}"/')
        ->not->toContain('style=');
});

it('aligne « maintenant » à droite du trait en fin de journée, sans débordement (qa m1 L4)', function () {
    Livewire::withoutLazyLoading();
    Carbon::setTestNow(Carbon::parse('2026-10-08 22:40:00', 'Europe/Paris'));
    expect(($this->etat)()->get('/')->assertOk()->getContent())->toMatch('/class="frise-maintenant frise-t-\d+ fin"/');

    Carbon::setTestNow(Carbon::parse('2026-10-08 14:23:00', 'Europe/Paris'));
    expect($this->get('/')->getContent())->not->toMatch('/class="frise-maintenant frise-t-\d+ fin"/');
});

it('Compte : ancre « Hermes relié » en tête, bouton « Nouveau compte » ciblable, colonne d’action fixe (L3 m1/m3, L6)', function () {
    Livewire::withoutLazyLoading();
    $html = ($this->etat)()->get('/compte')->assertOk()->getContent();

    expect($html)->toContain('href="#hermes-relie"')
        ->toContain('id="hermes-relie"')
        ->toContain('id="btn-nouveau-compte"')
        ->toContain('class="compte-action"');
});

it('importe composants.css en couche, pour que son reset n’écrase pas les espacements Tailwind (dc m6 L3)', function () {
    expect(File::get(resource_path('css/app.css')))
        ->toContain("@import '../../design-system/composants.css' layer(components);");
});
