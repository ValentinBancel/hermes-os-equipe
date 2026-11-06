<?php

namespace App\Hermes;

use App\Audit\Journal;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\Exceptions\TotpAReconfirmer;
use App\Hermes\Exceptions\TropDActions;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Seul point d'entrée des actions d'écriture (docs/architecture.md, « Flux d'écriture ») :
 * 1. TOTP confirmé depuis moins de 15 min (sinon TotpAReconfirmer) ;
 * 2. 20 actions par minute au plus (sinon TropDActions) ;
 * 3. arguments validés (le pont revalide) ;
 * 4. ligne d'audit `pending`, appel du pont, puis la même ligne passe à `ok` ou `failed`.
 * Les refus (TOTP, limite, validation) sont aussi journalisés, en `blocked`.
 */
final class ExecuteurActions
{
    public const ACTIONS_PAR_MINUTE = 20;

    private const CHAMPS_TEXTE = ['titre', 'corps', 'texte', 'raison'];

    public function __construct(
        private readonly HermesClient $client,
        private readonly Journal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws TotpAReconfirmer
     * @throws TropDActions
     * @throws ValidationException
     * @throws PontInjoignable
     * @throws PontErreur
     */
    public function executer(Request $request, User $user, Action $action, array $donnees): ResultatAction
    {
        $evenement = 'action.'.$action->value;

        if (! $action->messagerie() && ! $this->totpRecent($request)) {
            $this->journal->ecrire($evenement, 'blocked', $user, contexte: ['refus' => 'totp_a_reconfirmer']);

            throw new TotpAReconfirmer;
        }

        $cle = 'hermes-os:actions:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($cle, self::ACTIONS_PAR_MINUTE)) {
            $this->journal->ecrire($evenement, 'blocked', $user, contexte: ['refus' => 'limite_par_minute']);

            throw new TropDActions(RateLimiter::availableIn($cle));
        }
        RateLimiter::hit($cle, 60);

        $validateur = Validator::make($donnees, $action->regles());
        if ($validateur->fails()) {
            $this->journal->ecrire($evenement, 'blocked', $user, contexte: [
                'refus' => 'validation',
                'champs' => array_keys($validateur->errors()->messages()),
            ]);
        }
        $arguments = array_filter($validateur->validate(), fn (mixed $v) => $v !== null);

        $ligne = $this->journal->ecrire($evenement, 'pending', $user, contexte: [
            'arguments' => $this->pourAudit($arguments),
        ]);

        try {
            // L3 : le pont journalise l'e-mail du compte à l'origine de l'action (jamais fourni par le navigateur).
            $resultat = $this->client->action($action, [...$arguments, 'acteur' => $user->email]);
        } catch (Throwable $e) {
            $ligne->terminer('failed', [
                'erreur' => $e instanceof PontErreur || $e instanceof PontInjoignable ? $e->getMessage() : class_basename($e),
            ]);

            throw $e;
        }

        if ($resultat->attente !== null) {
            // Pont distant (L4) : l'action reste en file ; l'interface la suit (wire:poll) puis clôt le journal.
            return new ResultatAction($action, false, null, '', $resultat->erreur, $resultat->attente, $ligne->getKey());
        }

        $this->cloturer($ligne, $resultat);

        return $resultat;
    }

    /** Suivi d'une action en file (pilote distant) : null tant que le pont n'a pas répondu. */
    public function suivre(User $user, string $attente, int $journal): ?ResultatAction
    {
        if (! $this->client instanceof DistantClient) {
            return null;
        }

        $resultat = $this->client->suivre($attente);
        if ($resultat === null) {
            return null;
        }

        $ligne = AuditLog::query()->whereKey($journal)->where('user_id', $user->getKey())->where('status', 'pending')->first();
        if ($ligne !== null) {
            $this->cloturer($ligne, $resultat);
        }

        return $resultat;
    }

    private function cloturer(AuditLog $ligne, ResultatAction $resultat): void
    {
        $ligne->terminer($resultat->ok ? 'ok' : 'failed', array_filter([
            'code' => $resultat->code,
            'sortie' => Str::limit($resultat->sortie, 500) ?: null,
            'erreur' => Str::limit($resultat->erreur, 500) ?: null,
        ], fn (mixed $v) => $v !== null));

        $this->client->oublierCache();
    }

    public function totpRecent(Request $request): bool
    {
        $confirme = ($request->hasSession() ? $request->session() : app('session.store'))->get('hermes.totp_confirme_a');
        $minutes = (int) config('hermes-os.auth.minutes_validite_totp');

        return is_int($confirme) && now()->getTimestamp() - $confirme < $minutes * 60;
    }

    /**
     * Arguments tels que journalisés : textes libres raccourcis (le journal sert à savoir qui a fait quoi,
     * pas à recopier les contenus).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function pourAudit(array $arguments): array
    {
        // Coffre : on journalise quels champs secrets ont été fournis, jamais leurs valeurs.
        if (array_key_exists('secret', $arguments)) {
            $arguments['secret'] = array_keys(array_filter((array) $arguments['secret'], fn (mixed $v) => $v !== null && $v !== ''));
        }

        foreach (self::CHAMPS_TEXTE as $champ) {
            if (is_string($arguments[$champ] ?? null)) {
                $arguments[$champ] = Str::limit($arguments[$champ], 200);
            }
        }

        return $arguments;
    }
}
