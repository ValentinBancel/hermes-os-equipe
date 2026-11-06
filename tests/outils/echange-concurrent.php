<?php

/*
 * Outil du test de concurrence de l'appairage (tests/Feature/Hermes/PontDistantTest.php, secu M1) : chaque processus
 * démarre la console sur une base SQLite FICHIER partagée, comme en production. Jamais servi (hors de public/).
 *
 *   php tests/outils/echange-concurrent.php preparer                 → migre la base, affiche un code d'appairage
 *   php tests/outils/echange-concurrent.php echanger <code> <depart> → attend l'instant <depart> (microtime),
 *                                                                      échange le code, affiche le statut HTTP
 */

use App\Pont\Appairage;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';

if (($argv[1] ?? '') === 'preparer') {
    $app->make(ConsoleKernel::class)->bootstrap();
    $app->make(ConsoleKernel::class)->call('migrate', ['--force' => true]);
    echo Appairage::afficher(app(Appairage::class)->generer(null)['code']), "\n";
    exit(0);
}

if (($argv[1] ?? '') === 'echanger') {
    $kernel = $app->make(HttpKernel::class);
    $depart = (float) ($argv[3] ?? 0);
    while (microtime(true) < $depart) {
        usleep(100);
    }
    $requete = Request::create('/pont/v1/appairage', 'POST', server: [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1',
    ], content: (string) json_encode(['code' => $argv[2] ?? '', 'nom' => 'concurrent']));
    $reponse = $kernel->handle($requete);
    echo $reponse->getStatusCode(), "\n";
    $kernel->terminate($requete, $reponse);
    exit(0);
}

fwrite(STDERR, "usage : echange-concurrent.php preparer|echanger <code> <depart>\n");
exit(2);
