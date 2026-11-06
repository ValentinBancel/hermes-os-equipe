<?php

use App\Hermes\Action;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\HermesClient;
use App\Hermes\PontClient;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const SECRET_TEST = 'secret-de-test-du-pont-hermes-os-0123456789abcdef';

function pontClient(?string $secret = SECRET_TEST, int $cache = 4): PontClient
{
    return new PontClient(app(Factory::class), Cache::store(), '/run/hermes-os/pont.sock', $secret, $cache);
}

/**
 * Vérifie la signature comme le pont : HMAC-SHA256 de « horodatage\nMÉTHODE\nchemin?requête\ncorps ».
 */
function signatureValide(Request $requete): bool
{
    $url = parse_url($requete->url());
    $cible = $url['path'].(isset($url['query']) ? '?'.$url['query'] : '');
    $attendue = hash_hmac('sha256', $requete->header('X-Pont-Horodatage')[0]."\n".$requete->method()."\n".$cible."\n".$requete->body(), SECRET_TEST);

    return hash_equals($attendue, $requete->header('X-Pont-Signature')[0] ?? '');
}

it('signe chaque requête (HMAC-SHA256, horodatage courant) et passe par le socket Unix', function () {
    Http::fake(['pont/v1/cron/a1b2c3d4e5f6' => Http::response(['id' => 'a1b2c3d4e5f6', 'sorties' => []])]);

    $lecture = pontClient()->planifiee('a1b2c3d4e5f6');

    expect($lecture->get('id'))->toBe('a1b2c3d4e5f6')->and($lecture->perimee)->toBeFalse();
    Http::assertSent(function (Request $r) {
        return $r->method() === 'GET'
            && $r->url() === 'http://pont/v1/cron/a1b2c3d4e5f6'
            && signatureValide($r)
            && abs((int) $r->header('X-Pont-Horodatage')[0] - time()) <= 1;
    });
});

it('signe aussi la chaîne de requête', function () {
    Http::fake(['pont/v1/consommation*' => Http::response(['total' => ['total' => 3]])]);

    pontClient()->consommation(7);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://pont/v1/consommation?jours=7' && signatureValide($r));
});

it('produit la même signature que le pont Python', function () {
    // Valeur calculée par pont/hermes_os_pont.py (Pont.signer) pour les mêmes entrées.
    expect(PontClient::signer(SECRET_TEST, '1791480000', 'POST', '/v1/actions/cron.run', '{"job_id":"31cd7657668e"}'))
        ->toBe(hash_hmac('sha256', "1791480000\nPOST\n/v1/actions/cron.run\n{\"job_id\":\"31cd7657668e\"}", SECRET_TEST));
});

it('met les lectures en cache quelques secondes', function () {
    Http::fake(['pont/v1/gateway' => Http::sequence()
        ->push(['etat' => 'running'])
        ->push(['etat' => 'stopped'])]);
    $client = pontClient(cache: 4);

    expect($client->gateway()->get('etat'))->toBe('running');
    $this->travel(3)->seconds();
    expect($client->gateway()->get('etat'))->toBe('running');
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    expect($client->gateway()->get('etat'))->toBe('stopped');
    Http::assertSentCount(2);
});

it('borne le cache entre 3 et 5 secondes via la configuration', function () {
    config(['hermes-os.pont.driver' => 'pont', 'hermes-os.pont.secret' => SECRET_TEST, 'hermes-os.pont.cache_secondes' => 60]);
    app()->forgetInstance(HermesClient::class);
    Http::fake(['pont/v1/sante' => Http::sequence()->push(['ok' => true])->push(['ok' => false])]);
    $client = app(HermesClient::class);

    expect($client)->toBeInstanceOf(PontClient::class);
    $client->sante();
    $this->travel(6)->seconds();
    expect($client->sante()->get('ok'))->toBeFalse();
});

it('oublie le cache après une action', function () {
    Http::fake([
        'pont/v1/cron' => Http::sequence()->push(['taches' => [['etat' => 'scheduled']]])->push(['taches' => [['etat' => 'paused']]]),
        'pont/v1/actions/*' => Http::response(['ok' => true, 'action' => 'cron.pause', 'code' => 0, 'sortie' => '', 'erreur' => '']),
    ]);
    $client = pontClient();

    $client->planifiees();
    $client->action(Action::CronSuspendre, ['job_id' => '31cd7657668e']);
    $client->oublierCache();

    expect($client->planifiees()->get('taches.0.etat'))->toBe('paused');
});

it('lève PontInjoignable si le pont ne répond pas et qu\'aucune donnée n\'est connue', function () {
    Http::fake(['pont/*' => Http::failedConnection()]);

    pontClient()->gateway();
})->throws(PontInjoignable::class, 'Pont injoignable.');

it('rend les dernières données connues, marquées périmées, quand le pont tombe', function () {
    Http::fake(['pont/v1/cron' => Http::sequence()
        ->push(['taches' => [['id' => 'a1b2c3d4e5f6']]])
        ->pushFailedConnection()]);
    $client = pontClient();
    $client->planifiees();

    $this->travel(2)->minutes();
    $lecture = $client->planifiees();

    expect($lecture->perimee)->toBeTrue()
        ->and($lecture->raison)->toBe('Pont injoignable.')
        ->and($lecture->ageSecondes())->toBe(120)
        ->and($lecture->get('taches.0.id'))->toBe('a1b2c3d4e5f6');
});

it('traite une signature refusée (401) comme un pont injoignable, sans exposer le secret', function () {
    Http::fake(['pont/*' => Http::response(['erreur' => 'Signature invalide.'], 401)]);

    expect(fn () => pontClient()->sante())->toThrow(PontInjoignable::class, 'Signature refusée par le pont.');
});

it('traite une erreur de lecture du pont (503) comme injoignable', function () {
    Http::fake(['pont/*' => Http::response(['erreur' => 'Source Hermes illisible.'], 503)]);

    pontClient()->sante();
})->throws(PontInjoignable::class);

it('remonte les refus métier du pont (404, 422) avec leur message', function () {
    Http::fake(['pont/*' => Http::response(['erreur' => 'Tâche inconnue.'], 404)]);

    expect(fn () => pontClient()->planifiee('ffffffffffff'))
        ->toThrow(fn (PontErreur $e) => expect([$e->statut, $e->getMessage()])->toBe([404, 'Tâche inconnue.']));
});

it('refuse sans appel réseau un paramètre mal formé', function (Closure $lire) {
    Http::fake();

    expect(fn () => $lire(pontClient()))->toThrow(PontErreur::class);
    Http::assertNothingSent();
})->with([
    'conversation' => [fn (PontClient $c) => $c->conversationMessagerie('../c_1')],
    'tâche' => [fn (PontClient $c) => $c->planifiee('31cd7657668E')],
    'sortie' => [fn (PontClient $c) => $c->sortie('31cd7657668e', '../jobs.json')],
]);

it('n\'appelle pas le pont sans secret configuré', function () {
    Http::fake();

    expect(fn () => pontClient(null)->sante())->toThrow(PontInjoignable::class, 'HERMES_PONT_SECRET');
    expect(fn () => pontClient('court')->sante())->toThrow(PontInjoignable::class);
    Http::assertNothingSent();
});

it('envoie une action en POST JSON signé et rend le résultat de la CLI', function () {
    Http::fake(['pont/v1/actions/messagerie.envoyer' => Http::response([
        'ok' => true, 'action' => 'messagerie.envoyer', 'code' => 0, 'sortie' => 'c_aaaaaaaaaaaaaaaa', 'erreur' => '',
    ])]);

    $resultat = pontClient()->action(Action::MessagerieEnvoyer, ['interlocuteur' => 'assistant', 'texte' => 'Validé ✓']);

    expect($resultat->ok)->toBeTrue()->and($resultat->sortie)->toBe('c_aaaaaaaaaaaaaaaa');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->body() === '{"interlocuteur":"assistant","texte":"Validé ✓"}'
        && $r->hasHeader('Content-Type', 'application/json')
        && signatureValide($r));
});

it('rend un échec d\'exécution (délai dépassé, CLI absente) comme résultat, pas comme panne', function (int $statut) {
    Http::fake(['pont/*' => Http::response(['ok' => false, 'action' => 'cron.run', 'code' => null, 'sortie' => '', 'erreur' => 'Délai de 30 s dépassé.'], $statut)]);

    $resultat = pontClient()->action(Action::CronLancer, ['job_id' => '31cd7657668e']);

    expect($resultat->ok)->toBeFalse()->and($resultat->erreur)->toBe('Délai de 30 s dépassé.');
})->with([200, 503, 504]);

it('remonte un refus de validation du pont sur une action', function () {
    Http::fake(['pont/*' => Http::response(['erreur' => 'Tâche planifiée inconnue.'], 422)]);

    pontClient()->action(Action::CronLancer, ['job_id' => '000000000000']);
})->throws(PontErreur::class, 'Tâche planifiée inconnue.');
