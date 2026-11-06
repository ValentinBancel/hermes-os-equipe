<?php

/*
 * Outil du test de bout en bout du pont distant (scripts/e2e-pont-distant.sh) : manipule la console comme le ferait
 * l'interface, sans navigateur. Jamais servi par le serveur web (hors de public/).
 *
 *   php scripts/e2e/console.php code            → code d'appairage (XXXX-XXXX)
 *   php scripts/e2e/console.php etat            → JSON : instance active, nombre de relevés, clés
 *   php scripts/e2e/console.php action <job>    → met « cron.pause » en file, affiche l'id
 *   php scripts/e2e/console.php suivre <id>     → JSON du résultat, ou « attente »
 *   php scripts/e2e/console.php delier          → révoque l'instance
 */

use App\Hermes\Action;
use App\Hermes\DistantClient;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Pont\Appairage;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$commande = $argv[1] ?? '';
$client = new DistantClient(attenteSecondes: 0);

switch ($commande) {
    case 'code':
        echo Appairage::afficher(app(Appairage::class)->generer(null)['code']), "\n";
        break;
    case 'etat':
        $instance = PontInstance::active();
        echo json_encode([
            'instance' => $instance?->only(['nom', 'version', 'profils', 'latence_ms']),
            'releves' => PontReleve::query()->count(),
            'cles' => PontReleve::query()->orderBy('cle')->pluck('cle'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        break;
    case 'action':
        echo $client->action(Action::CronSuspendre, ['job_id' => $argv[2] ?? '', 'acteur' => 'e2e@exemple.invalid'])->attente, "\n";
        break;
    case 'suivre':
        $res = $client->suivre($argv[2] ?? '');
        echo $res === null ? 'attente' : json_encode(['ok' => $res->ok, 'sortie' => $res->sortie, 'erreur' => $res->erreur], JSON_UNESCAPED_UNICODE), "\n";
        break;
    case 'delier':
        echo app(Appairage::class)->delier(null) ? "délié\n" : "rien à délier\n";
        break;
    default:
        fwrite(STDERR, "usage : console.php code|etat|action <job>|suivre <id>|delier\n");
        exit(2);
}
