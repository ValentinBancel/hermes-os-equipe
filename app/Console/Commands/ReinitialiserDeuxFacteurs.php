<?php

namespace App\Console\Commands;

use App\Audit\Journal;
use App\Auth\AppareilsDeConfiance;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Perte du téléphone et des codes de récupération : efface le TOTP du compte et ferme
 * toutes ses sessions. Le TOTP sera réenrôlé à la connexion suivante (accès serveur requis).
 */
#[Signature('hermes-os:reinitialiser-2fa
    {--email= : Compte visé (par défaut : le compte unique)}
    {--force : Ne pas demander de confirmation}')]
#[Description('Réinitialise le TOTP du compte et ferme ses sessions')]
class ReinitialiserDeuxFacteurs extends Command
{
    public function handle(Journal $journal): int
    {
        $email = $this->option('email');

        $user = $email
            ? User::query()->where('email', mb_strtolower((string) $email))->first()
            : User::query()->sole();

        if ($user === null) {
            $this->error('Compte introuvable.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Réinitialiser le TOTP de {$user->email} ?")) {
            $this->line('Abandon.');

            return self::FAILURE;
        }

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => null,
        ])->save();

        $sessions = DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        $appareils = app(AppareilsDeConfiance::class)->revoquerTout($user);

        $journal->ecrire('totp.reinitialise', user: $user, contexte: ['source' => 'cli', 'sessions_fermees' => $sessions, 'appareils_revoques' => $appareils]);

        $this->info("TOTP réinitialisé, {$sessions} session(s) fermée(s). Réenrôlement à la prochaine connexion.");

        return self::SUCCESS;
    }
}
