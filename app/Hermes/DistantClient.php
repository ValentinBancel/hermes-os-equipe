<?php

namespace App\Hermes;

use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Models\PontAction;
use App\Models\PontInstance;
use App\Models\PontReleve;
use Carbon\CarbonImmutable;

/**
 * Pilote « distant » (L4, R9) : le pont pousse ses relevés et récupère les actions par HTTPS sortant.
 * Lectures = dernier relevé par clé (périmé au-delà de 60 s) ; une clé jamais relevée (détail d'une sortie,
 * conversation…) est demandée au pont comme action `lecture`. Actions = file `pont_actions` (expiration 90 s) ;
 * si le pont ne répond pas pendant l'attente courte, l'interface suit l'action par wire:poll.
 */
final class DistantClient implements HermesClient
{
    use RoutesDuPont;

    public function __construct(
        private readonly int $perimeSecondes = 60,
        private readonly int $expirationSecondes = 90,
        private readonly int $attenteSecondes = 8,
    ) {}

    public function pilote(): string
    {
        return 'distant';
    }

    public function lire(string $chemin, array $requete = []): Lecture
    {
        $cible = self::cle($chemin, $requete);
        $instance = PontInstance::active();

        if ($instance === null) {
            throw new PontInjoignable('Aucun Hermes relié : reliez-en un depuis Compte.');
        }

        $releve = PontReleve::query()->where('cle', $cible)->first();

        if ($releve === null) {
            $releve = $this->lireAPart($cible);
        }

        $statut = (int) $releve->statut;
        $donnees = is_array($releve->donnees) ? $releve->donnees : [];

        if ($statut >= 400 && $statut < 500) {
            throw new PontErreur($statut, is_string($donnees['erreur'] ?? null) ? $donnees['erreur'] : 'Requête refusée.');
        }

        if ($statut !== 200) {
            throw new PontInjoignable('Réponse illisible du pont.');
        }

        $recu = CarbonImmutable::instance($releve->recu_le);
        $age = (int) $recu->diffInSeconds(CarbonImmutable::now(), absolute: true);

        if ($age > $this->perimeSecondes) {
            return new Lecture($donnees, $recu, true, 'Pont injoignable : dernier relevé il y a '.$age.' s.');
        }

        return new Lecture($donnees, $recu);
    }

    public function action(Action $action, array $donnees): ResultatAction
    {
        if (PontInstance::active() === null) {
            throw new PontInjoignable('Aucun Hermes relié : reliez-en un depuis Compte.');
        }

        $acteur = is_string($donnees['acteur'] ?? null) ? $donnees['acteur'] : null;
        unset($donnees['acteur']);

        $file = $this->enfiler($action->value, $donnees, $acteur);

        return $this->attendre($file, $this->attenteSecondes) ?? new ResultatAction(
            $action, false, null, '', 'En attente du pont…', attente: $file->id,
        );
    }

    /** Résultat d'une action suivie par wire:poll : null tant qu'elle est en file. */
    public function suivre(string $id): ?ResultatAction
    {
        $file = PontAction::query()->find($id);

        if ($file === null) {
            return null;
        }

        return $this->resultat($file) ?? ($this->expirer($file) ? $this->resultat($file->refresh()) : null);
    }

    public function oublierCache(): void
    {
        // Rien à oublier : le relevé suivant arrive juste après chaque action (R10).
    }

    public static function cle(string $chemin, array $requete = []): string
    {
        return $requete === [] ? $chemin : $chemin.'?'.http_build_query($requete, '', '&', PHP_QUERY_RFC3986);
    }

    private function lireAPart(string $cible): PontReleve
    {
        $file = PontAction::query()
            ->where('action', 'lecture')
            ->whereIn('statut', ['en_attente', 'envoyee'])
            ->where('expire_le', '>', now())
            ->get()
            ->first(fn (PontAction $a) => ($a->donnees['chemin'] ?? null) === $cible)
            ?? $this->enfiler('lecture', ['chemin' => $cible], null);

        $this->attendre($file, $this->attenteSecondes);

        return PontReleve::query()->where('cle', $cible)->first()
            ?? throw new PontInjoignable('Donnée en cours de récupération auprès du pont.');
    }

    /** @param  array<string, mixed>  $donnees */
    private function enfiler(string $action, array $donnees, ?string $acteur): PontAction
    {
        return PontAction::query()->create([
            'action' => $action,
            'donnees' => $donnees,
            'acteur' => $acteur,
            'statut' => 'en_attente',
            'expire_le' => now()->addSeconds($this->expirationSecondes),
        ]);
    }

    private function attendre(PontAction $file, int $secondes): ?ResultatAction
    {
        $limite = microtime(true) + $secondes;

        do {
            $resultat = $this->resultat($file->refresh());
            if ($resultat !== null || microtime(true) >= $limite) {
                return $resultat;
            }
            usleep(250_000);
        } while (true);
    }

    private function expirer(PontAction $file): bool
    {
        if ($file->statut === 'terminee' || $file->expire_le->isFuture()) {
            return false;
        }

        $file->update(['statut' => 'expiree', 'terminee_le' => now()]);

        return true;
    }

    private function resultat(PontAction $file): ?ResultatAction
    {
        if ($file->action === 'lecture' || ! in_array($file->statut, ['terminee', 'expiree'], true)) {
            return null;
        }

        $action = Action::from($file->action);

        if ($file->statut === 'expiree') {
            return new ResultatAction($action, false, null, '', 'Le pont n\'a pas répondu à temps (90 s) : action abandonnée.');
        }

        $r = (array) $file->resultat;
        $donnees = is_array($r['donnees'] ?? null) ? $r['donnees'] : [];

        if (array_key_exists('ok', $donnees)) {
            return ResultatAction::depuisReponse($action, $donnees);
        }

        if (($r['ok'] ?? false) === true && $action->messagerie()) {
            return new ResultatAction($action, true, 0, is_string($donnees['conversation'] ?? null) ? $donnees['conversation'] : '');
        }

        return new ResultatAction(
            $action, ($r['ok'] ?? false) === true, is_int($r['code'] ?? null) ? $r['code'] : null, '',
            is_string($r['message'] ?? null) ? $r['message'] : '',
        );
    }
}
