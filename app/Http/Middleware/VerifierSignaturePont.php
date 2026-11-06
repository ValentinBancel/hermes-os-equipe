<?php

namespace App\Http\Middleware;

use App\Models\PontInstance;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifie le pont distant (L4, R5) : instance active, horodatage à ±300 s, nonce jamais vu,
 * X-Hermes-Signature = hex(HMAC-SHA256(secret, MÉTHODE\nchemin?requête\nhorodatage\nnonce\nhex(sha256(corps)))).
 * Tout refus répond 401 sans détail (le pont s'arrête proprement sur 401).
 */
final class VerifierSignaturePont
{
    public const FENETRE_SECONDES = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $instanceId = (string) $request->header('X-Hermes-Instance', '');
        $horodatage = (string) $request->header('X-Hermes-Horodatage', '');
        $nonce = (string) $request->header('X-Hermes-Nonce', '');
        $signature = (string) $request->header('X-Hermes-Signature', '');

        if (! preg_match('/^[0-9]{1,12}$/', $horodatage) || ! preg_match('/^[a-f0-9]{32}$/', $nonce)
            || ! preg_match('/^[a-f0-9]{64}$/', $signature) || abs(time() - (int) $horodatage) > self::FENETRE_SECONDES) {
            return self::refus();
        }

        $instance = $instanceId === '' ? null : PontInstance::query()->active()->where('instance_id', $instanceId)->first();

        if ($instance === null) {
            return self::refus();
        }

        // Cible signée relative à la racine de la console (le pont signe « /pont/v1/… », même sous un sous-dossier).
        $requete = (string) $request->server('QUERY_STRING', '');
        $cible = $request->getPathInfo().($requete !== '' ? '?'.$requete : '');

        $attendue = self::signer((string) $instance->secret, $request->getMethod(), $cible,
            $horodatage, $nonce, $request->getContent());

        if (! hash_equals($attendue, $signature)) {
            return self::refus();
        }

        try {
            // Anti-rejeu : l'unicité (instance, nonce) en base tranche même entre requêtes concurrentes.
            DB::table('pont_nonces')->insert(['instance_id' => $instanceId, 'nonce' => $nonce, 'cree_le' => now()]);
        } catch (QueryException) {
            return self::refus();
        }

        $request->attributes->set('pont_instance', $instance);

        return $next($request);
    }

    public static function signer(string $secret, string $methode, string $cible, string $horodatage, string $nonce, string $corps): string
    {
        return hash_hmac('sha256', implode("\n", [strtoupper($methode), $cible, $horodatage, $nonce, hash('sha256', $corps)]), $secret);
    }

    private static function refus(): Response
    {
        return response()->json(['erreur' => 'Non authentifié.'], 401);
    }
}
