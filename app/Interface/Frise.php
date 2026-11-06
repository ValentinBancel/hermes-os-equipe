<?php

namespace App\Interface;

use App\Hermes\HermesClient;
use Carbon\CarbonImmutable;

/**
 * Frise 0-24 h de l'accueil : exécutions de tâches planifiées depuis minuit (historique réel des tâches, lu sur le
 * pont) et exécutions à venir jusqu'à minuit (calculées depuis le planning). Regroupement à 5 min (« ×n ») par statut :
 * un échec n'est jamais regroupé avec des réussites (D1) ; il est dessiné en carré vermillon (C1 : même codage que
 * l'erreur), au-dessus des réussites de la même fenêtre.
 */
final class Frise
{
    /** @var list<array{minute: int, n: int, echec: bool}> */
    public array $passes = [];

    /** @var list<array{minute: int, n: int}> */
    public array $venir = [];

    /** @var array<int, array{0: int, 1: int, 2: int}> heure => [réussies, échecs, à venir] */
    public array $parHeure = [];

    public int $reussies = 0;

    public int $echecs = 0;

    public int $aVenir = 0;

    public ?CarbonImmutable $prochaine = null;

    public int $minuteMaintenant;

    /**
     * @param  list<array<string, mixed>>  $taches
     */
    public function __construct(Releve $releve, array $taches, public readonly CarbonImmutable $maintenant, public readonly bool $suspendue = false)
    {
        $minuit = $maintenant->startOfDay();
        $this->minuteMaintenant = (int) floor($minuit->diffInMinutes($maintenant));
        $this->parHeure = array_fill(0, 24, [0, 0, 0]);

        $passes = [];
        foreach ($taches as $t) {
            if (($t['derniere_execution'] ?? null) === null || Temps::lire($t['derniere_execution'])?->lt($minuit)) {
                continue;
            }
            $id = (string) $t['id'];
            foreach ($releve->donnees(fn (HermesClient $c) => $c->planifiee($id))['historique'] ?? [] as $h) {
                $debut = Temps::lire($h['demarre_a'] ?? null);
                if ($debut === null || $debut->lt($minuit) || $debut->gt($maintenant)) {
                    continue;
                }
                $echec = ($h['statut'] ?? null) !== 'completed' || ($h['livraison'] ?? null) === 'failed';
                $passes[] = [(int) floor($minuit->diffInMinutes($debut)), $echec];
            }
        }

        $venir = [];
        foreach ($taches as $t) {
            foreach (Planification::executionsPrevues($t, $maintenant, $minuit->endOfDay()) as $x) {
                $venir[] = (int) floor($minuit->diffInMinutes($x));
                if ($this->prochaine === null || $x->lt($this->prochaine)) {
                    $this->prochaine = $x;
                }
            }
        }

        sort($venir);
        usort($passes, fn (array $a, array $b) => $a[0] <=> $b[0]);

        // D1 : regroupement par statut. Un échec n'est jamais fondu avec des réussites : chaque statut a ses propres
        // groupes de 5 min (rond encre « ×n » pour les réussites, carré vermillon au-dessus pour les échecs).
        $derniers = [false => null, true => null];
        foreach ($passes as [$m, $echec]) {
            $echec ? $this->echecs++ : $this->reussies++;
            $this->parHeure[intdiv($m, 60)][$echec ? 1 : 0]++;
            $dernier = $derniers[$echec];
            if ($dernier !== null && $m - $this->passes[$dernier]['minute'] <= 5) {
                $this->passes[$dernier]['n']++;
            } else {
                $this->passes[] = ['minute' => $m, 'n' => 1, 'echec' => $echec];
                $derniers[$echec] = array_key_last($this->passes);
            }
        }

        foreach ($venir as $m) {
            $this->aVenir++;
            $this->parHeure[min(23, intdiv($m, 60))][2]++;
            $dernier = array_key_last($this->venir);
            if ($dernier !== null && $m - $this->venir[$dernier]['minute'] <= 5) {
                $this->venir[$dernier]['n']++;
            } else {
                $this->venir[] = ['minute' => $m, 'n' => 1];
            }
        }
    }

    /** Abscisse (en %) d'une minute de la journée. */
    public static function pct(int|float $minute): string
    {
        return number_format(max(0, min(1440, $minute)) / 1440 * 100, 3, '.', '');
    }

    /**
     * Largeur minimale de la frise en points (≥ 640 px : 640 − 2 × 16 de marge) et de la frise en barres (< 640 px :
     * 320 − 2 × 16). Les positions sont en % ; ces minimums servent à garantir un écart en px dans le pire cas.
     */
    public const LARGEUR_MIN_POINTS = 608;

    public const LARGEUR_MIN_BARRES = 288;

    /**
     * D1 : ancrage de l'étiquette « ×n » d'un point. Centrée sous le point si elle reste à 8 px au moins du trait
     * « maintenant » (liseré compris) ; sinon décalée du côté opposé au trait (« end » = à gauche du point).
     */
    public function ancrageEtiquette(int $minute, int $n): string
    {
        $pxParMinute = self::LARGEUR_MIN_POINTS / 1440;
        $demiLargeur = (1 + strlen((string) $n)) * 4.5;
        $ecart = abs($minute - $this->minuteMaintenant) * $pxParMinute - $demiLargeur;

        return match (true) {
            $ecart >= 8 + 3 => 'middle',
            $minute <= $this->minuteMaintenant => 'end',
            default => 'start',
        };
    }

    /**
     * D2 : graduations de l'axe en barres (0 h · 6 h · 12 h · 18 h · 24 h), sauf celle qui tomberait à moins de 8 px de
     * l'étiquette d'heure courante (42 px de large), au pire cas de largeur.
     *
     * @return list<int> heures affichées
     */
    public function graduationsBarres(): array
    {
        $px = self::LARGEUR_MIN_BARRES / 1440;
        $m = $this->minuteMaintenant * $px;
        $largeur = 24; // « 18 h » en 13 px semi-gras, arrondi au-dessus

        return array_values(array_filter([0, 6, 12, 18, 24], function (int $h) use ($px, $m, $largeur): bool {
            $x = $h * 60 * $px;
            // 0 h est ancré à gauche, 24 h à droite, les autres centrés.
            [$g, $d] = match ($h) {
                0 => [$x, $x + $largeur],
                24 => [$x - $largeur, $x],
                default => [$x - $largeur / 2, $x + $largeur / 2],
            };

            return $d <= $m - 21 - 8 || $g >= $m + 21 + 8;
        }));
    }

    /**
     * Barres par heure (< 640 px) : étiquette « ×n » si n ≥ 3, jamais sur deux heures voisines (1.3).
     *
     * @return array<int, bool>
     */
    public function etiquettesBarres(): array
    {
        $res = [];
        $derniere = -9;
        foreach ($this->parHeure as $h => [$p, $e, $v]) {
            $n = $p + $e + $v;
            $res[$h] = $n >= 3 && $h - $derniere >= 2;
            if ($res[$h]) {
                $derniere = $h;
            }
        }

        return $res;
    }

    public function maximumParHeure(): int
    {
        return max(1, ...array_map(fn (array $x) => array_sum($x), $this->parHeure));
    }
}
