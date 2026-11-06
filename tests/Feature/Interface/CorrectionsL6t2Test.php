<?php

use App\Hermes\HermesClient;
use App\Livewire\Vault;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/*
 * L6 tour 2 (audit design-critic v1) : rendus serveur des correctifs. Les mesures géométriques (cibles de 40 px à
 * 390, position de la confirmation et du formulaire du Coffre) se font dans un vrai Chromium : scripts/mesure-cibles.sh.
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

it('Coffre (M2) : la confirmation de suppression remplace la ligne visée, bouton final rouge plein nommé', function () {
    $page = Livewire::test(Vault::class)->call('demanderSuppression', 'assistant', 'vault_d3d000000004');
    $html = $page->html();

    // Une seule confirmation, à la place de la ligne visée (la ligne n'est plus rendue, ses voisines oui).
    expect(substr_count($html, 'confirmation-ligne'))->toBe(1)
        ->and($html)->not->toContain('wire:key="e-vault_d3d000000004"')
        ->and($html)->toContain('wire:key="c-vault_d3d000000004"');

    $libelle = 'adresse de facturation'; // jeu de démonstration (app/Hermes/Demo/JeuDeDemonstration.php)
    expect($html)->toMatch('/<button type="submit" class="bouton-danger bouton-danger-plein"[^>]*>\s*Supprimer « '.preg_quote(e($libelle), '/').' »\s*<\/button>/')
        ->toMatch('/<button type="button" class="bouton-texte" wire:click="fermerConfirmation" data-annuler>Annuler<\/button>/');
});

it('Coffre (M2) : le formulaire « Modifier » s\'ouvre sous la ligne cliquée, sa confirmation juste après', function () {
    $page = Livewire::test(Vault::class)->call('modifier', 'assistant', 'vault_d3d000000004');
    $html = $page->html();

    $ligne = strpos($html, 'wire:key="e-vault_d3d000000004"');
    $form = strpos($html, 'formulaire-ligne');
    expect($ligne)->not->toBeFalse()->and($form)->toBeGreaterThan($ligne);
    // Rien d'autre (autre ligne) entre la ligne et son formulaire.
    expect(substr(substr($html, $ligne, $form - $ligne), 20))->not->toContain('wire:key="e-');

    $page->set('label', 'intranet bis')->call('demanderEnregistrement');
    $html = $page->html();
    expect(strpos($html, 'role="dialog"'))->toBeGreaterThan(strpos($html, 'formulaire-ligne'))
        ->and(substr_count($html, 'role="dialog"'))->toBe(1);
});

it('Coffre : la confirmation d\'ajout s\'ouvre sous le formulaire, en haut de page', function () {
    $html = Livewire::test(Vault::class)->call('nouveau')
        ->set('label', 'forum pro')->set('origin', 'https://pro.example.test')->set('identifier', 'v@exemple.fr')
        ->set('secret.password', 'x-secret-x')->call('demanderEnregistrement')->html();

    expect(strpos($html, 'role="dialog"'))->toBeGreaterThan(strpos($html, 'id="v-form"'))
        ->and(strpos($html, 'role="dialog"'))->toBeLessThan(strpos($html, 'wire:key="g-'));
});

it('Planifiées (m5) : une ligne en pause propose « Reprendre » quand le pont répond, rien quand il est figé', function () {
    $tache = ['id' => 'a1b2c3d4e5f6', 'nom' => 'Veille', 'planning' => '0 8 * * *', 'actif' => false, 'etat' => 'paused',
        'dernier_statut' => 'ok', 'derniere_execution' => null, 'prochaine_execution' => null];

    $actif = view('livewire.partials.table-taches', ['taches' => [$tache], 'groupe' => 'pause', 'fige' => false])->render();
    expect($actif)->toContain('aria-label="Reprendre Veille">Reprendre</button>')
        ->and($actif)->not->toContain('>Lancer</button>');

    $fige = view('livewire.partials.table-taches', ['taches' => [$tache], 'groupe' => 'pause', 'fige' => true])->render();
    expect($fige)->not->toContain('Reprendre</button>')->and($fige)->toContain('aria-label="Ouvrir Veille"');
});

it('Compte (m3) : « 1 restant », « 3 restants », « aucun »', function () {
    $vue = fn (int $n) => preg_replace('/\s+/', ' ', Blade::render(
        "{{ \$codes === 0 ? 'aucun' : \\App\\Interface\\Nombres::accord(\$codes, 'restant', 'restants') }}", ['codes' => $n]));
    expect($vue(1))->toBe("1\u{00A0}restant")->and($vue(3))->toBe("3\u{00A0}restants")->and($vue(0))->toBe('aucun');
    expect(File::get(resource_path('views/livewire/compte.blade.php')))
        ->toContain("{{ \$codes === 0 ? 'aucun' : \\App\\Interface\\Nombres::accord(\$codes, 'restant', 'restants') }}");
});

it('Fiche planifiée (m4) : segments de méta insécables, « livraison : origin »', function () {
    $this->get(route('planifiees.fiche', 'c3d4e5f6a1b2'))->assertOk()
        ->assertSee('<span class="whitespace-nowrap">livraison : origin</span>', false)
        ->assertSee('<span class="whitespace-nowrap">c3d4e5f6a1b2</span>', false);
});

it('Écrans imposés (m1) : marque décollée de l\'arc sous 760 px ; spécimen aligné sur « Nouveau compte » (m6)', function () {
    expect(File::get(resource_path('views/components/auth-ecran.blade.php')))->toContain('<p class="marque max-[760px]:mt-[var(--espace-3)]">')
        ->and(File::get(base_path('design-system/specimen.html')))->not->toContain('>Inviter<')->toContain('>Nouveau compte</a>');
});

it('M1 : règle DS des cibles tactiles de 40 px sous 760 px, sans nouveau jeton', function () {
    $css = File::get(base_path('design-system/composants.css'));
    expect($css)->toContain('.bouton, .bouton-texte, .bouton-danger { min-height: var(--espace-10); display: inline-flex; align-items: center; justify-content: center; }')
        ->toContain('padding-block: calc((var(--espace-10) - 1lh) / 2); margin-block: calc((var(--espace-10) - 1lh) / -2);');
});
