<?php

use App\Hermes\HermesClient;
use App\Interface\QuestionAgent;
use App\Livewire\Accueil;
use App\Livewire\Compte;
use App\Livewire\Sessions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * v0.1.1 (vérification finale) : « QUESTION : » brut dans « À décider », compteur « en cours » sans liste,
 * « Sessions ouvertes » et « Session » en double dans Compte.
 */

beforeEach(function () {
    [$this->user] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
    $this->actingAs($this->user);
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->subMinutes(2)->getTimestamp(),
    ]);
});

it('QuestionAgent : la ligne QUESTION peut suivre un préambule, qui passe dans le détail', function () {
    $q = QuestionAgent::depuis("J'ai deux devis pour la salle.\nQUESTION : je réserve la salle la moins chère (1 200 €) ?\nCONTEXTE : l'autre est mieux desservie mais coûte 400 € de plus.\n\nDevis en pièce jointe.");

    expect($q)->toBe([
        'question' => 'je réserve la salle la moins chère (1 200 €) ?',
        'contexte' => "l'autre est mieux desservie mais coûte 400 € de plus.",
        'detail' => "J'ai deux devis pour la salle.\n\nDevis en pièce jointe.",
    ]);
});

it('QuestionAgent : la question s\'arrête à la fin de sa ligne, préfixe en gras compris', function () {
    expect(QuestionAgent::depuis("**QUESTION :** On publie lundi ?\nCONTEXTE : tout est prêt.")['question'])->toBe('On publie lundi ?');
});

it('Accueil : « À décider » affiche la question seule, sans le préfixe « QUESTION : » ni le préambule', function () {
    $html = Livewire::withoutLazyLoading()->test(Accueil::class)->html();

    // Jeu de démonstration : « J'ai deux devis pour la salle.\nQUESTION : je réserve la salle la moins chère (1 200 €) ? … »
    preg_match('/<section id="ad".*?<\/section>/s', $html, $section);
    expect($section[0])->toContain('je réserve la salle la moins chère (1 200 €) ?')
        ->and(preg_replace('/title="[^"]*"/', '', $section[0]))->not->toContain('QUESTION')
        ->not->toContain('deux devis pour la salle');
});

it('Accueil : le compteur « en cours » mène aux sessions actives, même nombre des deux côtés', function () {
    $html = Livewire::withoutLazyLoading()->test(Accueil::class)->html();
    $lien = route('consommation.sessions', ['etat' => 'en-cours']);

    expect($html)->toMatch('/<a class="compteur-lien" href="'.preg_quote(e($lien), '/').'"><b>2<\/b><span>en cours<\/span><\/a>/');

    expect(substr_count(Livewire::test(Sessions::class)->html(), 'wire:key="s-'))->toBe(40);

    $sessions = Livewire::withQueryParams(['etat' => 'en-cours'])->test(Sessions::class)->html();
    expect(substr_count($sessions, 'wire:key="s-'))->toBe(2)
        ->and(substr_count($sessions, 'signe de vie'))->toBe(2)
        ->and($sessions)->toContain('Sessions en cours');

});

it('Compte : une seule ligne par session, « Cet appareil » porte « Se déconnecter »', function () {
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        'id' => 'session-telephone', 'user_id' => $this->user->getKey(), 'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Safari/604.1',
        'payload' => '', 'last_activity' => now()->subHour()->getTimestamp(),
    ]);

    preg_match('/<section class="section" aria-labelledby="t-mc">.*?<\/section>/s', Livewire::test(Compte::class)->html(), $section);
    $html = $section[0];

    expect($html)->not->toContain('<span>Session</span>')
        ->not->toContain('Ouverte sur cet appareil')
        ->and(substr_count($html, 'Sessions ouvertes'))->toBe(1)
        ->and(substr_count($html, 'Se déconnecter'))->toBe(1)
        ->and($html)->toMatch('/Cet appareil<br>.*?<button type="submit" class="bouton-texte">Se déconnecter<\/button>/s')
        ->and($html)->toContain("demanderFermetureSession('session-telephone')");
});
