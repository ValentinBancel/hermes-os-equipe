<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Interface\Decisions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;

/**
 * Pôles : organigramme. La direction en haut, Hermes (profil default) dessous, puis un manager (lead) par pôle avec son
 * équipe (exécutants, auditeurs, support) en colonne sous lui. Les pôles sans manager sont rattachés directement à
 * Hermes. Le détail des skills reste sur la fiche profil : l'organigramme ne montre que qui rend compte à qui.
 */
#[Title('Pôles — hermes-os')]
class Poles extends Page
{
    protected string $actif = 'poles';

    public function render(): View
    {
        $releve = $this->releve();
        $d = $releve->donnees(fn (HermesClient $c) => $c->poles());
        $profils = array_column($d['profils'] ?? [], null, 'profil');

        // Session active la plus récente par profil (repère « en cours »).
        $enCours = [];
        foreach (Decisions::enCours($releve->donnees(fn (HermesClient $c) => $c->sessions())['sessions'] ?? [], CarbonImmutable::now()) as $s) {
            $enCours[$s['profil']] ??= $s;
        }

        // Pôles non vides ; ceux qui ont un manager d'abord (plus grosse équipe à gauche), puis les autres.
        $taille = fn (array $p) => count($p['executants'] ?? []) + count($p['auditeurs'] ?? []) + count($p['support'] ?? []);
        $poles = array_values(array_filter($d['poles'] ?? [], fn (array $p) => ($p['lead'] ?? null) !== null || $taille($p) > 0));
        usort($poles, fn (array $a, array $b) => [($a['lead'] ?? null) === null, -$taille($a), $a['pole']]
            <=> [($b['lead'] ?? null) === null, -$taille($b), $b['pole']]);

        // Profils rattachés à aucun pôle (hors default) : sous Hermes, pour ne perdre personne.
        $places = ['default' => true];
        foreach ($poles as $p) {
            foreach ([$p['lead'] ?? null, ...($p['executants'] ?? []), ...($p['auditeurs'] ?? []), ...($p['support'] ?? [])] as $nom) {
                if ($nom !== null) {
                    $places[$nom] = true;
                }
            }
        }
        $horsPole = array_values(array_filter($profils, fn (array $p) => ! isset($places[$p['profil']])));

        return $this->page('livewire.poles', [
            'poles' => $poles,
            'profils' => $profils,
            'hermes' => $profils['default'] ?? ['profil' => 'default', 'modele' => null],
            'horsPole' => $horsPole,
            'enCours' => $enCours,
            'communs' => $d['skills_communs'] ?? [],
        ]);
    }
}
