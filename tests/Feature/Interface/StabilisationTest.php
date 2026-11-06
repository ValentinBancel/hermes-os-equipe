<?php

use App\Hermes\HermesClient;
use App\Hermes\Lecture;
use App\Interface\Frise;
use App\Interface\Markdown;
use App\Interface\Nombres;
use App\Interface\Planification;
use App\Interface\Releve;
use App\Interface\Temps;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * Lot 08 — stabilisation v1 (decisions.md § v2) : un test par point vérifiable côté serveur.
 */

afterEach(fn () => Carbon::setTestNow());

/**
 * Frise construite sur un historique donné (une seule tâche), sans pont.
 *
 * @param  list<array{0: string, 1: string}>  $historique  [heure « H:i:s », statut]
 */
function friseAvec(array $historique, string $maintenant = '2026-10-08 16:00:00', array $tache = []): Frise
{
    $now = CarbonImmutable::parse($maintenant, 'Europe/Paris');
    $hist = array_map(fn (array $h) => [
        'demarre_a' => $now->setTimeFromTimeString($h[0])->toIso8601String(),
        'statut' => $h[1],
        'livraison' => 'delivered',
    ], $historique);

    $client = Mockery::mock(HermesClient::class);
    $client->shouldReceive('planifiee')->andReturn(new Lecture(['historique' => $hist], $now));

    return new Frise(new Releve($client), [[
        'id' => 'aaaaaaaaaaaa', 'planning' => '0 3 * * *', 'actif' => true, 'etat' => 'scheduled',
        'derniere_execution' => $now->setTime(9, 0)->toIso8601String(), ...$tache,
    ]], $now);
}

it('ne regroupe jamais un échec avec des réussites : 10 réussites + 1 échec dans la même fenêtre = 2 marques (D1)', function () {
    $h = [];
    for ($i = 0; $i < 10; $i++) {
        $h[] = ['09:0'.intdiv($i, 3).':'.str_pad((string) ($i * 5), 2, '0', STR_PAD_LEFT), 'completed'];
    }
    $h[] = ['09:02:30', 'error'];

    $frise = friseAvec($h);

    expect($frise->passes)->toHaveCount(2)
        ->and(collect($frise->passes)->firstWhere('echec', false))->toMatchArray(['n' => 10])
        ->and(collect($frise->passes)->firstWhere('echec', true))->toMatchArray(['n' => 1])
        ->and($frise->reussies)->toBe(10)
        ->and($frise->echecs)->toBe(1);
});

it('écarte « ×n » du trait « maintenant » (D1)', function () {
    $frise = friseAvec([['15:58:00', 'completed'], ['15:59:00', 'completed']]);

    // 15:58 est à 2 min du trait (≈ 0,8 px) : l'étiquette passe à gauche du point.
    expect($frise->ancrageEtiquette(958, 2))->toBe('end')
        ->and($frise->ancrageEtiquette(600, 2))->toBe('middle')
        ->and($frise->ancrageEtiquette(961, 2))->toBe('start');
});

it('frise mobile : graduations sans collision avec l’heure courante (D2)', function () {
    $frise = friseAvec([['09:00:00', 'completed'], ['09:30:00', 'error']], '2026-10-08 22:04:00');

    // 22:04 : la graduation 24 h tombe sous l'étiquette courante, elle est masquée ; 12 h est présente.
    expect($frise->graduationsBarres())->toBe([0, 6, 12, 18])
        ->and(friseAvec([], '2026-10-08 03:00:00')->graduationsBarres())->toBe([12, 18, 24])
        ->and(friseAvec([], '2026-10-08 12:00:00')->graduationsBarres())->toBe([0, 6, 18, 24]);
});

it('décale les titres Markdown de deux niveaux (A4)', function () {
    $html = (string) Markdown::rendre("# Titre\n\n## Section\n\n### Sous\n\n#### Bas\n\n##### Très bas");

    expect($html)->toContain('<h3>Titre</h3>')
        ->toContain('<h4>Section</h4>')
        ->toContain('<h5>Sous</h5>')
        ->toContain('<h6>Bas</h6>')
        ->toContain('<h6>Très bas</h6>')
        ->not->toContain('<h1>')
        ->not->toContain('<h2>');
});

it('plafonne l’échelle à 3 × la médiane quand un pic dépasse 8 × la médiane (D8)', function () {
    expect(Nombres::echelle([115_000, 140_000, 180_000, 200_000, 250_000, 295_000, 11_300_000]))
        ->toBe(['echelle' => 600_000.0, 'coupe' => true])
        ->and(Nombres::echelle([100, 200, 300]))->toBe(['echelle' => 300.0, 'coupe' => false]);
});

it('signale le jour hors échelle sur l’accueil et sur la consommation (D8)', function () {
    Livewire::withoutLazyLoading();
    [$user] = $this->compteEnrole();
    $this->connecteAvecTotp($user);

    $accueil = $this->get('/')->assertOk()->getContent();
    $conso = $this->get('/consommation?periode=7j')->assertOk()->getContent();

    // Jeu démo : pic du jour (226 M) contre 7 jours ≤ 30 M → coupé. DA-7 : l'histogramme de l'accueil plafonne la
    // barre à 100 % et le dit dans son infobulle (pas de motif « // » dans le design system) ; la consommation (L6)
    // reprend le même histogramme.
    expect($accueil)->toContain('(hors échelle)')
        ->and($conso)->toContain('(hors échelle)')->not->toContain('barre-coupee');
});

it('dérive la prochaine exécution du cron dans le jeu démo, cohérente avec la frise (D14)', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-08 19:55:00', 'Europe/Paris'));
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);

    $taches = app(HermesClient::class)->planifiees()->donnees['taches'];
    $parId = collect($taches)->keyBy('id');

    expect(Temps::lire($parId['a1b2c3d4e5f6']['prochaine_execution'])->format('Y-m-d H:i'))->toBe('2026-10-09 08:00')
        ->and(Temps::lire($parId['e5f6a1b2c3d4']['prochaine_execution'])->format('Y-m-d H:i'))->toBe('2026-10-08 22:00')
        ->and(Temps::lire($parId['d4e5f6a1b2c3']['prochaine_execution'])->format('Y-m-d H:i'))->toBe('2026-10-08 20:00');

    // Même valeur que le calcul de la frise.
    foreach ($taches as $t) {
        $prevue = Planification::executionsPrevues($t, CarbonImmutable::now('Europe/Paris'), CarbonImmutable::now('Europe/Paris')->addDays(8), 1)[0] ?? null;
        if ($prevue !== null && ! str_starts_with($t['planning'], 'every')) {
            expect(Temps::lire($t['prochaine_execution'])->equalTo($prevue))->toBeTrue($t['nom']);
        }
    }
});

it('n’a qu’une seule zone live sur l’accueil, hors des liens et du h1 (A6)', function () {
    Livewire::withoutLazyLoading();
    [$user] = $this->compteEnrole();
    $html = $this->connecteAvecTotp($user)->get('/')->assertOk()->getContent();
    $main = str($html)->after('<main')->before('</main>')->toString();

    expect(substr_count($main, 'aria-live'))->toBe(0)
        ->and(preg_match_all('/<span class="sr-only" role="status">/', $main))->toBe(1)
        ->and($main)->toMatch("/<span class=\"sr-only\" role=\"status\">\\d+\u{00A0}erreurs? en 24\u{00A0}h · \\d+\u{00A0}décisions? attendues?<\\/span>/u");
});

it('pose le bandeau « pont injoignable » en état d’erreur DA-7 (composants.md, état d’erreur ; remplace D13)', function () {
    Livewire::withoutLazyLoading();
    [$user] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'pont_injoignable']);
    app()->forgetInstance(HermesClient::class);

    $html = $this->connecteAvecTotp($user)->get('/')->assertOk()->getContent();
    preg_match('/<div class="([^"]+)" role="alert">/', $html, $m);

    expect($m[1] ?? '')->toBe('etat-erreur')
        ->and($html)->toContain('Hermes ne répond pas')
        ->not->toContain('ocre')->not->toContain('vermillon');
});

it('déclare le favicon SVG et l’icône Apple dans les layouts, fichiers présents sans lettre (D5)', function () {
    [$user] = $this->compteEnrole();
    $pages = [
        $this->get('/connexion')->getContent(),
        $this->connecteAvecTotp($user)->get('/')->getContent(),
    ];
    foreach ($pages as $html) {
        expect($html)->toContain('<link rel="icon" href="/favicon.svg" type="image/svg+xml">')
            ->toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png">');
    }

    $svg = file_get_contents(public_path('favicon.svg'));
    expect($svg)->not->toContain('<text')->toContain('#1B1A17')->toContain('#B3261E')
        ->and(getimagesize(public_path('apple-touch-icon.png'))[0])->toBe(180)
        ->and(file_exists(public_path('favicon.ico')))->toBeFalse();
});

it('colle les unités dans les libellés de l’accueil (D7)', function () {
    Livewire::withoutLazyLoading();
    [$user] = $this->compteEnrole();
    $html = $this->connecteAvecTotp($user)->get('/')->assertOk()->getContent();

    $main = str($html)->after('<main')->before('</main>')->toString();
    $texte = html_entity_decode(strip_tags($main), encoding: 'UTF-8');

    expect($html)->toContain("depuis 24\u{00A0}h")
        ->toContain('Erreurs 24&nbsp;h')
        ->toContain('0-24&nbsp;h')
        ->and($texte)->not->toMatch('/\d (h|min|j|k|M)\b/u');
});
