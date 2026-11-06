<?php

namespace App\Hermes;

use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;

/**
 * Accès aux données de Hermes (ADR 0002). Deux implémentations, choisies par HERMES_PONT_DRIVER :
 * - `PontClient` (`pont`) : socket Unix du pont hôte, requêtes signées HMAC, cache 3-5 s ;
 * - `DemoClient` (`demo`, défaut) : jeu de données figé, aucun accès à Hermes (clone neuf, CI).
 *
 * Les formes des réponses sont celles du pont (`pont/contrat.json`, vérifié par les tests des deux côtés).
 *
 * Toute lecture peut lever PontInjoignable (pont muet et aucune donnée connue) ou PontErreur (404/422…).
 * Les actions ne s'appellent pas directement depuis l'interface : passer par ExecuteurActions
 * (TOTP < 15 min, 20/min, journal d'audit).
 */
interface HermesClient
{
    /** Nom du pilote : `pont` ou `demo`. */
    public function pilote(): string;

    /**
     * Lecture brute d'une route GET du pont (ex. `/v1/cron`).
     *
     * @param  array<string, int|string>  $requete
     *
     * @throws PontInjoignable
     * @throws PontErreur
     */
    public function lire(string $chemin, array $requete = []): Lecture;

    /**
     * @param  array<string, mixed>  $donnees
     *
     * @throws PontInjoignable
     * @throws PontErreur
     */
    public function action(Action $action, array $donnees): ResultatAction;

    /** Invalide le cache de lecture (après une action, pour voir son effet tout de suite). */
    public function oublierCache(): void;

    public function sante(): Lecture;

    public function gateway(): Lecture;

    public function aDecider(): Lecture;

    public function planifiees(): Lecture;

    public function planifiee(string $id): Lecture;

    public function sortie(string $id, string $nom): Lecture;

    public function consommation(int $jours = 30): Lecture;

    public function sessions(int $limite = 100): Lecture;

    public function poles(): Lecture;

    public function erreurs(int $heures = 24): Lecture;

    /** Coffres (vault) de Hermes, un par profil : métadonnées seulement, jamais de valeur secrète. */
    public function vault(): Lecture;

    /** Interlocuteurs (managers) et conversations en cours. */
    public function messagerie(): Lecture;

    /** Événements d'une conversation `c_…` à partir du numéro `$depuis`. */
    public function conversationMessagerie(string $conversation, int $depuis = 0): Lecture;
}
