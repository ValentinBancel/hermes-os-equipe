<?php

namespace App\Livewire;

use App\Hermes\HermesClient;
use App\Interface\Temps;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Consommation (ux/03 § Consommation, M8) : tokens hors cache d'abord, graphe par jour sans faux zéros
 * (avant la 1ʳᵉ session : zone hachurée « pas de données avant le JJ/MM »), part hors cache sur 100 % réels.
 */
#[Title('Consommation — hermes-os')]
class Consommation extends Page
{
    protected string $actif = 'consommation';

    #[Url(as: 'periode', except: '30j')]
    public string $periode = '30j';

    public function render(): View
    {
        $jours = match ($this->periode) {
            '7j' => 7,
            'mois' => Temps::maintenant()->day,
            default => 30,
        };
        if (! in_array($this->periode, ['7j', '30j', 'mois'], true)) {
            $this->periode = '30j';
        }

        $c = $this->releve()->donnees(fn (HermesClient $client) => $client->consommation($jours));
        $horsCache = fn (array $a) => (int) ($a['entree'] ?? 0) + (int) ($a['sortie'] ?? 0);

        $parJour = array_map(fn (array $j) => ['date' => (string) $j['date'], 'valeur' => $horsCache($j), 'sessions' => (int) $j['sessions']], $c['par_jour'] ?? []);
        // M8 : avant la première session, il n'y a pas de données (pas des zéros).
        $premier = null;
        foreach ($parJour as $i => $j) {
            if ($j['sessions'] > 0 || $j['valeur'] > 0) {
                $premier = $i;
                break;
            }
        }

        $total = $c['total'] ?? [];
        $totalHc = $total === [] ? 0 : $horsCache($total);
        $trier = function (array $lignes, string $cle) use ($horsCache, $totalHc): array {
            $res = array_map(fn (array $l) => [
                'nom' => (string) $l[$cle],
                'sessions' => (int) $l['sessions'],
                'hors_cache' => $horsCache($l),
                'cache' => (int) $l['cache_lecture'] + (int) $l['cache_ecriture'],
                'cout' => (float) $l['cout_estime_usd'],
                'part' => $totalHc > 0 ? $horsCache($l) / $totalHc * 100 : 0.0,
            ], $lignes);
            usort($res, fn (array $a, array $b) => $b['hors_cache'] <=> $a['hors_cache']);

            return $res;
        };

        $pic = $parJour === [] ? null : collect($parJour)->sortByDesc('valeur')->first();

        return $this->page('livewire.consommation', [
            'c' => $c,
            'parJour' => $parJour,
            'premier' => $premier,
            'pic' => $pic && $pic['valeur'] > 0 ? $pic : null,
            'total' => $total,
            'totalHc' => $totalHc,
            'cache' => $total === [] ? 0 : (int) $total['cache_lecture'] + (int) $total['cache_ecriture'],
            'profils' => $trier($c['par_profil'] ?? [], 'profil'),
            'modeles' => $trier($c['par_modele'] ?? [], 'modele'),
            'sources' => $trier($c['par_source'] ?? [], 'source'),
        ]);
    }
}
