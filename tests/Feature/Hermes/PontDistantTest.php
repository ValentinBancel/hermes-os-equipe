<?php

use App\Hermes\Action;
use App\Hermes\DistantClient;
use App\Hermes\Exceptions\PontInjoignable;
use App\Http\Middleware\VerifierSignaturePont;
use App\Models\AuditLog;
use App\Models\PontAction;
use App\Models\PontCode;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Pont\Appairage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/*
 * L4 — pont distant : appairage (R4), signature HMAC + anti-rejeu (R5), secret chiffré (R6),
 * relevés (R7), file d'actions (R8), pilote distant (R9), API (R10), installeur (R12).
 */

function relier(): array
{
    $code = app(Appairage::class)->generer(null)['code'];

    return test()->postJson('/pont/v1/appairage', [
        'code' => Appairage::afficher($code), 'nom' => 'Hermes test', 'hote' => 'h.example', 'version' => '0.24', 'profils' => ['dev', 'cto'],
    ])->assertCreated()->json();
}

function signe(string $methode, string $cible, array $identite, ?array $corps = null, ?int $horodatage = null, ?string $nonce = null): array
{
    $brut = $corps === null ? '' : json_encode($corps);
    $horodatage ??= time();
    $nonce ??= bin2hex(random_bytes(16));

    return [
        'X-Hermes-Instance' => $identite['instance_id'],
        'X-Hermes-Horodatage' => (string) $horodatage,
        'X-Hermes-Nonce' => $nonce,
        'X-Hermes-Signature' => VerifierSignaturePont::signer($identite['secret'], $methode, $cible, (string) $horodatage, $nonce, $brut),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

function envoyer(string $methode, string $cible, array $identite, ?array $corps = null, array $entetes = [])
{
    $entetes = [...signe($methode, $cible, $identite, $corps), ...$entetes];
    $serveur = collect($entetes)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();
    $serveur['CONTENT_TYPE'] = 'application/json';

    return test()->call($methode, $cible, [], [], [], $serveur, $corps === null ? '' : json_encode($corps));
}

test('l’appairage échange un code valide contre une identité, une seule fois', function () {
    $code = app(Appairage::class)->generer(null)['code'];
    expect($code)->toMatch('/^['.Appairage::ALPHABET.']{8}$/');
    expect(PontCode::query()->first()->code_hash)->toBe(hash('sha256', $code));

    $r = $this->postJson('/pont/v1/appairage', ['code' => strtolower(Appairage::afficher($code)), 'nom' => 'X'])->assertCreated();
    expect($r->json('instance_id'))->toBeString()->and(strlen($r->json('secret')))->toBeGreaterThanOrEqual(43);

    $this->postJson('/pont/v1/appairage', ['code' => $code])->assertStatus(422);
    expect(AuditLog::query()->where('event', 'pont.relie')->count())->toBe(1);
});

test('le secret est chiffré en base (R6)', function () {
    $id = relier();
    $brut = DB::table('pont_instances')->where('instance_id', $id['instance_id'])->value('secret');

    expect($brut)->not->toContain($id['secret'])
        ->and(PontInstance::active()->secret)->toBe($id['secret'])
        ->and(PontInstance::active()->toArray())->not->toHaveKey('secret');
});

test('5 essais faux invalident le code ; un code expiré est refusé', function () {
    $code = app(Appairage::class)->generer(null)['code'];
    foreach (range(1, 5) as $_) {
        $this->postJson('/pont/v1/appairage', ['code' => 'ZZZZZZZZ'])->assertStatus(422);
    }
    $this->postJson('/pont/v1/appairage', ['code' => $code])->assertStatus(422);

    $code = app(Appairage::class)->generer(null)['code'];
    $this->travel(11)->minutes();
    $this->postJson('/pont/v1/appairage', ['code' => $code])->assertStatus(422);
});

test('relier un second Hermes révoque le premier', function () {
    $premier = relier();
    $second = relier();

    expect(PontInstance::query()->active()->count())->toBe(1)
        ->and(PontInstance::active()->instance_id)->toBe($second['instance_id']);
    envoyer('POST', '/pont/v1/releves', $premier, ['releves' => []])->assertStatus(401);
    envoyer('POST', '/pont/v1/releves', $second, ['releves' => []])->assertNoContent();
});

test('la signature protège les relevés : mauvaise signature, horodatage hors fenêtre et rejeu → 401', function () {
    $id = relier();
    $corps = ['releves' => ['/v1/sante' => ['statut' => 200, 'donnees' => ['ok' => true]]], 'latence_ms' => 38];

    $faux = [...$id, 'secret' => Str::random(43)];
    envoyer('POST', '/pont/v1/releves', $faux, $corps)->assertStatus(401);

    $vieux = signe('POST', '/pont/v1/releves', $id, $corps, time() - 400);
    $this->call('POST', '/pont/v1/releves', [], [], [], collect($vieux)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], json_encode($corps))
        ->assertStatus(401);

    $entetes = signe('POST', '/pont/v1/releves', $id, $corps);
    $serveur = collect($entetes)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'];
    $this->call('POST', '/pont/v1/releves', [], [], [], $serveur, json_encode($corps))->assertNoContent();
    $this->call('POST', '/pont/v1/releves', [], [], [], $serveur, json_encode($corps))->assertStatus(401);

    expect(PontReleve::query()->where('cle', '/v1/sante')->first()->donnees)->toBe(['ok' => true])
        ->and(PontInstance::active()->latence_ms)->toBe(38);
});

test('les relevés hors liste de clés sont ignorés et un corps trop gros est refusé', function () {
    $id = relier();
    envoyer('POST', '/pont/v1/releves', $id, ['releves' => [
        '/etc/passwd' => ['statut' => 200, 'donnees' => []],
        '/v1/../x' => ['statut' => 200, 'donnees' => []],
        '/v1/cron' => ['statut' => 200, 'donnees' => ['taches' => []]],
    ]])->assertNoContent();

    expect(PontReleve::query()->pluck('cle')->all())->toBe(['/v1/cron']);

    envoyer('POST', '/pont/v1/releves', $id, ['releves' => [], 'bourrage' => str_repeat('a', 2 * 1024 * 1024)])->assertStatus(413);
});

test('le pilote distant lit le dernier relevé et le marque périmé après 60 s', function () {
    relier();
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'donnees' => ['ok' => true], 'recu_le' => now()]);
    $client = new DistantClient(attenteSecondes: 0);

    expect($client->sante()->donnees)->toBe(['ok' => true])->and($client->sante()->perimee)->toBeFalse();

    $this->travel(61)->seconds();
    expect($client->sante()->perimee)->toBeTrue();
});

test('sans Hermes relié, le pilote distant est injoignable', function () {
    (new DistantClient(attenteSecondes: 0))->sante();
})->throws(PontInjoignable::class);

test('une action passe par la file : récupérée par le pont, résultat renvoyé, puis lue par la console', function () {
    $id = relier();
    $client = new DistantClient(attenteSecondes: 0);

    $res = $client->action(Action::CronSuspendre, ['job_id' => 'abcdef123456', 'acteur' => 'a@b.fr']);
    expect($res->attente)->toBeString();
    expect(PontAction::query()->find($res->attente)->acteur)->toBe('a@b.fr');

    $lot = envoyer('GET', '/pont/v1/actions?attente=0', $id)->assertOk()->json('actions');
    expect($lot)->toHaveCount(1)->and($lot[0]['action'])->toBe('cron.pause')->and($lot[0]['donnees'])->toBe(['job_id' => 'abcdef123456']);
    envoyer('GET', '/pont/v1/actions?attente=0', $id)->assertNoContent();

    expect($client->suivre($res->attente))->toBeNull();

    envoyer('POST', "/pont/v1/actions/{$res->attente}/resultat", $id, ['ok' => true, 'code' => 200, 'donnees' => ['ok' => true, 'code' => 0, 'sortie' => 'en pause']])
        ->assertNoContent();
    envoyer('POST', "/pont/v1/actions/{$res->attente}/resultat", $id, ['ok' => true])->assertNotFound();

    $fin = $client->suivre($res->attente);
    expect($fin->ok)->toBeTrue()->and($fin->sortie)->toBe('en pause');
});

test('une action non récupérée expire après 90 s', function () {
    relier();
    $client = new DistantClient(attenteSecondes: 0);
    $res = $client->action(Action::CronSuspendre, ['job_id' => 'abcdef123456']);

    $this->travel(91)->seconds();
    $fin = $client->suivre($res->attente);

    expect($fin->ok)->toBeFalse()->and($fin->erreur)->toContain('90 s')
        ->and(PontAction::query()->find($res->attente)->statut)->toBe('expiree');
});

test('délier révoque l’accès, vide les relevés et la file', function () {
    $id = relier();
    PontReleve::query()->create(['cle' => '/v1/sante', 'statut' => 200, 'donnees' => [], 'recu_le' => now()]);

    expect(app(Appairage::class)->delier(null))->toBeTrue();
    expect(PontInstance::active())->toBeNull()->and(PontReleve::query()->count())->toBe(0);
    envoyer('GET', '/pont/v1/actions?attente=0', $id)->assertStatus(401);
});

test('l’API du pont échappe à la liste d’IP, l’interface non', function () {
    config(['hermes-os.ip_allowlist' => ['10.0.0.1']]);

    $this->postJson('/pont/v1/appairage', ['code' => 'ZZZZZZZZ'])->assertStatus(422);
    $this->get('/pont/installer.sh')->assertOk();
    $this->get('/connexion')->assertForbidden();
});

test('l’installeur injecte l’URL de la console et ne sert que les modules en liste blanche', function () {
    config(['app.url' => 'https://console.example']);

    $this->get('/pont/installer.sh')->assertOk()
        ->assertSee("CONSOLE='https://console.example'", false)
        ->assertSee("curl --proto '=https' --proto-redir '=https'", false)
        ->assertSee('hermes_os_distant.py', false)
        ->assertDontSee('__CONSOLE__', false);

    $this->get('/pont/fichiers/hermes_os_distant.py')->assertOk();
    $this->get('/pont/fichiers/hermes_os_inconnu.py')->assertNotFound();
    $this->get('/pont/fichiers/..%2F.env')->assertNotFound();
});

test('l’appairage est limité à 10 essais par minute et par IP', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/pont/v1/appairage', ['code' => 'ZZZZZZZZ'])->assertStatus(422);
    }
    $this->postJson('/pont/v1/appairage', ['code' => 'ZZZZZZZZ'])->assertStatus(429);
});

/*
 * Tour 2 — correctifs de l'audit sécurité v1 (M1, m1, m3, m4, m6, m8).
 */

test('M1 : échanges simultanés du même code (processus séparés, SQLite fichier) → un seul 201, aucun 500', function () {
    $base = tempnam(sys_get_temp_dir(), 'pont-concurrent-').'.sqlite';
    touch($base);
    $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $base, 'DB_URL' => '', 'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'CACHE_STORE' => 'array'];
    $outil = base_path('tests/outils/echange-concurrent.php');

    try {
        $prep = Process::env($env)->path(base_path())->run([PHP_BINARY, $outil, 'preparer'])->throw();
        $code = trim($prep->output());
        $depart = (string) (microtime(true) + 2);
        $processus = collect(range(1, 4))->map(fn () => Process::env($env)->path(base_path())
            ->start([PHP_BINARY, $outil, 'echanger', $code, $depart]));
        $statuts = $processus->map(fn ($p) => trim($p->wait()->output()))->sort()->values()->all();
    } finally {
        @unlink($base);
    }

    expect($statuts)->toBe(['201', '422', '422', '422']);
});

test('m1 : un identifiant d’instance aléatoire ne contourne plus la limite par IP', function () {
    RateLimiter::increment(md5('pont'.'ip:127.0.0.1'), 60, 600);

    $this->withHeaders(['X-Hermes-Instance' => (string) Str::uuid()])->getJson('/pont/v1/actions')->assertStatus(429);
    $this->withHeaders(['X-Hermes-Instance' => (string) Str::uuid()])->get('/pont/installer.sh')->assertStatus(429);
});

test('m1 : le quota d’une instance est propre à l’IP (une autre IP ne l’épuise pas)', function () {
    $instance = (string) Str::uuid();
    RateLimiter::increment(md5('pont'.'instance:10.9.9.9|'.$instance), 60, 240);

    $this->withHeaders(['X-Hermes-Instance' => $instance])->getJson('/pont/v1/actions')->assertStatus(401);
    RateLimiter::increment(md5('pont'.'instance:127.0.0.1|'.$instance), 60, 240);
    $this->withHeaders(['X-Hermes-Instance' => $instance])->getJson('/pont/v1/actions')->assertStatus(429);
});

test('m3 : une APP_URL dangereuse n’est jamais rendue dans l’installeur', function (string $url) {
    config(['app.url' => $url]);

    $r = $this->get('/pont/installer.sh')->assertStatus(500);
    expect($r->getContent())->not->toContain('$(')->not->toContain('evil');
})->with([
    'substitution' => ['https://x.example/$(touch /tmp/evil)'],
    'apostrophe' => ["https://x.example/'evil"],
    'http distant' => ['http://evil.example'],
    'autre schéma' => ['file:///evil'],
]);

test('m3 : HTTP n’est accepté qu’en bouclage, et curl l’autorise seulement dans ce cas', function () {
    config(['app.url' => 'http://127.0.0.1:18090']);
    $this->get('/pont/installer.sh')->assertOk()
        ->assertSee("CONSOLE='http://127.0.0.1:18090'", false)
        ->assertSee("--proto '=https,http'", false);

    config(['app.url' => 'http://localhost']);
    $this->get('/pont/installer.sh')->assertOk();
});

test('m4 : un résultat arrivé après l’expiration est refusé et l’action marquée expirée', function () {
    $id = relier();
    $res = (new DistantClient(attenteSecondes: 0))->action(Action::CronSuspendre, ['job_id' => 'abcdef123456']);
    envoyer('GET', '/pont/v1/actions?attente=0', $id)->assertOk();

    $this->travel(91)->seconds();
    envoyer('POST', "/pont/v1/actions/{$res->attente}/resultat", $id, ['ok' => true, 'code' => 200])->assertStatus(410);

    $action = PontAction::query()->find($res->attente);
    expect($action->statut)->toBe('expiree')->and($action->resultat)->toBeNull();
});

test('m6 : appairage au-delà de 4 Kio et résultat au-delà de 2 Mo → 413', function () {
    $this->postJson('/pont/v1/appairage', ['code' => 'ZZZZZZZZ', 'nom' => str_repeat('a', 4200)])->assertStatus(413);
    expect(PontCode::query()->count())->toBe(0);

    $id = relier();
    $res = (new DistantClient(attenteSecondes: 0))->action(Action::CronSuspendre, ['job_id' => 'abcdef123456']);
    envoyer('GET', '/pont/v1/actions?attente=0', $id)->assertOk();
    envoyer('POST', "/pont/v1/actions/{$res->attente}/resultat", $id, ['ok' => true, 'message' => str_repeat('a', 2 * 1024 * 1024)])
        ->assertStatus(413);
    expect(PontAction::query()->find($res->attente)->statut)->toBe('envoyee');
});

test('m8 : l’action est rattachée à l’instance qui la reçoit ; une autre instance ne peut pas en rendre le résultat', function () {
    $a = relier();
    $res = (new DistantClient(attenteSecondes: 0))->action(Action::CronSuspendre, ['job_id' => 'abcdef123456']);
    envoyer('GET', '/pont/v1/actions?attente=0', $a)->assertOk();
    expect(PontAction::query()->find($res->attente)->instance_id)->toBe($a['instance_id']);

    // Livrée à une autre instance (une seule est active à la fois : on simule l'action d'une instance précédente).
    PontAction::query()->whereKey($res->attente)->update(['instance_id' => (string) Str::uuid()]);
    envoyer('POST', "/pont/v1/actions/{$res->attente}/resultat", $a, ['ok' => true])->assertNotFound();
    expect(PontAction::query()->find($res->attente)->statut)->toBe('envoyee');
});
