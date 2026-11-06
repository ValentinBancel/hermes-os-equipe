<?php

namespace App\Livewire\Concerns;

use App\Hermes\Action;
use App\Interface\Erreurs;
use App\Interface\Temps;

/**
 * Lancer / Mettre en pause / Reprendre une tâche planifiée : mêmes confirmations (et même étape TOTP) depuis la
 * fiche et depuis la liste Planifiées.
 */
trait ActionsTache
{
    /** @param array<string, mixed> $t */
    protected function confirmerLancement(string $id, array $t): void
    {
        $prochaine = $t['prochaine_execution'] ?? null;
        $avertissement = ($t['dernier_statut'] ?? null) === 'delivery_failed'
            ? 'Le dernier envoi a échoué ('.Erreurs::courte($t['erreur_livraison'] ?? '', 'delivery_failed').') : la livraison échouera sans doute à nouveau.'
            : null;

        $this->ouvrirConfirmation(Action::CronLancer, ['job_id' => $id],
            'Lancer « '.($t['nom'] ?? $id).' » maintenant ? Le planning normal n’est pas modifié'.($prochaine ? ' (prochaine : '.Temps::absolu($prochaine).')' : '').'.',
            'lancer', $avertissement);
    }

    /** @param array<string, mixed> $t */
    protected function confirmerPause(string $id, array $t): void
    {
        $prochaine = $t['prochaine_execution'] ?? null;
        $this->ouvrirConfirmation(Action::CronSuspendre, ['job_id' => $id],
            'Mettre en pause « '.($t['nom'] ?? $id).' » ? Les exécutions prévues n’auront pas lieu tant qu’elle n’est pas reprise'.($prochaine ? ' (prochaine annulée : '.Temps::absolu($prochaine).')' : '').'.',
            'mettre en pause');
    }

    /** @param array<string, mixed> $t */
    protected function confirmerReprise(string $id, array $t): void
    {
        $this->ouvrirConfirmation(Action::CronReprendre, ['job_id' => $id],
            'Reprendre « '.($t['nom'] ?? $id).' » ? Les exécutions manquées ne sont pas rattrapées.',
            'reprendre');
    }
}
