<?php

/*
 * Outil de scripts/mesure-panneau-relie.sh : prépare la console jetable pour mesurer le panneau « Hermes relié » dans un
 * vrai Chromium. Jamais servi par le serveur web (hors de public/), jamais lancé sur une base réelle.
 *
 *   php scripts/e2e/mesure.php compte <email> <mot-de-passe>   → crée un compte enrôlé TOTP, affiche le secret
 *   php scripts/e2e/mesure.php nouveau <email> <mot-de-passe>  → crée un compte sans TOTP (enrôlement)
 *   php scripts/e2e/mesure.php totp <secret>                   → code TOTP courant
 *   php scripts/e2e/mesure.php instance en-ligne|hors-ligne    → relie « Hermes Équipe » et pose un relevé frais ou vieux de 5 min
 *   php scripts/e2e/mesure.php delier                          → révoque l'instance active
 *   php scripts/e2e/mesure.php vieillir                        → dernier code TOTP des sessions vieux d'1 h (confirmation avec code)
 *   php scripts/e2e/mesure.php expiration                      → « HH:MM » de fin du dernier code d'appairage, fuseau de l'application
 */

use App\Interface\Temps;
use App\Models\PontCode;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Models\User;
use App\Pont\Appairage;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$commande = $argv[1] ?? '';

switch ($commande) {
    case 'compte':
        $secret = app(Google2FA::class)->generateSecretKey();
        $user = new User;
        $user->forceFill([
            'name' => 'Mesure',
            'email' => $argv[2],
            'password' => $argv[3],
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-recup-1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        echo $secret, "\n";
        break;
    case 'nouveau':
        // Compte sans TOTP : la connexion mène à l'enrôlement (écran imposé, scripts/mesure-cibles.sh).
        $user = new User;
        $user->forceFill(['name' => 'Neuf', 'email' => $argv[2], 'password' => $argv[3]])->save();
        echo "compte {$argv[2]}\n";
        break;
    case 'totp':
        echo app(Google2FA::class)->getCurrentOtp($argv[2] ?? ''), "\n";
        break;
    case 'instance':
        $appairage = app(Appairage::class);
        if (PontInstance::active() === null) {
            $appairage->echanger($appairage->generer(null)['code'], ['nom' => 'Hermes Équipe', 'hote' => 'hermes.example', 'version' => '0.24.1', 'profils' => ['dev', 'cto']]);
        }
        $releve = ($argv[2] ?? '') === 'hors-ligne' ? now()->subMinutes(5) : now();
        PontInstance::active()->update(['latence_ms' => 42, 'dernier_releve_le' => $releve]);
        PontReleve::query()->updateOrCreate(['cle' => '/v1/sante'], ['statut' => 200, 'recu_le' => $releve,
            'donnees' => ['ok' => true, 'verifications' => [['nom' => 'Gateway', 'ok' => true], ['nom' => 'Base de sessions', 'ok' => true]]]]);
        echo "instance {$argv[2]}\n";
        break;
    case 'delier':
        echo app(Appairage::class)->delier(null) ? "délié\n" : "rien à délier\n";
        break;
    case 'vieillir':
        // Sessions en base, non chiffrées (SESSION_ENCRYPT=false sur la console jetable uniquement).
        $n = 0;
        foreach (DB::table('sessions')->get() as $s) {
            $json = config('session.serialization') === 'json';
            $brut = base64_decode($s->payload);
            $donnees = $json ? json_decode($brut, true) : unserialize($brut, ['allowed_classes' => false]);
            if (! is_array($donnees) || ! isset($donnees['hermes']['totp_confirme_a'])) {
                continue;
            }
            $donnees['hermes']['totp_confirme_a'] = now()->subHour()->getTimestamp();
            $n += DB::table('sessions')->where('id', $s->id)->update(['payload' => base64_encode($json ? json_encode($donnees) : serialize($donnees))]);
        }
        echo "{$n} session(s)\n";
        break;
    case 'expiration':
        echo Temps::lire(PontCode::query()->latest('id')->value('expire_le'))?->format('H:i'), "\n";
        break;
    default:
        fwrite(STDERR, "usage : mesure.php compte|nouveau|totp|instance|delier|vieillir|expiration\n");
        exit(2);
}
