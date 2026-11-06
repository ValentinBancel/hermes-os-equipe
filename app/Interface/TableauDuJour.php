<?php

namespace App\Interface;

use App\Hermes\HermesClient;
use Carbon\CarbonImmutable;

/**
 * Données de l'accueil « Le tableau du jour » (ux/03 § Accueil, disposition 1), assemblées à partir des lectures
 * du pont uniquement. Ordre P1 unique : Erreurs → À décider → En cours (2.6).
 */
final class TableauDuJour
{
    /** @var array<string, mixed> */
    public array $gateway;

    /** @var list<array<string, mixed>> */
    public array $erreurs = [];

    /** @var list<array<string, mixed>> */
    public array $decisions = [];

    /** @var list<array<string, mixed>> */
    public array $enCours = [];

    /** @var list<array<string, mixed>> */
    public array $prochaines = [];

    /** @var list<array{nom: string, etat: string, forme: string, echecs: int}> */
    public array $plateformes = [];

    /** @var array<string, mixed> */
    public array $tokens = [];

    public Frise $frise;

    public int $nbProfils = 0;

    public int $nbTaches = 0;

    public CarbonImmutable $maintenant;

    public function __construct(private readonly Releve $releve)
    {
        $this->maintenant = Temps::maintenant();

        $this->gateway = $releve->donnees(fn (HermesClient $c) => $c->gateway());
        $journal = $releve->donnees(fn (HermesClient $c) => $c->erreurs(24));
        $taches = $releve->donnees(fn (HermesClient $c) => $c->planifiees())['taches'] ?? [];
        $aDecider = $releve->donnees(fn (HermesClient $c) => $c->aDecider())['elements'] ?? [];
        $sessions = $releve->donnees(fn (HermesClient $c) => $c->sessions())['sessions'] ?? [];

        $this->nbTaches = count($taches);
        $this->nbProfils = count($releve->donnees(fn (HermesClient $c) => $c->poles())['profils'] ?? []);

        $this->erreurs = $this->erreurs($journal['entrees'] ?? [], $taches);
        $this->decisions = Decisions::decisions($aDecider);
        $this->enCours = $this->gatewayEnMarche() || $this->gateway === [] ? Decisions::enCours($sessions, $this->maintenant) : [];
        $this->prochaines = $this->prochaines($taches);
        $this->plateformes = $this->plateformes($taches);
        $this->tokens = $this->tokens();
        $this->frise = new Frise($releve, $taches, $this->maintenant, suspendue: ! $this->gatewayEnMarche() && $this->gateway !== []);
    }

    public function gatewayEnMarche(): bool
    {
        return ($this->gateway['etat'] ?? null) === 'running';
    }

    public function gatewayArrete(): bool
    {
        return $this->gateway !== [] && ! $this->gatewayEnMarche();
    }

    public function toutTourne(): bool
    {
        return ! $this->releve->fige() && $this->gatewayEnMarche()
            && $this->erreurs === [] && $this->decisions === [];
    }

    /**
     * Erreurs 24 h : lignes ERROR du journal regroupées par message (« 3× … ») ; une tâche planifiée en échec dans
     * la fenêtre est rattachée à sa ligne (même nom), sinon ajoutée.
     * Les WARNING sont exclus (ux/03 § Accueil).
     *
     * @param  list<array<string, mixed>>  $entrees
     * @param  list<array<string, mixed>>  $taches
     * @return list<array<string, mixed>>
     */
    private function erreurs(array $entrees, array $taches): array
    {
        $echecs = array_values(array_filter($taches, fn (array $t) => Planification::enEchec($t)));
        $groupes = [];

        foreach ($entrees as $e) {
            if (($e['niveau'] ?? null) !== 'ERROR') {
                continue;
            }
            $message = (string) ($e['message'] ?? '');
            $cle = md5($message);
            if (isset($groupes[$cle])) {
                $groupes[$cle]['nombre']++;
                if (strcmp((string) $e['quand'], (string) $groupes[$cle]['quand']) > 0) {
                    $groupes[$cle]['quand'] = $e['quand'];
                }

                continue;
            }

            $tache = null;
            foreach ($echecs as $t) {
                if (str_contains($message, (string) $t['nom'])) {
                    $tache = $t;
                }
            }

            $gateway = str_starts_with((string) ($e['module'] ?? ''), 'gateway');
            $groupes[$cle] = [
                'motif' => $tache ? Libelles::dernierStatut($tache['dernier_statut'])[0] : ($gateway ? 'Arrêté' : 'Erreur'),
                'titre' => $tache ? (string) $tache['nom'] : ($gateway ? 'Gateway Hermes' : (string) ($e['module'] ?? 'Hermes')),
                'explication' => $tache ? $this->explicationTache($tache, $message) : $message,
                'quand' => $e['quand'],
                'nombre' => 1,
                'lien' => $tache ? route('planifiees.fiche', $tache['id']) : null,
                'tache' => $tache['id'] ?? null,
            ];
        }

        $rattachees = array_filter(array_column($groupes, 'tache'));
        foreach ($echecs as $t) {
            $quand = Temps::lire($t['derniere_execution'] ?? null);
            if (in_array($t['id'], $rattachees, true) || $quand === null || $quand->lt($this->maintenant->subDay())) {
                continue;
            }
            $groupes[] = [
                'motif' => Libelles::dernierStatut($t['dernier_statut'])[0],
                'titre' => (string) $t['nom'],
                'explication' => $this->explicationTache($t, (string) ($t['derniere_erreur'] ?? '')),
                'quand' => $t['derniere_execution'],
                'nombre' => 1,
                'lien' => route('planifiees.fiche', $t['id']),
                'tache' => $t['id'],
            ];
        }

        $res = array_values($groupes);
        usort($res, fn (array $a, array $b) => strcmp((string) $b['quand'], (string) $a['quand']));

        return $res;
    }

    /** @param  array<string, mixed>  $tache */
    private function explicationTache(array $tache, string $repli): string
    {
        $erreur = (string) ($tache['erreur_livraison'] ?? '') ?: (string) ($tache['derniere_erreur'] ?? '') ?: $repli;

        return Erreurs::enClair($erreur, $tache['dernier_statut'] ?? null);
    }

    /**
     * Les 5 prochaines exécutions (une par tâche, ux/03 : « les 5 suivantes »).
     *
     * @param  list<array<string, mixed>>  $taches
     * @return list<array<string, mixed>>
     */
    private function prochaines(array $taches): array
    {
        $res = array_values(array_filter($taches, fn (array $t) => ($t['actif'] ?? false) === true
            && ($t['etat'] ?? null) === 'scheduled' && ! empty($t['prochaine_execution'])));
        usort($res, fn (array $a, array $b) => Temps::lire($a['prochaine_execution'])?->getTimestamp() <=> Temps::lire($b['prochaine_execution'])?->getTimestamp());

        return array_slice($res, 0, 5);
    }

    /**
     * Plateformes du gateway, avec le nombre de livraisons échouées qui les concernent (T2/C1 : jamais en vert seul).
     *
     * @param  list<array<string, mixed>>  $taches
     * @return list<array{nom: string, etat: string, forme: string, echecs: int}>
     */
    private function plateformes(array $taches): array
    {
        $res = [];
        foreach ($this->gateway['plateformes'] ?? [] as $p) {
            $nom = (string) $p['nom'];
            $echecs = count(array_filter($taches, fn (array $t) => ($t['dernier_statut'] ?? null) === 'delivery_failed'
                && ($t['actif'] ?? false) === true
                && (($t['livraison'] ?? null) === $nom || stripos((string) ($t['erreur_livraison'] ?? ''), $nom) !== false)));
            [$etat, $forme] = Libelles::plateforme($p['etat'] ?? null);
            if (($p['attention'] ?? false) === true) {
                $etat .= ' · à vérifier';
            }
            $res[] = ['nom' => Libelles::nomPlateforme($nom), 'etat' => $etat, 'forme' => $forme, 'echecs' => $echecs];
        }

        return $res;
    }

    /**
     * Tokens hors cache (entrée + sortie) du jour et des 7 derniers jours.
     *
     * @return array<string, mixed>
     */
    private function tokens(): array
    {
        $conso = $this->releve->donnees(fn (HermesClient $c) => $c->consommation(7));
        $jours = [];
        foreach ($conso['par_jour'] ?? [] as $j) {
            $jours[] = ['date' => (string) $j['date'], 'hors_cache' => (int) $j['entree'] + (int) $j['sortie']];
        }
        $parJour = is_array($conso['par_jour'] ?? null) ? $conso['par_jour'] : [];
        $aujourdhui = end($parJour) ?: null;
        if ($conso === [] || $aujourdhui === null || ($aujourdhui['date'] ?? null) !== $this->maintenant->toDateString()) {
            $aujourdhui = null;
        }

        return [
            'jours' => $jours,
            'max' => max([1, ...array_column($jours, 'hors_cache')]),
            'echelle' => Nombres::echelle(array_column($jours, 'hors_cache')),
            'jour' => $aujourdhui === null ? null : [
                'hors_cache' => (int) $aujourdhui['entree'] + (int) $aujourdhui['sortie'],
                'cache_lu' => (int) $aujourdhui['cache_lecture'],
                'cache_ecrit' => (int) $aujourdhui['cache_ecriture'],
                'sessions' => (int) $aujourdhui['sessions'],
                'cout' => (float) $aujourdhui['cout_estime_usd'],
            ],
        ];
    }
}
