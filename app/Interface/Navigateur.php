<?php

namespace App\Interface;

/**
 * « Firefox · Windows » à partir du user agent (sans dépendance) : sessions et appareils de confiance (Compte).
 */
final class Navigateur
{
    public static function libelle(string $ua): string
    {
        $nav = match (true) {
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            $ua === '' => 'Navigateur inconnu',
            default => 'Autre navigateur',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os ? "{$nav} · {$os}" : $nav;
    }
}
