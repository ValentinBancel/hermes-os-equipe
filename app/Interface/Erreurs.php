<?php

namespace App\Interface;

/**
 * Traduction des erreurs connues en clair (ux/03 § Planifiées). Repli : le texte brut (déjà masqué par le pont).
 */
final class Erreurs
{
    public static function enClair(?string $brute, ?string $statut = null): string
    {
        $b = trim((string) $brute);

        if (preg_match('/unknown channel|10003|salon introuvable|channel not found/i', $b) === 1) {
            return 'Le résultat a été produit mais n’a pas pu être envoyé : salon Discord introuvable (supprimé ou mauvais identifiant). À corriger côté Hermes : salon de livraison.';
        }

        if ($statut === 'timeout' || preg_match('/timed? ?out|délai/i', $b) === 1) {
            return 'L’exécution a dépassé le délai autorisé.';
        }

        if ($b === '') {
            return match ($statut) {
                'delivery_failed' => 'Le résultat a été produit mais n’a pas pu être livré.',
                null, '', 'ok' => '',
                default => 'Erreur non reconnue.',
            };
        }

        return $b;
    }

    /** Courte (mobile, accueil) : « salon Discord introuvable ». */
    public static function courte(?string $brute, ?string $statut = null): string
    {
        $b = (string) $brute;

        return match (true) {
            preg_match('/unknown channel|10003|salon (Discord )?introuvable|channel not found/i', $b) === 1 => 'salon Discord introuvable',
            preg_match('/^Gateway arrêté/u', $b) === 1 => 'plus aucun message Discord traité',
            $statut === 'timeout' || preg_match('/délai autorisé/u', $b) === 1 => 'délai dépassé',
            default => mb_strimwidth(self::enClair($b, $statut), 0, 60, '…'),
        };
    }
}
