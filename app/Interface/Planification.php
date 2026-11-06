<?php

namespace App\Interface;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Throwable;

/**
 * Tâches planifiées : planning en clair (ux/03 § Tâches planifiées), groupes de la liste, exécutions du jour
 * (frise 0-24 h de l'accueil).
 */
final class Planification
{
    private const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

    /** @param  array<string, mixed>  $t */
    public static function enEchec(array $t): bool
    {
        return ($t['actif'] ?? false) === true
            && ! in_array($t['dernier_statut'] ?? null, [null, '', 'ok'], true);
    }

    /**
     * Groupe de la liste : echec · active · pause · off (désactivée ou terminée).
     *
     * @param  array<string, mixed>  $t
     */
    public static function groupe(array $t): string
    {
        return match (true) {
            self::enEchec($t) => 'echec',
            ($t['etat'] ?? null) === 'paused' => 'pause',
            ($t['actif'] ?? false) !== true || ($t['etat'] ?? null) === 'completed' => 'off',
            default => 'active',
        };
    }

    /** @param  array<string, mixed>  $t */
    public static function etat(array $t): string
    {
        if (($t['actif'] ?? false) !== true) {
            return 'Désactivée';
        }

        return Libelles::ETATS_TACHE[$t['etat'] ?? ''] ?? '['.($t['etat'] ?? '?').']';
    }

    /**
     * « 0 8 * * * » → « tous les jours 8 h » ; repli : l'expression brute.
     */
    public static function enClair(string $planning): string
    {
        $p = trim($planning);

        if (preg_match('/^every\s+(\d+)\s*([smhd])$/i', $p, $m) === 1) {
            $unite = ['s' => 's', 'm' => 'min', 'h' => 'h', 'd' => 'j'][strtolower($m[2])];

            return "toutes les {$m[1]} {$unite}";
        }

        $c = preg_split('/\s+/', $p);
        if (! is_array($c) || count($c) !== 5) {
            return $p;
        }
        [$min, $heure, $jm, $mois, $js] = $c;

        if ($jm !== '*' || $mois !== '*') {
            return $p;
        }

        $jours = self::joursEnClair($js);
        if ($jours === null) {
            return $p;
        }

        // « */30 9-17 » : toutes les 30 min, 9 h–17 h
        if (preg_match('#^\*/(\d+)$#', $min, $m) === 1) {
            $plage = match (true) {
                $heure === '*' => '',
                preg_match('/^(\d+)-(\d+)$/', $heure, $h) === 1 => ", {$h[1]} h–{$h[2]} h",
                default => null,
            };
            if ($plage === null) {
                return $p;
            }

            return "toutes les {$m[1]} min{$plage}".($jours === 'tous les jours' ? '' : ", {$jours}");
        }

        if (! ctype_digit($min)) {
            return $p;
        }

        if ($heure === '*') {
            $suffixe = (int) $min === 0 ? '' : " (à :{$min})";

            return 'toutes les heures'.$suffixe.($jours === 'tous les jours' ? '' : ", {$jours}");
        }

        $heures = explode(',', $heure);
        foreach ($heures as $h) {
            if (! ctype_digit($h)) {
                return $p;
            }
        }
        $libelles = array_map(fn (string $h) => (int) $h.' h'.((int) $min === 0 ? '' : ' '.str_pad($min, 2, '0', STR_PAD_LEFT)), $heures);
        $quand = count($libelles) > 1 ? implode(', ', array_slice($libelles, 0, -1)).' et '.end($libelles) : $libelles[0];

        return "{$jours} {$quand}";
    }

    private static function joursEnClair(string $js): ?string
    {
        if ($js === '*') {
            return 'tous les jours';
        }
        if (ctype_digit($js) && (int) $js <= 7) {
            return self::JOURS[(int) $js];
        }
        if (preg_match('/^(\d)-(\d)$/', $js, $m) === 1) {
            return self::JOURS[(int) $m[1]].'–'.self::JOURS[(int) $m[2]];
        }

        return null;
    }

    /**
     * Exécutions prévues entre $de (exclu) et $a (inclus), dans le fuseau d'affichage.
     *
     * @param  array<string, mixed>  $t
     * @return list<CarbonImmutable>
     */
    public static function executionsPrevues(array $t, CarbonImmutable $de, CarbonImmutable $a, int $max = 200): array
    {
        if (($t['actif'] ?? false) !== true || ($t['etat'] ?? null) !== 'scheduled') {
            return [];
        }

        $planning = (string) ($t['planning'] ?? '');
        $res = [];

        if (preg_match('/^every\s+(\d+)\s*([smhd])$/i', $planning, $m) === 1) {
            $pas = (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][strtolower($m[2])];
            $prochaine = Temps::lire($t['prochaine_execution'] ?? null);
            if ($pas <= 0 || $prochaine === null) {
                return [];
            }
            for ($x = $prochaine; $x->lte($a) && count($res) < $max; $x = $x->addSeconds($pas)) {
                if ($x->gt($de)) {
                    $res[] = $x;
                }
            }

            return $res;
        }

        try {
            $cron = new CronExpression($planning);
            $x = $de;
            while (count($res) < $max) {
                $x = CarbonImmutable::instance($cron->getNextRunDate($x, 0, false, Temps::fuseau()))->setTimezone(Temps::fuseau());
                if ($x->gt($a)) {
                    break;
                }
                $res[] = $x;
            }
        } catch (Throwable) {
            return [];
        }

        return $res;
    }
}
