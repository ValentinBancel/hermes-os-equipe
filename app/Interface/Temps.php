<?php

namespace App\Interface;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Formateur de temps UNIQUE de l'interface (decisions.md § v1, M1 ; ux/03 § Dates).
 *
 * - Données fraîches : relatif sous 24 h (« il y a 3 min », « dans 7 min »), puis « hier 18:02 », puis « 07/10 18:02 ».
 * - Données figées (pont injoignable, `Lecture::perimee`) : JAMAIS de relatif, heures absolues partout
 *   (un « il y a 2 min » calculé sur des données de 14:23 serait faux). Seul le bandeau du pont garde un relatif.
 *
 * Fuseau d'affichage : config('hermes-os.fuseau'), Europe/Paris par défaut.
 */
final class Temps
{
    private const JOURS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    private const JOURS_LONGS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    public static function fuseau(): string
    {
        return (string) config('hermes-os.fuseau', 'Europe/Paris');
    }

    public static function maintenant(): CarbonImmutable
    {
        return CarbonImmutable::now(self::fuseau());
    }

    public static function lire(CarbonInterface|string|int|null $t): ?CarbonImmutable
    {
        if ($t === null || $t === '') {
            return null;
        }

        try {
            $date = match (true) {
                $t instanceof CarbonInterface => CarbonImmutable::instance($t),
                is_int($t) => CarbonImmutable::createFromTimestamp($t),
                default => CarbonImmutable::parse($t),
            };
        } catch (\Throwable) {
            return null;
        }

        return $date->setTimezone(self::fuseau());
    }

    /**
     * Moment passé ou futur : relatif sous 24 h si les données sont fraîches, absolu sinon.
     */
    public static function quand(CarbonInterface|string|int|null $t, bool $fige = false): string
    {
        $d = self::lire($t);
        if ($d === null) {
            return '—';
        }

        if ($fige) {
            return self::absolu($d);
        }

        $secondes = (int) round(self::maintenant()->diffInSeconds($d, absolute: false));
        if (abs($secondes) >= 86400) {
            return self::absolu($d);
        }

        return self::relatif($d);
    }

    /**
     * Toujours relatif (« il y a 3 min », « dans 7 min ») : réservé au bandeau du pont et à la fraîcheur.
     */
    public static function relatif(CarbonInterface|string|int|null $t): string
    {
        $d = self::lire($t);
        if ($d === null) {
            return '—';
        }

        $secondes = (int) round(self::maintenant()->diffInSeconds($d, absolute: false));
        $futur = $secondes > 0;
        $duree = self::duree(abs($secondes));

        if (abs($secondes) < 5) {
            return 'à l’instant';
        }

        return $futur ? "dans {$duree}" : "il y a {$duree}";
    }

    /**
     * Durée lisible : « 42 s », « 7 min », « 7 h 37 », « 3 j ».
     */
    public static function duree(int $secondes): string
    {
        // D7 : insécables entre nombre et unité (« 7 h 37 » ne se coupe pas).
        return Libelles::insecables(match (true) {
            $secondes < 60 => "{$secondes} s",
            $secondes < 3600 => intdiv($secondes, 60).' min',
            $secondes < 86400 => intdiv($secondes, 3600).' h'.(($m = intdiv($secondes % 3600, 60)) > 0 ? "\u{00A0}".str_pad((string) $m, 2, '0', STR_PAD_LEFT) : ''),
            default => intdiv($secondes, 86400).' j',
        });
    }

    /**
     * « 14:22 » (aujourd'hui), « hier 18:02 », « demain 08:00 », sinon « 07/10 18:02 ».
     */
    public static function absolu(CarbonInterface|string|int|null $t): string
    {
        $d = self::lire($t);
        if ($d === null) {
            return '—';
        }

        $jour = self::jourRelatif($d);

        return match ($jour) {
            'aujourd’hui' => $d->format('H:i'),
            null => $d->format('d/m H:i'),
            default => $jour.' '.$d->format('H:i'),
        };
    }

    /** « 14:22 » seul (colonnes d'heures au pochoir). */
    public static function heure(CarbonInterface|string|int|null $t): string
    {
        return self::lire($t)?->format('H:i') ?? '—';
    }

    /** « aujourd’hui », « hier », « demain », ou null au-delà. */
    public static function jourRelatif(CarbonInterface|string|int|null $t): ?string
    {
        $d = self::lire($t);
        if ($d === null) {
            return null;
        }

        $ecart = (int) self::maintenant()->startOfDay()->diffInDays($d->startOfDay(), absolute: false);

        return match ($ecart) {
            0 => 'aujourd’hui',
            -1 => 'hier',
            1 => 'demain',
            default => null,
        };
    }

    /** Sous-ligne d'une heure au pochoir : « aujourd’hui », « hier », « mer. 14/10 ». */
    public static function jour(CarbonInterface|string|int|null $t): string
    {
        $d = self::lire($t);
        if ($d === null) {
            return '';
        }

        return self::jourRelatif($d) ?? self::JOURS[$d->dayOfWeek].' '.$d->format('d/m');
    }

    /** « 08/10 » */
    public static function date(CarbonInterface|string|int|null $t): string
    {
        return self::lire($t)?->format('d/m') ?? '—';
    }

    /** « 3 novembre 2026 », « 1er octobre 2026 » : date en lettres, avec l'année. */
    public static function dateLongue(CarbonInterface|string|int|null $t): string
    {
        $d = self::lire($t);

        return $d === null ? '—' : ($d->day === 1 ? '1er' : $d->day).' '.self::MOIS[$d->month - 1].' '.$d->year;
    }

    /** « 08/10/2026 14:22 » : info-bulle et attribut title des <time>. */
    public static function complet(CarbonInterface|string|int|null $t): string
    {
        return self::lire($t)?->format('d/m/Y H:i:s') ?? '';
    }

    /** ISO 8601 pour l'attribut datetime. */
    public static function iso(CarbonInterface|string|int|null $t): string
    {
        return self::lire($t)?->toIso8601String() ?? '';
    }

    /** Étiquette du jour : « Jeudi 8 octobre ». */
    public static function etiquette(?CarbonInterface $t = null): string
    {
        $d = $t === null ? self::maintenant() : self::lire($t);

        return ucfirst(self::JOURS_LONGS[$d->dayOfWeek]).' '.$d->day.' '.self::MOIS[$d->month - 1];
    }

    /** « jeu. 8 » (repère de minuit de la frise). */
    public static function jourCourt(CarbonInterface|string|int|null $t): string
    {
        $d = self::lire($t);

        return $d === null ? '' : self::JOURS[$d->dayOfWeek].' '.$d->day;
    }
}
