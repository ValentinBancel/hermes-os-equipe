<?php

namespace App\Interface;

use App\Models\User;

/**
 * Présence affichée dans la barre haute (« ● N connectés ») et initiales des avatars.
 *
 * « Connecté » = compte actif dont la dernière activité date de moins de 5 minutes (L3, décision C3). Le compte
 * courant compte toujours (sa requête vient de noter son activité).
 */
final class Presence
{
    public static function connectes(): int
    {
        if (! auth()->check()) {
            return 0;
        }

        return max(1, User::query()->connectes()->count());
    }

    public static function libelle(int $n): string
    {
        return $n."\u{00A0}".($n > 1 ? 'connectés' : 'connecté');
    }

    /** « Camille Martin » → « CM » ; « camille » → « CA ». */
    public static function initiales(?string $nom): string
    {
        $mots = preg_split('/[\s\-_.@]+/u', trim((string) $nom), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $initiales = match (true) {
            $mots === [] => '?',
            count($mots) === 1 => mb_substr($mots[0], 0, 2),
            default => mb_substr($mots[0], 0, 1).mb_substr($mots[count($mots) - 1], 0, 1),
        };

        return mb_strtoupper($initiales);
    }

    public static function initialesDe(?User $user): string
    {
        return self::initiales($user?->name ?: $user?->email);
    }
}
