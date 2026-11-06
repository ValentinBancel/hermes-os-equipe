<?php

namespace App\Hermes;

use App\Hermes\Exceptions\PontErreur;

/**
 * Routes nommées du pont, communes aux deux clients : chaque méthode construit le chemin
 * `/v1/...` (segments validés et encodés) et délègue à `lire()`.
 */
trait RoutesDuPont
{
    public function sante(): Lecture
    {
        return $this->lire('/v1/sante');
    }

    public function gateway(): Lecture
    {
        return $this->lire('/v1/gateway');
    }

    public function aDecider(): Lecture
    {
        return $this->lire('/v1/a-decider');
    }

    public function planifiees(): Lecture
    {
        return $this->lire('/v1/cron');
    }

    public function planifiee(string $id): Lecture
    {
        return $this->lire('/v1/cron/'.$this->segment($id, '/^[a-f0-9]{12}$/'));
    }

    public function sortie(string $id, string $nom): Lecture
    {
        return $this->lire('/v1/cron/'.$this->segment($id, '/^[a-f0-9]{12}$/')
            .'/sorties/'.$this->segment($nom, '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.md$/'));
    }

    public function consommation(int $jours = 30): Lecture
    {
        return $this->lire('/v1/consommation', ['jours' => max(1, min(365, $jours))]);
    }

    public function sessions(int $limite = 100): Lecture
    {
        return $this->lire('/v1/consommation/sessions', ['limite' => max(1, min(500, $limite))]);
    }

    public function poles(): Lecture
    {
        return $this->lire('/v1/poles');
    }

    public function erreurs(int $heures = 24): Lecture
    {
        return $this->lire('/v1/erreurs', ['heures' => max(1, min(168, $heures))]);
    }

    public function vault(): Lecture
    {
        return $this->lire('/v1/vault');
    }

    public function messagerie(): Lecture
    {
        return $this->lire('/v1/messagerie');
    }

    public function conversationMessagerie(string $conversation, int $depuis = 0): Lecture
    {
        return $this->lire('/v1/messagerie/'.$this->segment($conversation, '/^c_[a-f0-9]{16}$/'), ['depuis' => max(0, $depuis)]);
    }

    /**
     * Un segment d'URL venu de l'interface (paramètre de route) : refusé avant tout appel s'il
     * n'a pas la forme attendue. Le pont applique les mêmes règles (défense en profondeur).
     */
    private function segment(string $valeur, string $motif = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/'): string
    {
        if (preg_match($motif, $valeur) !== 1 || str_contains($valeur, '..')) {
            throw new PontErreur(404, 'Introuvable.');
        }

        return rawurlencode($valeur);
    }
}
