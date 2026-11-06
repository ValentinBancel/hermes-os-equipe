<?php

use App\Hermes\Action;
use App\Hermes\DemoClient;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\HermesClient;

/*
 * Le client de démonstration est le pilote par défaut (clone neuf, CI) et rend exactement
 * les mêmes formes que le pont réel : pont/contrat.json, vérifié aussi côté Python
 * (pont/tests/test_contrat.py) sur le vrai code du pont.
 */

/**
 * Objets désignés par un chemin du contrat (« », « x[] », « a.b », « x[].y »).
 *
 * @param  array<string, mixed>  $donnees
 * @return list<array<string, mixed>>
 */
function noeudsDuContrat(array $donnees, string $chemin): array
{
    $courants = [$donnees];
    foreach (array_filter(explode('.', $chemin), 'strlen') as $partie) {
        $liste = str_ends_with($partie, '[]');
        $cle = $liste ? substr($partie, 0, -2) : $partie;
        $suivants = [];
        foreach ($courants as $c) {
            $v = $c[$cle];
            array_push($suivants, ...($liste ? $v : [$v]));
        }
        $courants = $suivants;
    }

    return $courants;
}

function contrat(): array
{
    return json_decode(file_get_contents(base_path('pont/contrat.json')), true, flags: JSON_THROW_ON_ERROR);
}

it('utilise le pilote démo par défaut, sans configuration', function () {
    expect(config('hermes-os.pont.driver'))->toBe('demo')
        ->and(app(HermesClient::class))->toBeInstanceOf(DemoClient::class)
        ->and(app(HermesClient::class)->pilote())->toBe('demo');
});

it('refuse un pilote inconnu', function () {
    config(['hermes-os.pont.driver' => 'fichiers']);
    app()->forgetInstance(HermesClient::class);

    app(HermesClient::class);
})->throws(InvalidArgumentException::class);

it('respecte le contrat du pont sur chaque route', function (string $route, Closure $lire) {
    $contrat = contrat();
    $lecture = $lire(new DemoClient);

    expect($lecture->perimee)->toBeFalse();

    foreach ($contrat['routes'][$route] as $chemin => $cles) {
        $attendues = $cles;
        $objets = noeudsDuContrat($lecture->donnees, $chemin);

        expect($objets)->not->toBeEmpty("{$route} : « {$chemin} » vide, contrat non vérifié");
        foreach ($objets as $objet) {
            $reelles = array_keys($objet);
            sort($reelles);
            sort($attendues);
            expect($reelles)->toBe($attendues, "{$route} : « {$chemin} »");
        }
    }
})->with([
    'sante' => ['sante', fn (DemoClient $c) => $c->sante()],
    'gateway' => ['gateway', fn (DemoClient $c) => $c->gateway()],
    'a_decider' => ['a_decider', fn (DemoClient $c) => $c->aDecider()],
    'planifiees' => ['planifiees', fn (DemoClient $c) => $c->planifiees()],
    'planifiee' => ['planifiee', fn (DemoClient $c) => $c->planifiee('a1b2c3d4e5f6')],
    'sortie' => ['sortie', function (DemoClient $c) {
        return $c->sortie('a1b2c3d4e5f6', $c->planifiee('a1b2c3d4e5f6')->get('sorties.0.nom'));
    }],
    'consommation' => ['consommation', fn (DemoClient $c) => $c->consommation(7)],
    'sessions' => ['sessions', fn (DemoClient $c) => $c->sessions()],
    'poles' => ['poles', fn (DemoClient $c) => $c->poles()],
    'erreurs' => ['erreurs', fn (DemoClient $c) => $c->erreurs()],
    'messagerie' => ['messagerie', fn (DemoClient $c) => $c->messagerie()],
    'conversation_messagerie' => ['conversation_messagerie', fn (DemoClient $c) => $c->conversationMessagerie('c_d3d0000000000001')],
    'vault' => ['vault', fn (DemoClient $c) => $c->vault()],
]);

it('couvre toutes les routes du contrat', function () {
    expect(array_keys(contrat()['routes']))->toHaveCount(13);
});

it('rend un jeu neutre et vraisemblable (équipe type, pas de lorem ipsum)', function () {
    $c = new DemoClient;

    expect($c->gateway()->get('profils_servis'))->toBe(['default', 'assistant', 'recherche', 'veille', 'redaction'])
        ->and($c->planifiees()->get('taches'))->toHaveCount(8)
        ->and(collect($c->planifiees()->get('taches'))->firstWhere('id', 'c3d4e5f6a1b2')['dernier_statut'])->toBe('delivery_failed')
        ->and(collect($c->poles()->get('poles'))->firstWhere('pole', 'etudes'))->toMatchArray([
            'lead' => 'recherche', 'executants' => ['veille'], 'auditeurs' => [],
        ])
        ->and($c->consommation(30)->get('total.cout_reel_usd'))->toBe(0.0)
        ->and($c->consommation(7)->get('par_jour'))->toHaveCount(7)
        ->and(array_column($c->aDecider()->get('elements'), 'type'))->toBe(['question', 'approbation'])
        ->and(collect($c->sessions()->get('sessions'))->whereNull('termine_a'))->toHaveCount(2);
});

it('n\'a plus de session active quand le gateway est arrêté', function () {
    expect(collect((new DemoClient('gateway_arrete'))->sessions()->get('sessions'))->whereNull('termine_a'))->toBeEmpty();
});

it('simule le gateway arrêté', function () {
    $c = new DemoClient('gateway_arrete');

    expect($c->gateway()->get('etat'))->toBe('stopped')
        ->and($c->gateway()->get('processus_actif'))->toBeFalse()
        ->and($c->erreurs()->get('comptes.erreurs'))->toBe(2);
});

it('simule le pont injoignable : données périmées de 2 minutes, actions refusées', function () {
    $c = new DemoClient('pont_injoignable');
    $lecture = $c->planifiees();

    expect($lecture->perimee)->toBeTrue()
        ->and($lecture->ageSecondes())->toBeGreaterThanOrEqual(119)
        ->and($lecture->get('taches'))->not->toBeEmpty();

    $c->action(Action::CronLancer, ['job_id' => 'a1b2c3d4e5f6']);
})->throws(PontInjoignable::class);

it('choisit l\'état de démonstration par la configuration', function () {
    config(['hermes-os.pont.demo_etat' => 'gateway_arrete']);
    app()->forgetInstance(HermesClient::class);

    expect(app(HermesClient::class)->gateway()->get('etat'))->toBe('stopped');
});

it('répond 404 comme le pont pour une ressource inconnue', function (Closure $lire) {
    expect(fn () => $lire(new DemoClient))->toThrow(fn (PontErreur $e) => expect($e->introuvable())->toBeTrue());
})->with([
    'tâche' => [fn (DemoClient $c) => $c->planifiee('000000000000')],
    'route' => [fn (DemoClient $c) => $c->lire('/v1/inconnue')],
    'missions retirées' => [fn (DemoClient $c) => $c->lire('/v1/missions')],
    'kanban retiré' => [fn (DemoClient $c) => $c->lire('/v1/kanban')],
    'terminal retiré' => [fn (DemoClient $c) => $c->lire('/v1/terminal/sessions')],
]);

it('accepte une action valide sans rien envoyer à Hermes', function () {
    $resultat = (new DemoClient)->action(Action::CronLancer, ['job_id' => 'a1b2c3d4e5f6']);

    expect($resultat->ok)->toBeTrue()
        ->and($resultat->code)->toBe(0)
        ->and($resultat->sortie)->toContain('démonstration');
});

it('refuse les mêmes actions que le pont (422)', function (Action $action, array $donnees) {
    expect(fn () => (new DemoClient)->action($action, $donnees))
        ->toThrow(fn (PontErreur $e) => expect($e->statut)->toBe(422));
})->with([
    'tâche inconnue' => [Action::CronLancer, ['job_id' => '000000000000']],
]);
