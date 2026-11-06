<?php

namespace App\Interface;

use Carbon\CarbonImmutable;

/**
 * Règles « À décider » et « En cours » de l'accueil (P2, mission console-multi) :
 * - « À décider » = éléments de /v1/a-decider (pont) : approbations en attente et questions posées par un agent
 *   dans la Messagerie, de la plus ancienne à la plus récente ; c'est ce que compte la pastille Accueil ;
 * - « En cours » = sessions d'agents non terminées, actives depuis moins de {@see self::FENETRE_ACTIVITE} s.
 */
final class Decisions
{
    /** Au-delà de 15 min sans activité, une session non close n'est plus « en cours ». */
    public const FENETRE_ACTIVITE = 900;

    /** @param  array<string, mixed>  $element */
    public static function motif(array $element): string
    {
        return ($element['type'] ?? null) === 'approbation' ? 'Approbation' : 'Question';
    }

    /**
     * Lien vers la conversation de la Messagerie (ou l'ancre de la section si la Messagerie est désactivée).
     *
     * @param  array<string, mixed>  $element
     */
    public static function lien(array $element): string
    {
        if (! config('hermes-os.messagerie')) {
            return '#ad';
        }

        return route('messagerie.conversation', [(string) $element['interlocuteur'], (string) $element['conversation']]);
    }

    /**
     * Sessions actives : non terminées, avec une activité depuis moins de FENETRE_ACTIVITE s ; la plus récente d'abord.
     *
     * @param  list<array<string, mixed>>  $sessions
     * @return list<array<string, mixed>>
     */
    public static function enCours(array $sessions, CarbonImmutable $maintenant): array
    {
        $seuil = $maintenant->subSeconds(self::FENETRE_ACTIVITE);
        $res = array_values(array_filter($sessions, function (array $s) use ($seuil) {
            $activite = Temps::lire($s['derniere_activite'] ?? $s['demarre_a'] ?? null);

            return ($s['termine_a'] ?? null) === null && $activite !== null && $activite->gte($seuil);
        }));
        usort($res, fn (array $a, array $b) => strcmp((string) ($b['derniere_activite'] ?? ''), (string) ($a['derniere_activite'] ?? '')));

        return $res;
    }

    /**
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    public static function decisions(array $elements): array
    {
        usort($elements, fn (array $a, array $b) => strcmp((string) $a['cree_a'], (string) $b['cree_a']));

        return array_values($elements);
    }
}
