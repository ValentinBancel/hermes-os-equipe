<?php

namespace App\Http\Controllers\Pont;

use App\Http\Controllers\Controller;
use App\Models\PontAction;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Pont\Appairage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * API du pont distant (L4, R4, R10). Hors session/CSRF/liste d'IP ; tout sauf l'appairage est signé
 * (middleware VerifierSignaturePont). Réponses génériques, jamais de secret dans un journal.
 */
final class PontController extends Controller
{
    public const RELEVES_MAX_OCTETS = 2 * 1024 * 1024;

    /** Appairage non authentifié : un code et quelques infos d'identité, jamais plus de 4 Kio (secu m6). */
    public const APPAIRAGE_MAX_OCTETS = 4096;

    public const RE_CLE = '#^/v1/[A-Za-z0-9._/%-]{1,300}(\?[A-Za-z0-9._=&%-]{0,300})?$#';

    public function appairage(Request $request, Appairage $appairage): JsonResponse
    {
        if (strlen($request->getContent()) > self::APPAIRAGE_MAX_OCTETS) {
            return self::tropVolumineux();
        }

        $corps = $request->json()->all();
        $code = is_string($corps['code'] ?? null) ? $corps['code'] : '';

        $instance = $appairage->echanger($code, $corps);

        if ($instance === null) {
            return response()->json(['erreur' => 'Code refusé.'], 422);
        }

        return response()->json($instance, 201);
    }

    public function releves(Request $request): Response|JsonResponse
    {
        if (strlen($request->getContent()) > self::RELEVES_MAX_OCTETS) {
            return self::tropVolumineux();
        }

        /** @var PontInstance $instance */
        $instance = $request->attributes->get('pont_instance');
        $corps = $request->json()->all();
        $releves = is_array($corps['releves'] ?? null) ? $corps['releves'] : [];
        $maintenant = now();

        DB::transaction(function () use ($releves, $maintenant) {
            foreach ($releves as $cle => $releve) {
                if (! is_string($cle) || ! preg_match(self::RE_CLE, $cle) || str_contains($cle, '..')
                    || ! is_array($releve) || ! is_int($releve['statut'] ?? null) || ! is_array($releve['donnees'] ?? null)) {
                    continue;
                }
                PontReleve::query()->updateOrCreate(['cle' => $cle], [
                    'statut' => $releve['statut'], 'donnees' => $releve['donnees'], 'recu_le' => $maintenant,
                ]);
            }
        });

        $instance->forceFill(array_filter([
            'dernier_contact_le' => $maintenant,
            'dernier_releve_le' => $maintenant,
            'version' => is_string($corps['version'] ?? null) ? mb_substr($corps['version'], 0, 40) : null,
            'latence_ms' => is_int($corps['latence_ms'] ?? null) ? max(0, min(600_000, $corps['latence_ms'])) : null,
            'profils' => is_array($corps['profils'] ?? null)
                ? array_values(array_slice(array_filter($corps['profils'], fn ($p) => is_string($p) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $p)), 0, 200))
                : null,
        ], fn ($v) => $v !== null))->save();

        DB::table('pont_nonces')->where('cree_le', '<', now()->subMinutes(10))->delete();

        return response()->noContent();
    }

    public function actions(Request $request): Response|JsonResponse
    {
        /** @var PontInstance $instance */
        $instance = $request->attributes->get('pont_instance');
        $attente = max(0, min((int) config('hermes-os.pont.long_poll_max_secondes', 25), (int) $request->query('attente', 0)));
        $limite = microtime(true) + $attente;

        do {
            $instance->forceFill(['dernier_contact_le' => now()])->save();
            PontAction::query()->where('statut', 'en_attente')->where('expire_le', '<=', now())
                ->update(['statut' => 'expiree', 'terminee_le' => now()]);

            $actions = DB::transaction(function () use ($instance) {
                $lot = PontAction::query()->where('statut', 'en_attente')->where('expire_le', '>', now())
                    ->orderBy('created_at')->limit(20)->lockForUpdate()->get();
                // L'action est rattachée à l'instance qui la reçoit (secu m8) : seule elle pourra en rendre le résultat.
                PontAction::query()->whereKey($lot->modelKeys())
                    ->update(['statut' => 'envoyee', 'envoyee_le' => now(), 'instance_id' => $instance->instance_id]);

                return $lot;
            });

            if ($actions->isNotEmpty()) {
                return response()->json(['actions' => $actions->map(fn (PontAction $a) => [
                    'id' => $a->id, 'action' => $a->action, 'donnees' => (object) $a->donnees,
                    'acteur' => $a->acteur, 'cree_le' => $a->created_at?->toIso8601String(),
                ])->all()]);
            }

            if (microtime(true) >= $limite) {
                break;
            }
            sleep(1);
        } while ($instance->refresh()->revoquee_le === null);

        return $instance->revoquee_le === null ? response()->noContent() : response()->json(['erreur' => 'Non authentifié.'], 401);
    }

    public function resultat(Request $request, string $id): Response|JsonResponse
    {
        if (strlen($request->getContent()) > self::RELEVES_MAX_OCTETS) {
            return self::tropVolumineux();
        }

        /** @var PontInstance $instance */
        $instance = $request->attributes->get('pont_instance');
        $action = PontAction::query()->whereKey($id)->where('statut', 'envoyee')
            ->where('instance_id', $instance->instance_id)->first();

        if ($action === null) {
            return response()->json(['erreur' => 'Action inconnue.'], 404);
        }

        // Passé l'expiration, l'interface a déjà annoncé l'abandon : un résultat tardif est refusé (secu m4).
        if ($action->expire_le->isPast()) {
            $action->update(['statut' => 'expiree', 'terminee_le' => now()]);

            return response()->json(['erreur' => 'Action expirée.'], 410);
        }

        $corps = $request->json()->all();
        $resultat = [
            'ok' => ($corps['ok'] ?? false) === true,
            'code' => is_int($corps['code'] ?? null) ? $corps['code'] : null,
            'message' => is_string($corps['message'] ?? null) ? mb_substr($corps['message'], 0, 2000) : '',
            'donnees' => is_array($corps['donnees'] ?? null) ? $corps['donnees'] : null,
        ];

        DB::transaction(function () use ($action, $resultat) {
            $action->update(['statut' => 'terminee', 'resultat' => $resultat, 'terminee_le' => now()]);

            // Lecture à la demande (détail d'une sortie, conversation) : le résultat devient un relevé.
            $cle = $action->action === 'lecture' ? ($action->donnees['chemin'] ?? null) : null;
            if (is_string($cle) && preg_match(self::RE_CLE, $cle) && is_array($resultat['donnees']) && $resultat['code'] !== null) {
                PontReleve::query()->updateOrCreate(['cle' => $cle], [
                    'statut' => $resultat['code'], 'donnees' => $resultat['donnees'], 'recu_le' => now(),
                ]);
            }
        });

        return response()->noContent();
    }

    private static function tropVolumineux(): JsonResponse
    {
        return response()->json(['erreur' => 'Corps trop volumineux.'], 413);
    }
}
