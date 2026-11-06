<?php

use App\Hermes\HermesClient;
use App\Livewire\Compte;
use App\Livewire\Planifiee;
use App\Models\AuditLog;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
 * Actions depuis l'interface : boîte de confirmation verrouillée, TOTP redemandé après 15 min, journal d'audit,
 * actions coupées quand le pont est injoignable.
 */

beforeEach(function () {
    [$this->user, $this->secret] = $this->compteEnrole();
    config(['hermes-os.pont.demo_etat' => 'nominal']);
    app()->forgetInstance(HermesClient::class);
});

function totpConfirmeIlYa(int $minutes): void
{
    session([
        'hermes.connecte_a' => now()->getTimestamp(),
        'hermes.derniere_activite' => now()->getTimestamp(),
        'hermes.totp_confirme_a' => now()->subMinutes($minutes)->getTimestamp(),
    ]);
}

it('ouvre une confirmation sans rien envoyer, puis exécute et journalise à la validation', function () {
    $this->actingAs($this->user);
    totpConfirmeIlYa(2);

    $page = Livewire::test(Planifiee::class, ['id' => 'c3d4e5f6a1b2'])
        ->call('demanderLancement')
        ->assertSet('etapeCode', false)
        ->assertSee('Lancer « ');

    expect($page->get('confirmation')['action'])->toBe('cron.run')
        ->and(AuditLog::where('event', 'like', 'action.%')->count())->toBe(0);

    $page->call('confirmer')->assertSet('confirmation', null);

    expect($page->get('resultat')['ok'])->toBeTrue()
        ->and(AuditLog::where('event', 'action.cron.run')->where('status', 'ok')->exists())->toBeTrue();
});

it('refuse de modifier le contenu verrouillé de la confirmation depuis le navigateur', function () {
    $this->actingAs($this->user);
    totpConfirmeIlYa(2);

    Livewire::test(Planifiee::class, ['id' => 'c3d4e5f6a1b2'])
        ->call('demanderLancement')
        ->set('confirmation.donnees.job_id', 'e287c4e773d9');
})->throws(CannotUpdateLockedPropertyException::class);

it('redemande le code TOTP si la dernière vérification date de plus de 15 min', function () {
    $this->actingAs($this->user);
    totpConfirmeIlYa(20);

    $page = Livewire::test(Planifiee::class, ['id' => 'c3d4e5f6a1b2'])
        ->call('demanderLancement')
        ->assertSet('etapeCode', true)
        ->set('code', '000000')
        ->call('confirmer')
        ->assertHasErrors('code');

    expect($page->get('confirmation'))->not->toBeNull()
        ->and(AuditLog::where('event', 'action.cron.run')->where('status', 'ok')->exists())->toBeFalse();

    $page->set('code', $this->codeTotp($this->secret))->call('confirmer')->assertHasNoErrors();

    expect($page->get('resultat')['ok'])->toBeTrue()
        ->and(AuditLog::where('event', 'action.cron.run')->where('status', 'ok')->exists())->toBeTrue();
});

it('coupe les actions quand le pont est injoignable', function () {
    config(['hermes-os.pont.demo_etat' => 'pont_injoignable']);
    app()->forgetInstance(HermesClient::class);
    $this->actingAs($this->user);
    totpConfirmeIlYa(2);

    $page = Livewire::test(Planifiee::class, ['id' => 'c3d4e5f6a1b2'])->call('demanderLancement');

    expect($page->get('confirmation'))->toBeNull()
        ->and($page->get('resultat')['message'])->toBe('Actions indisponibles : pont injoignable.');
});

it('régénère les codes de secours seulement après confirmation et TOTP récent', function () {
    $this->actingAs($this->user);
    totpConfirmeIlYa(30);
    $avant = $this->user->fresh()->two_factor_recovery_codes;

    $page = Livewire::test(Compte::class)->call('demanderNouveauxCodes')->assertSet('etapeCode', true);
    expect($this->user->fresh()->two_factor_recovery_codes)->toBe($avant);

    $page->set('code', $this->codeTotp($this->secret))->call('confirmer');

    expect($this->user->fresh()->two_factor_recovery_codes)->not->toBe($avant)
        ->and(AuditLog::where('event', 'totp.codes_regeneres')->exists())->toBeTrue();
});
