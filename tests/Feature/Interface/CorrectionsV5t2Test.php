<?php

use App\Hermes\HermesClient;
use Illuminate\Support\Facades\File;

/*
 * Lot 12b — finitions visuelles v5 tour 2 (decisions.md § « v5 — tour 2/3 », V5t2-1 à V5t2-7).
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    $this->etat = function (string $etat = 'nominal') {
        config(['hermes-os.pont.demo_etat' => $etat]);
        app()->forgetInstance(HermesClient::class);

        return $this->connecteAvecTotp($this->user);
    };
});

it('V5t2-2 (DA-7) : les 3 champs de code TOTP partagent le même champ (Outfit, aucune police taille-douce)', function () {
    foreach (['auth/code.blade.php', 'auth/enrolement.blade.php', 'components/confirmation.blade.php'] as $vue) {
        expect(File::get(resource_path('views/'.$vue)))
            ->toContain('x-data="codeTotp"')
            ->toContain('inputmode="numeric"')
            ->toContain('autocomplete="one-time-code"')
            ->not->toContain('font-serif')
            ->not->toContain('tracking-code');
    }
});

it('V5t2-5 / V5t2-7 (DA-7) : point d’échec de la frise jamais grisé en données figées', function () {
    $css = File::get(resource_path('css/app.css'));
    expect($css)->toContain('.fige .frise-point:not(.echec)');

    $html = ($this->etat)('pont_injoignable')->get('/')->assertOk()->getContent();
    expect($html)->toContain('class="fige"');
});

it('V5t2-6 : aucune valeur arbitraire tracking/leading/text dans les vues', function () {
    $vues = collect(File::allFiles(resource_path('views')))->map(fn ($f) => $f->getContents())->implode("\n");
    expect(preg_match_all('/(tracking|leading|text)-\[[^]]+\]/', $vues))->toBe(0);
});
