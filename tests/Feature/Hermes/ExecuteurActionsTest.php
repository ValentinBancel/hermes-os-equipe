<?php

use App\Hermes\Action;
use App\Hermes\DemoClient;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\Exceptions\TotpAReconfirmer;
use App\Hermes\Exceptions\TropDActions;
use App\Hermes\ExecuteurActions;
use App\Hermes\HermesClient;
use App\Hermes\Lecture;
use App\Hermes\ResultatAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Requête avec une session dont le TOTP a été confirmé il y a $minutes minutes (null = jamais).
 */
function requeteAvecTotp(?int $minutes): Request
{
    $requete = Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'Pest']);
    $requete->setLaravelSession(app('session')->driver());
    if ($minutes !== null) {
        $requete->session()->put('hermes.totp_confirme_a', now()->subMinutes($minutes)->getTimestamp());
    }
    app()->instance('request', $requete);

    return $requete;
}

/**
 * Faux client : enregistre les actions reçues, rend le résultat (ou lève l'exception) fourni.
 */
function clientEspion(ResultatAction|Throwable|null $reponse = null): HermesClient
{
    return new class($reponse) extends stdClass implements HermesClient
    {
        /** @var list<array{0: Action, 1: array<string, mixed>}> */
        public array $recues = [];

        public int $cachesOublies = 0;

        /** Statut de la ligne d'audit au moment où le pont est appelé. */
        public ?string $statutAuditPendantAppel = null;

        public function __construct(private ResultatAction|Throwable|null $reponse) {}

        public function pilote(): string
        {
            return 'espion';
        }

        public function lire(string $chemin, array $requete = []): Lecture
        {
            return (new DemoClient)->lire($chemin, $requete);
        }

        public function action(Action $action, array $donnees): ResultatAction
        {
            $this->recues[] = [$action, $donnees];
            $this->statutAuditPendantAppel = AuditLog::latest('id')->value('status');
            if ($this->reponse instanceof Throwable) {
                throw $this->reponse;
            }

            return $this->reponse ?? new ResultatAction($action, true, 0, 'fait');
        }

        public function oublierCache(): void
        {
            $this->cachesOublies++;
        }

        public function sante(): Lecture
        {
            return $this->lire('/v1/sante');
        }

        public function gateway(): Lecture
        {
            return $this->lire('/v1/gateway');
        }

        public function aDecider(): Lecture
        {
            return $this->lire('/v1/a-decider');
        }

        public function planifiees(): Lecture
        {
            return $this->lire('/v1/cron');
        }

        public function planifiee(string $id): Lecture
        {
            return $this->lire("/v1/cron/{$id}");
        }

        public function sortie(string $id, string $nom): Lecture
        {
            return $this->lire("/v1/cron/{$id}/sorties/{$nom}");
        }

        public function consommation(int $jours = 30): Lecture
        {
            return $this->lire('/v1/consommation', ['jours' => $jours]);
        }

        public function sessions(int $limite = 100): Lecture
        {
            return $this->lire('/v1/consommation/sessions');
        }

        public function poles(): Lecture
        {
            return $this->lire('/v1/poles');
        }

        public function erreurs(int $heures = 24): Lecture
        {
            return $this->lire('/v1/erreurs');
        }

        public function vault(): Lecture
        {
            return $this->lire('/v1/vault');
        }

        public function messagerie(): Lecture
        {
            return $this->lire('/v1/messagerie');
        }

        public function conversationMessagerie(string $conversation, int $depuis = 0): Lecture
        {
            return $this->lire('/v1/messagerie/'.$conversation);
        }
    };
}

function executeur(HermesClient $client): ExecuteurActions
{
    app()->instance(HermesClient::class, $client);

    return app(ExecuteurActions::class);
}

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('exécute une action et journalise pending puis ok sur la même ligne', function () {
    $client = clientEspion();

    $resultat = executeur($client)->executer(requeteAvecTotp(3), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']);

    expect($resultat->ok)->toBeTrue()
        ->and($client->recues)->toBe([[Action::CronLancer, ['job_id' => '31cd7657668e', 'acteur' => $this->user->email]]])
        ->and($client->cachesOublies)->toBe(1)
        ->and(AuditLog::count())->toBe(1);

    $ligne = AuditLog::sole();
    expect($ligne->event)->toBe('action.cron.run')
        ->and($ligne->status)->toBe('ok')
        ->and($ligne->user_id)->toBe($this->user->id)
        ->and($ligne->ip_address)->toBe('203.0.113.7')
        ->and($ligne->context)->toBe(['arguments' => ['job_id' => '31cd7657668e'], 'resultat' => ['code' => 0, 'sortie' => 'fait']]);
});

it('écrit la ligne pending avant d\'appeler le pont', function () {
    $client = clientEspion();

    executeur($client)->executer(requeteAvecTotp(1), $this->user, Action::CronSuspendre, ['job_id' => '31cd7657668e']);

    expect($client->statutAuditPendantAppel)->toBe('pending')->and(AuditLog::sole()->status)->toBe('ok');
});

it('journalise failed quand la CLI échoue', function () {
    $client = clientEspion(new ResultatAction(Action::CronLancer, false, 1, '', 'Job introuvable côté Hermes.'));

    $resultat = executeur($client)->executer(requeteAvecTotp(1), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']);

    expect($resultat->ok)->toBeFalse()
        ->and(AuditLog::sole()->status)->toBe('failed')
        ->and(AuditLog::sole()->context['resultat'])->toBe(['code' => 1, 'erreur' => 'Job introuvable côté Hermes.']);
});

it('journalise failed et relaie l\'exception quand le pont est injoignable ou refuse', function (Throwable $erreur, string $message) {
    $client = clientEspion($erreur);

    expect(fn () => executeur($client)->executer(requeteAvecTotp(1), $this->user, Action::CronReprendre, ['job_id' => '31cd7657668e']))
        ->toThrow($erreur::class);

    expect(AuditLog::sole()->status)->toBe('failed')
        ->and(AuditLog::sole()->context['resultat'])->toBe(['erreur' => $message])
        ->and($client->cachesOublies)->toBe(0);
})->with([
    'injoignable' => [new PontInjoignable('Pont injoignable.'), 'Pont injoignable.'],
    'refus métier' => [new PontErreur(422, 'Tâche planifiée inconnue.'), 'Tâche planifiée inconnue.'],
]);

it('exige un TOTP confirmé depuis moins de 15 minutes', function (?int $minutes) {
    $client = clientEspion();

    expect(fn () => executeur($client)->executer(requeteAvecTotp($minutes), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']))
        ->toThrow(TotpAReconfirmer::class);

    expect($client->recues)->toBe([])
        ->and(AuditLog::sole()->status)->toBe('blocked')
        ->and(AuditLog::sole()->context)->toBe(['refus' => 'totp_a_reconfirmer']);
})->with(['15 min' => 15, '2 h' => 120, 'jamais' => null]);

it('accepte un TOTP confirmé il y a 14 minutes', function () {
    $resultat = executeur(clientEspion())->executer(requeteAvecTotp(14), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']);

    expect($resultat->ok)->toBeTrue();
});

it('limite à 20 actions par minute', function () {
    $client = clientEspion();
    $executeur = executeur($client);

    for ($i = 0; $i < 20; $i++) {
        $executeur->executer(requeteAvecTotp(1), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']);
    }

    expect(fn () => $executeur->executer(requeteAvecTotp(1), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']))
        ->toThrow(fn (TropDActions $e) => expect($e->secondesAvantReessai)->toBeGreaterThan(0));
    expect($client->recues)->toHaveCount(20)
        ->and(AuditLog::where('status', 'blocked')->sole()->context)->toBe(['refus' => 'limite_par_minute']);

    $this->travel(61)->seconds();
    expect($executeur->executer(requeteAvecTotp(1), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e'])->ok)->toBeTrue();
});

it('valide les arguments avant tout appel au pont', function (Action $action, array $donnees) {
    $client = clientEspion();

    expect(fn () => executeur($client)->executer(requeteAvecTotp(1), $this->user, $action, $donnees))
        ->toThrow(ValidationException::class);

    expect($client->recues)->toBe([])
        ->and(AuditLog::sole()->status)->toBe('blocked')
        ->and(AuditLog::sole()->context['refus'])->toBe('validation');
})->with([
    'job_id majuscule' => [Action::CronLancer, ['job_id' => '31CD7657668E']],
    'job_id injecté' => [Action::CronLancer, ['job_id' => '31cd7657668e; id']],
    'job_id absent' => [Action::CronSuspendre, []],
    'interlocuteur traversée' => [Action::MessagerieEnvoyer, ['interlocuteur' => '../dev', 'texte' => 'x']],
    'texte absent' => [Action::MessagerieEnvoyer, ['interlocuteur' => 'assistant']],
    'conversation mal formée' => [Action::MessagerieArreter, ['conversation' => '--help']],
    'texte trop long' => [Action::MessagerieEnvoyer, ['interlocuteur' => 'assistant', 'texte' => str_repeat('é', 8001)]],
    'texte avec octet nul' => [Action::MessagerieEnvoyer, ['interlocuteur' => 'assistant', 'texte' => "a\0b"]],
]);

it('accepte un texte de 8 000 caractères et ne transmet que les champs prévus', function () {
    $client = clientEspion();
    $texte = str_repeat('é', 8000);

    executeur($client)->executer(requeteAvecTotp(1), $this->user, Action::MessagerieEnvoyer, [
        'interlocuteur' => 'assistant', 'texte' => $texte, 'auteur' => 'root', 'shell' => 'id', 'acteur' => 'pirate@exemple.fr',
    ]);

    // L3 : l'acteur transmis au pont est toujours le compte connecté, jamais une valeur fournie par le navigateur.
    expect($client->recues[0][1])->toBe(['interlocuteur' => 'assistant', 'texte' => $texte, 'acteur' => $this->user->email])
        ->and(mb_strlen(AuditLog::sole()->context['arguments']['texte']))->toBeLessThanOrEqual(203);
});

it('ne permet aucune autre modification d\'une ligne d\'audit', function () {
    executeur(clientEspion())->executer(requeteAvecTotp(1), $this->user, Action::CronLancer, ['job_id' => '31cd7657668e']);
    $ligne = AuditLog::sole();

    expect(fn () => $ligne->terminer('failed'))->toThrow(LogicException::class)
        ->and(fn () => $ligne->delete())->toThrow(LogicException::class);

    $pending = AuditLog::create(['event' => 'action.cron.run', 'status' => 'pending']);
    expect(fn () => $pending->update(['status' => 'ok', 'event' => 'autre']))->toThrow(LogicException::class)
        ->and(fn () => $pending->update(['status' => 'blocked']))->toThrow(LogicException::class);
});
