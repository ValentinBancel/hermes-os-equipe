<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Interface\Planification;
use App\Livewire\Concerns\ActionsTache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;

/**
 * Tâches planifiées (cron/jobs.json via le pont) : En échec en tête, Actives, En pause, Désactivées repliées (m10).
 */
#[Title('Planifiées — hermes-os')]
class Planifiees extends Page
{
    use ActionsTache;

    protected string $actif = 'planifiees';

    public function render(): View
    {
        $taches = $this->releve()->donnees(fn (HermesClient $c) => $c->planifiees())['taches'] ?? [];
        $groupes = ['echec' => [], 'active' => [], 'pause' => [], 'off' => []];
        foreach ($taches as $t) {
            $groupes[Planification::groupe($t)][] = $t;
        }
        foreach (['echec', 'active', 'pause'] as $g) {
            usort($groupes[$g], fn (array $a, array $b) => strcmp((string) ($a['prochaine_execution'] ?? 'z'), (string) ($b['prochaine_execution'] ?? 'z')));
        }

        return $this->page('livewire.planifiees', ['groupes' => $groupes, 'total' => count($taches)]);
    }

    /** @return array<string, mixed>|null Tâche de la liste courante (id inconnu = null : rien n'est proposé). */
    private function tache(string $id): ?array
    {
        foreach ($this->releve()->donnees(fn (HermesClient $c) => $c->planifiees())['taches'] ?? [] as $t) {
            if (($t['id'] ?? null) === $id) {
                return $t;
            }
        }

        return null;
    }

    public function demanderLancement(string $id): void
    {
        if ($t = $this->tache($id)) {
            $this->confirmerLancement($id, $t);
        }
    }

    public function demanderPause(string $id): void
    {
        if ($t = $this->tache($id)) {
            $this->confirmerPause($id, $t);
        }
    }

    public function demanderReprise(string $id): void
    {
        if ($t = $this->tache($id)) {
            $this->confirmerReprise($id, $t);
        }
    }
}
