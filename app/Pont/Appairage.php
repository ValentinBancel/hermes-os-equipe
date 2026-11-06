<?php

namespace App\Pont;

use App\Audit\Journal;
use App\Models\PontAction;
use App\Models\PontCode;
use App\Models\PontInstance;
use App\Models\PontReleve;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Appairage du pont distant (L4, R4) : code à usage unique de 8 caractères (alphabet sans 0/O/1/I/L),
 * 10 minutes, invalidé après 5 essais faux ; l'échange crée l'instance et remplace la précédente.
 */
final class Appairage
{
    public const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const ESSAIS_MAX = 5;

    public function __construct(private readonly Journal $journal) {}

    /** @return array{code: string, expire_le: CarbonInterface} */
    public function generer(?User $user, string $source = 'compte'): array
    {
        PontCode::query()->whereNull('utilise_le')->whereNull('invalide_le')->update(['invalide_le' => now()]);

        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        $ligne = PontCode::query()->create([
            'code_hash' => hash('sha256', $code),
            'user_id' => $user?->getKey(),
            'expire_le' => now()->addMinutes((int) config('hermes-os.pont.code_minutes', 10)),
        ]);

        $this->journal->ecrire('pont.code', user: $user, contexte: ['source' => $source]);

        return ['code' => $code, 'expire_le' => $ligne->expire_le];
    }

    public static function normaliser(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    }

    public static function afficher(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }

    /**
     * @param  array{nom?: mixed, hote?: mixed, version?: mixed, profils?: mixed}  $infos
     * @return array{instance_id: string, secret: string}|null
     */
    public function echanger(string $code, array $infos): ?array
    {
        $code = self::normaliser($code);

        return DB::transaction(function () use ($code, $infos) {
            $actif = PontCode::query()->whereNull('utilise_le')->whereNull('invalide_le')->latest('id')->lockForUpdate()->first();

            if ($actif === null || ! $actif->utilisable() || ! preg_match('/^['.self::ALPHABET.']{8}$/', $code)
                || ! hash_equals($actif->code_hash, hash('sha256', $code))) {
                if ($actif !== null && $actif->utilisable()) {
                    $actif->essais_faux++;
                    if ($actif->essais_faux >= self::ESSAIS_MAX) {
                        $actif->invalide_le = now();
                    }
                    $actif->save();
                }
                $this->journal->ecrire('pont.appairage', 'failed', contexte: ['essais_faux' => $actif?->essais_faux]);

                return null;
            }

            $actif->update(['utilise_le' => now()]);

            $this->revoquerTout();

            $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $instance = PontInstance::query()->create([
                'instance_id' => (string) Str::uuid(),
                'secret' => $secret,
                'nom' => Str::limit(self::texte($infos['nom'] ?? null) ?: 'Hermes', 100, ''),
                'hote' => Str::limit(self::texte($infos['hote'] ?? null), 255, '') ?: null,
                'version' => Str::limit(self::texte($infos['version'] ?? null), 40, '') ?: null,
                'profils' => self::profils($infos['profils'] ?? null),
                'dernier_contact_le' => now(),
                'relie_par' => $actif->user_id,
            ]);

            $this->journal->ecrire('pont.relie', user: $actif->user_id ? User::query()->find($actif->user_id) : null,
                contexte: ['instance' => $instance->instance_id, 'nom' => $instance->nom]);

            return ['instance_id' => $instance->instance_id, 'secret' => $secret];
        });
    }

    public function delier(?User $user): bool
    {
        $instance = PontInstance::active();

        if ($instance === null) {
            return false;
        }

        $this->revoquerTout();
        $this->journal->ecrire('pont.delie', user: $user, contexte: ['instance' => $instance->instance_id]);

        return true;
    }

    /** Une seule instance active (R4) : la précédente perd son accès, ses relevés et sa file sont vidés. */
    private function revoquerTout(): void
    {
        PontInstance::query()->active()->update(['revoquee_le' => now()]);
        PontReleve::query()->delete();
        PontAction::query()->whereIn('statut', ['en_attente', 'envoyee'])->update(['statut' => 'expiree', 'terminee_le' => now()]);
        DB::table('pont_nonces')->delete();
    }

    private static function texte(mixed $valeur): string
    {
        return is_string($valeur) ? trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $valeur)) : '';
    }

    /** @return list<string> */
    private static function profils(mixed $valeur): array
    {
        return is_array($valeur)
            ? array_values(array_slice(array_filter($valeur, fn ($p) => is_string($p) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $p)), 0, 200))
            : [];
    }
}
