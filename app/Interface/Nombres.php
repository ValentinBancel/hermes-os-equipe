<?php

namespace App\Interface;

/**
 * Nombres : tokens « 2,0 M », « 736 k », « 30,1 k » (espace insécable, virgule décimale) ; dollars « 12,40 $ ».
 */
final class Nombres
{
    public static function tokens(int|float $n, bool $precis = false): string
    {
        $n = (float) $n;

        return match (true) {
            $n < 1000 => self::fr($n, 0),
            $n < 10_000 || ($precis && $n < 1_000_000) => self::fr($n / 1000, 1)."\u{00A0}k",
            $n < 1_000_000 => self::fr($n / 1000, 0)."\u{00A0}k",
            $n < 10_000_000 || $precis => self::fr($n / 1_000_000, 1)."\u{00A0}M",
            default => self::fr($n / 1_000_000, 0)."\u{00A0}M",
        };
    }

    public static function entier(int|float $n): string
    {
        return self::fr((float) $n, 0);
    }

    public static function dollars(float $usd): string
    {
        return self::fr($usd, 2)."\u{00A0}\$";
    }

    public static function pourcent(float $p, int $decimales = 1): string
    {
        return self::fr($p, $decimales)."\u{00A0}%";
    }

    public static function octets(int $o): string
    {
        return match (true) {
            $o < 1024 => $o."\u{00A0}o",
            $o < 1024 * 1024 => self::fr($o / 1024, 1)."\u{00A0}ko",
            default => self::fr($o / 1024 / 1024, 1)."\u{00A0}Mo",
        };
    }

    /**
     * Accord en nombre (m7) : « 0 erreur », « 1 erreur », « 2 erreurs » (règle française : 0 et 1 au singulier).
     */
    public static function accord(int $n, string $singulier, string $pluriel, bool $avecNombre = true): string
    {
        $mot = trans_choice('[0,1] '.$singulier.'|[2,*] '.$pluriel, $n);

        return $avecNombre ? $n."\u{00A0}".$mot : $mot;
    }

    /**
     * D8 : échelle d'un graphe en barres avec « barre coupée ». Si le maximum dépasse 8 × la médiane (valeurs > 0),
     * l'échelle est plafonnée à 3 × la médiane : les barres au-delà sont dessinées pleines, coupées « // », valeur
     * écrite. Sinon l'échelle est le maximum.
     *
     * @param  list<int|float>  $valeurs
     * @return array{echelle: float, coupe: bool}
     */
    public static function echelle(array $valeurs): array
    {
        $positifs = array_values(array_filter($valeurs, fn ($v) => $v > 0));
        $max = (float) max([1, ...$positifs]);
        if (count($positifs) < 3) {
            return ['echelle' => $max, 'coupe' => false];
        }
        sort($positifs);
        $n = count($positifs);
        $mediane = $n % 2 ? (float) $positifs[intdiv($n, 2)] : ($positifs[$n / 2 - 1] + $positifs[$n / 2]) / 2;

        return $max > 8 * $mediane
            ? ['echelle' => 3 * $mediane, 'coupe' => true]
            : ['echelle' => $max, 'coupe' => false];
    }

    public static function fr(float $n, int $decimales): string
    {
        return number_format($n, $decimales, ',', "\u{202F}");
    }
}
