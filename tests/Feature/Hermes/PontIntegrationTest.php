<?php

use App\Hermes\Action;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\PontClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/*
 * Intégration réelle : PontClient (curl sur socket Unix, HMAC) contre le vrai pont Python
 * (pont/hermes_os_pont.py) lancé sur un faux Hermes (pont/tests/fixtures.py) avec un faux binaire `hermes`.
 * Aucun accès à un vrai home Hermes. Ignoré si python3 est absent.
 */

beforeEach(function () {
    if ((new Process(['python3', '--version']))->run() !== 0) {
        $this->markTestSkipped('python3 absent : intégration du pont non jouée.');
    }

    Http::allowStrayRequests();
    $this->dossier = sys_get_temp_dir().'/pont-pest-'.bin2hex(random_bytes(4));
    mkdir($this->dossier);
    // Chemin de socket court (limite de 108 octets des sockets Unix).
    $this->socket = $this->dossier.'/p.sock';
    $this->processus = new Process(['python3', base_path('pont/tests/serveur_de_test.py'), $this->dossier, $this->socket]);
    $this->processus->setInput(new InputStream);
    $this->processus->start();
    $this->processus->waitUntil(fn (string $type, string $sortie) => str_contains($sortie, 'pret'));

    $this->client = new PontClient(app(Factory::class), Cache::store(), $this->socket, 'secret-de-test-du-pont-hermes-os-0123456789abcdef');
});

afterEach(function () {
    if (isset($this->processus)) {
        $this->processus->stop(3);
        (new Process(['rm', '-rf', $this->dossier]))->run();
    }
});

it('lit chaque route du vrai pont par le socket Unix', function () {
    $c = $this->client;

    expect($c->sante()->get('ok'))->toBeTrue()
        ->and($c->gateway()->get('etat'))->toBe('running')
        ->and($c->aDecider()->get('elements'))->toBeArray()
        ->and($c->planifiees()->get('taches'))->toHaveCount(2)
        ->and($c->planifiee('31cd7657668e')->get('sorties'))->toHaveCount(1)
        ->and($c->consommation(7)->get('par_jour'))->toHaveCount(7)
        ->and($c->sessions()->get('sessions'))->toHaveCount(6)
        ->and($c->poles()->get('poles'))->not->toBeEmpty()
        ->and($c->erreurs()->get('comptes.erreurs'))->toBe(2);
});

it('ne laisse sortir aucun secret de la fixture', function () {
    $c = $this->client;
    $tout = json_encode([
        $c->planifiees()->donnees,
        $c->sortie('31cd7657668e', '2026-10-08_08-01-28.md')->donnees, $c->erreurs()->donnees, $c->sessions()->donnees,
    ], JSON_UNESCAPED_UNICODE);

    expect($tout)->not->toContain('APPAT-')
        ->not->toContain('sk-ant-api03')
        ->not->toContain('ghp_1234567890')
        ->not->toContain('AKIAIOSFODNN7EXAMPLE')
        ->toContain('[masqué]');
});

it('ne sert plus les dossiers de mission (page supprimée), liens symboliques compris', function () {
    expect(fn () => $this->client->lire('/v1/missions/2026-10-08-hermes-os/lien-env.md'))
        ->toThrow(fn (PontErreur $e) => expect($e->statut)->toBe(404));
    expect(fn () => $this->client->lire('/v1/missions/lien-vault'))
        ->toThrow(fn (PontErreur $e) => expect($e->statut)->toBe(404));
});

it('exécute une action par le faux binaire hermes, arguments en liste', function () {
    $resultat = $this->client->action(Action::CronLancer, ['job_id' => '31cd7657668e']);

    expect($resultat->ok)->toBeTrue()->and($resultat->sortie)->toContain('[masqué]');
    $appel = json_decode(trim(file_get_contents($this->dossier.'/appels.jsonl')), true);
    expect($appel['argv'])->toBe(['cron', 'run', '31cd7657668e']);
});

it('ne sert plus le kanban ni le terminal (404)', function (string $chemin) {
    expect(fn () => $this->client->lire($chemin))
        ->toThrow(fn (PontErreur $e) => expect($e->statut)->toBe(404));
})->with(['/v1/kanban', '/v1/kanban/dev', '/v1/terminal/sessions', '/v1/units']);

it('rejette une requête signée avec un autre secret', function () {
    $intrus = new PontClient(app(Factory::class), Cache::store(), $this->socket, str_repeat('x', 48));

    $intrus->sante();
})->throws(PontInjoignable::class, 'Signature refusée');
