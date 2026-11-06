<?php

namespace App\Hermes;

use App\Hermes\Demo\JeuDeDemonstration;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use Carbon\CarbonImmutable;

/**
 * Client de démonstration (HERMES_PONT_DRIVER=demo, défaut) : jeu de données figé et réaliste
 * (relevé du 08/10/2026, sans secret), mêmes formes de réponse que le pont. Aucun accès à Hermes :
 * un clone neuf et la CI tournent sans rien d'autre.
 *
 * HERMES_DEMO_ETAT simule les états de l'accueil (lot 06, T1) :
 * - `nominal` (défaut) : relevé du 08/10 (1 erreur, 2 décisions, 2 sessions actives) ;
 * - `tout_tourne` : rien à décider, aucune erreur (accueil « Tout tourne. ») ;
 * - `gateway_arrete` : le gateway ne tourne plus ;
 * - `pont_injoignable` : lectures périmées de 2 min, actions refusées.
 */
final class DemoClient implements HermesClient
{
    use RoutesDuPont;

    public const ETATS = ['nominal', 'tout_tourne', 'gateway_arrete', 'pont_injoignable'];

    private readonly JeuDeDemonstration $jeu;

    public function __construct(private readonly string $etat = 'nominal')
    {
        $this->jeu = new JeuDeDemonstration($etat === 'gateway_arrete', $etat === 'tout_tourne');
    }

    public function pilote(): string
    {
        return 'demo';
    }

    public function lire(string $chemin, array $requete = []): Lecture
    {
        $segments = array_map('rawurldecode', array_values(array_filter(explode('/', $chemin), 'strlen')));

        if (($segments[0] ?? null) !== 'v1') {
            throw new PontErreur(404, 'Introuvable.');
        }

        $donnees = $this->router(array_slice($segments, 1), $requete);

        if ($this->etat === 'pont_injoignable') {
            return new Lecture($donnees, CarbonImmutable::now()->subMinutes(2), true, 'Pont injoignable.');
        }

        return new Lecture($donnees, CarbonImmutable::now());
    }

    public function action(Action $action, array $donnees): ResultatAction
    {
        if ($this->etat === 'pont_injoignable') {
            throw new PontInjoignable('Pont injoignable.');
        }

        $this->jeu->verifierAction($action, $donnees);

        return new ResultatAction(
            action: $action,
            ok: true,
            code: 0,
            sortie: $action === Action::MessagerieEnvoyer
                ? (string) ($donnees['conversation'] ?? JeuDeDemonstration::conversationNouvelle((string) ($donnees['interlocuteur'] ?? '')))
                : 'Mode démonstration : aucune action envoyée à Hermes.',
        );
    }

    public function oublierCache(): void {}

    /**
     * @param  list<string>  $s
     * @param  array<string, int|string>  $q
     * @return array<string, mixed>
     */
    private function router(array $s, array $q): array
    {
        $n = count($s);

        return match (true) {
            $s === ['sante'] => $this->jeu->sante(),
            $s === ['gateway'] => $this->jeu->gateway(),
            $s === ['a-decider'] => $this->jeu->aDecider(),
            $s === ['cron'] => $this->jeu->planifiees(),
            $n === 2 && $s[0] === 'cron' => $this->jeu->planifiee($s[1]),
            $n === 4 && $s[0] === 'cron' && $s[2] === 'sorties' => $this->jeu->sortie($s[1], $s[3]),
            $s === ['consommation'] => $this->jeu->consommation((int) ($q['jours'] ?? 30)),
            $s === ['consommation', 'sessions'] => $this->jeu->sessions((int) ($q['limite'] ?? 100)),
            $s === ['poles'] => $this->jeu->poles(),
            $s === ['vault'] => $this->jeu->vault(),
            $s === ['messagerie'] => $this->jeu->messagerie(),
            $n === 2 && $s[0] === 'messagerie' => $this->jeu->conversationMessagerie($s[1], (int) ($q['depuis'] ?? 0)),
            $s === ['erreurs'] => $this->jeu->erreurs((int) ($q['heures'] ?? 24)),
            default => throw new PontErreur(404, 'Introuvable.'),
        };
    }
}
