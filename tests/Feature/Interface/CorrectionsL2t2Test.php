<?php

use App\Hermes\HermesClient;
use App\Interface\Temps;
use App\Livewire\Planifiees;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * Lot 02, tour 2 (audits v1 qa + design-critic) : sprite hors flux, barre au-dessus du contenu, titres qui se
 * replient, colonne d'actions de Planifiées, mineurs de Compte, bouton « Reprendre ».
 */

afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    [$this->user, $this->secret] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
});

it('rend le sprite sans taille et le sort du flux, sans display:none (M1)', function () {
    $html = $this->connecteAvecTotp($this->user)->get('/planifiees')->assertOk()->getContent();
    $css = File::get(resource_path('css/app.css'));

    expect($html)->toMatch('/<svg[^>]*class="sprite"[^>]*width="0" height="0"/');
    expect($css)->toMatch('/\.sprite \{[^}]*position: absolute;[^}]*width: 0;[^}]*height: 0;[^}]*overflow: hidden;/')
        ->not->toMatch('/\.sprite \{[^}]*display: none/');
});

it('place la barre (et le menu mobile) au-dessus des surfaces en verre du contenu (M2)', function () {
    expect(File::get(resource_path('css/app.css')))->toMatch('/\.barre \{[^}]*position: relative;[^}]*z-index: 10;/');
});

it('laisse les titres se replier : nowrap levé sur h1 span (M3)', function () {
    expect(File::get(resource_path('css/app.css')))->toContain('h1 span { white-space: normal; }');
});

it('aligne les actions de Planifiées : « Ouvrir » principal sur chaque ligne, actions secondaires distinctes (M4)', function () {
    $html = $this->connecteAvecTotp($this->user)->get('/planifiees')->assertOk()->getContent();

    // 8 tâches dans le jeu de démonstration : un « Ouvrir » principal par ligne, y compris la ligne désactivée.
    expect(substr_count($html, 'class="tache-ouvrir"'))->toBe(8)
        ->and($html)->toContain('aria-label="Ouvrir Surveillance du site (essai)"')
        ->and($html)->not->toContain('la liste n\'offre que');
});

it('ouvre la confirmation « Reprendre » depuis la liste, et rien pour un id inconnu', function () {
    $this->actingAs($this->user);
    session(['hermes.connecte_a' => now()->getTimestamp(), 'hermes.derniere_activite' => now()->getTimestamp(), 'hermes.totp_confirme_a' => now()->getTimestamp()]);

    $page = Livewire::withoutLazyLoading()->test(Planifiees::class)->call('demanderReprise', 'a1b2c3d4e5f6');
    expect($page->get('confirmation')['action'])->toBe('cron.resume')
        ->and($page->get('confirmation')['donnees'])->toBe(['job_id' => 'a1b2c3d4e5f6']);

    $inconnu = Livewire::test(Planifiees::class)->call('demanderReprise', 'inconnu');
    expect($inconnu->get('confirmation'))->toBeNull();
});

it('écrit la date en lettres avec l’année (« depuis le 3 novembre 2026 »)', function () {
    expect(Temps::dateLongue(Carbon::parse('2026-11-03 10:00:00', 'Europe/Paris')))->toBe('3 novembre 2026')
        ->and(Temps::dateLongue(Carbon::parse('2026-10-01 10:00:00', 'Europe/Paris')))->toBe('1er octobre 2026')
        ->and(Temps::dateLongue(null))->toBe('—');
});

it('affiche « Ouvrir le journal » comme les autres liens d’action de Compte', function () {
    $html = $this->connecteAvecTotp($this->user)->get('/compte')->assertOk()->getContent();

    expect($html)->toMatch('/<a class="bouton-texte" href="[^"]*journal[^"]*">Ouvrir le journal<\/a>/')
        ->and($html)->toMatch('/depuis le \d{1,2}(er)? [a-zéû]+ \d{4}/u');
});
