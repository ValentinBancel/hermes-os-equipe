<?php

namespace App\Interface;

/**
 * Libellés et codage des statuts (ux/03 § 1) : toujours forme + mot + couleur, jamais la couleur seule.
 * Une valeur inconnue s'affiche brute entre crochets (« [suspended] »), jamais masquée.
 *
 * Formes (sprite) : s-ok ● · s-cours ◐ · s-attention ▲ · s-arret ■ · s-inactif ○ · s-relire ◆ · s-fait ✓.
 */
final class Libelles
{
    public const COULEURS = [
        's-attention' => 'text-ocre',
        's-arret' => 'text-vermillon',
        's-cours' => 'text-indigo',
        's-ok' => 'text-vert',
        's-inactif' => 'text-second',
        's-fait' => 'text-second',
        's-relire' => 'text-indigo',
    ];

    public const GATEWAY = [
        'running' => ['En marche', 's-ok'],
        'starting' => ['Démarrage…', 's-cours'],
        'degraded' => ['Dégradé', 's-attention'],
        'startup_failed' => ['Échec au démarrage', 's-arret'],
        'stopped' => ['Arrêté', 's-arret'],
    ];

    public const PLATEFORMES = [
        'connected' => ['connecté', 's-ok'],
        'connecting' => ['connexion…', 's-cours'],
        'retrying' => ['reconnexion…', 's-cours'],
        'disconnected' => ['déconnecté', 's-inactif'],
        'fatal' => ['erreur fatale', 's-arret'],
        'paused' => ['en pause', 's-inactif'],
        'disabled' => ['désactivé', 's-inactif'],
    ];

    public const ETATS_TACHE = [
        'scheduled' => 'Active',
        'paused' => 'En pause',
        'running' => 'En cours d’exécution',
        'completed' => 'Terminée',
        'error' => 'En erreur',
    ];

    public const DERNIER_STATUT = [
        'ok' => ['OK', 's-ok'],
        'delivery_failed' => ['Échec de livraison', 's-arret'],
        'error' => ['Échec', 's-arret'],
        'failed' => ['Échec', 's-arret'],
        'timeout' => ['Délai dépassé', 's-arret'],
    ];

    /** @return array{0: string, 1: string} */
    public static function gateway(?string $etat): array
    {
        return self::GATEWAY[$etat] ?? ['Inconnu ['.($etat ?? '?').']', 's-attention'];
    }

    /** @return array{0: string, 1: string} */
    public static function plateforme(?string $etat): array
    {
        return self::PLATEFORMES[$etat] ?? ['['.($etat ?? '?').']', 's-attention'];
    }

    /** @return array{0: string, 1: string} */
    public static function dernierStatut(?string $statut): array
    {
        if ($statut === null || $statut === '') {
            return ['Jamais exécutée', 's-inactif'];
        }

        return self::DERNIER_STATUT[$statut] ?? ['['.$statut.']', 's-attention'];
    }

    public static function couleur(string $forme): string
    {
        return self::COULEURS[$forme] ?? 'text-second';
    }

    /** Nom affiché d'une plateforme : « discord » → « Discord ». */
    public static function nomPlateforme(string $nom): string
    {
        return ucfirst($nom);
    }

    /**
     * D7 : insécable entre un nombre et son unité (« 24 h », « 0-24 h », « 11,3 M », « 7 j », « 15 min ») pour qu'une
     * unité ne parte jamais seule à la ligne. Idempotent ; ne touche que les unités de la liste.
     */
    public static function insecables(string $texte): string
    {
        return (string) preg_replace('/(\d)[ \x{00A0}](min|h|j|s|k|M|Mo|ko|o|%|\$)(?![\p{L}\d])/u', "\$1\u{00A0}\$2", $texte);
    }

    /** Lien « Écrire à » : ouvre la messagerie sur une nouvelle conversation, texte pré-rempli, jamais envoyé (GET). */
    public static function lienEcrire(string $manager, string $texte): string
    {
        return route('messagerie.interlocuteur', ['interlocuteur' => $manager, 'nouvelle' => 1, 'texte' => mb_substr($texte, 0, 300)]);
    }

    /** « aucun agent » quand aucun profil n'est indiqué (C7). */
    public static function assigne(?string $assignee): string
    {
        return $assignee === null || $assignee === '' ? 'aucun agent' : $assignee;
    }
}
