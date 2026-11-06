<?php

use App\Hermes\Demo\JeuDeDemonstration;
use App\Hermes\HermesClient;
use App\Livewire\Messagerie;
use App\Models\AuditLog;
use Livewire\Livewire;

/*
 * Messagerie avec les managers (ADR 0008, lot 13) : liste, fil, envoi sans confirmation ni TOTP, journal.
 */

beforeEach(function () {
    [$this->user, $this->secret] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->subHours(3)->getTimestamp(),
    ]);
});

it('affiche les interlocuteurs et le fil de la conversation', function () {
    $this->actingAs($this->user);

    Livewire::test(Messagerie::class, ['interlocuteur' => 'assistant', 'conversation' => 'c_d3d0000000000001'])
        ->assertSee('Interlocuteurs')
        ->assertSee('Préparation du séminaire')
        ->assertSee('Où en est la préparation du séminaire ?')
        ->assertSeeHtml('<strong>un point à trancher</strong>')
        ->assertSee('lit : ');
});

it('répond 404 pour un interlocuteur inconnu ou une conversation d\'un autre interlocuteur', function () {
    $this->actingAs($this->user);

    $this->get('/messagerie/inconnu')->assertNotFound();
    $this->get('/messagerie/recherche/c_d3d0000000000001')->assertNotFound();
    $this->get('/messagerie/assistant/pas-un-id')->assertNotFound();
});

it('envoie sans confirmation ni TOTP récent et journalise', function () {
    $this->actingAs($this->user);

    Livewire::test(Messagerie::class, ['interlocuteur' => 'assistant', 'conversation' => 'c_d3d0000000000001'])
        ->set('texte', 'Merci, je valide.')
        ->call('envoyer')
        ->assertSet('texte', '')
        ->assertSet('erreurMessagerie', '');

    expect(AuditLog::where('event', 'action.messagerie.envoyer')->where('status', 'ok')->exists())->toBeTrue();
});

it('crée une conversation et redirige vers elle', function () {
    $this->actingAs($this->user);

    Livewire::test(Messagerie::class, ['interlocuteur' => 'assistant'])
        ->set('texte', 'Nouveau sujet')
        ->call('envoyer')
        ->assertRedirect(route('messagerie.conversation', ['assistant', JeuDeDemonstration::conversationNouvelle('assistant')]));
});

it('démo : envoyer un premier message puis suivre la redirection donne 200 avec le message', function () {
    $this->actingAs($this->user);

    $envoi = Livewire::test(Messagerie::class, ['interlocuteur' => 'redaction'])->set('texte', 'Bonjour')->call('envoyer');
    $this->get($envoi->effects['redirect'])->assertOk()->assertSee('Message envoyé en mode démonstration');

    foreach (['hermes', 'assistant', 'recherche', 'redaction'] as $interlocuteur) {
        $this->get(route('messagerie.conversation', [$interlocuteur, JeuDeDemonstration::conversationNouvelle($interlocuteur)]))
            ->assertOk()
            ->assertSee('Message envoyé en mode démonstration');
    }
    // Les conversations « nouvelles » restent hors des listes.
    expect(collect(app(HermesClient::class)->messagerie()->donnees['conversations'])->pluck('titre'))->not->toContain('Nouvelle conversation');
});

it('refuse un texte vide ou trop long sans rien envoyer', function () {
    $this->actingAs($this->user);

    $page = Livewire::test(Messagerie::class, ['interlocuteur' => 'assistant'])
        ->set('texte', '   ')->call('envoyer')
        ->set('texte', str_repeat('a', 8001))->call('envoyer');

    expect($page->get('erreurMessagerie'))->not->toBe('');
});

it('verrouille l\'interlocuteur et la conversation côté navigateur', function () {
    $this->actingAs($this->user);

    Livewire::test(Messagerie::class, ['interlocuteur' => 'assistant'])
        ->set('interlocuteur', 'recherche');
})->throws(Exception::class, 'Cannot update locked property');

it('arrête et archive', function () {
    $this->actingAs($this->user);

    Livewire::test(Messagerie::class, ['interlocuteur' => 'recherche', 'conversation' => JeuDeDemonstration::CONVERSATION_DEMO])
        ->assertSee('en train de répondre')
        ->call('arreter')
        ->assertSet('erreurMessagerie', '')
        ->call('archiver', JeuDeDemonstration::CONVERSATION_DEMO)
        ->assertRedirect(route('messagerie.interlocuteur', 'recherche'));
});

it('masque la messagerie quand l\'interrupteur est coupé', function () {
    config(['hermes-os.messagerie' => false]);
    $this->actingAs($this->user);

    $this->get('/')->assertOk()->assertDontSee('/messagerie');
});

it('limite les interlocuteurs de la démo à la liste fermée du pont', function () {
    $ids = array_column(app(HermesClient::class)->messagerie()->donnees['interlocuteurs'] ?? [], 'id');

    expect($ids)->toBe(['hermes', 'assistant', 'recherche', 'redaction']);
});

it('sert des étapes en cours sous forme de chaînes, comme le pont réel (contrat)', function () {
    $d = app(HermesClient::class)->conversationMessagerie(JeuDeDemonstration::CONVERSATION_DEMO)->donnees;
    foreach ($d['etapes_en_cours'] as $etape) {
        expect($etape)->toBeString();
    }
    expect(json_decode(file_get_contents(base_path('pont/contrat.json')), true)['types']['conversation_messagerie']['etapes_en_cours'])->toContain('chaînes');
});
