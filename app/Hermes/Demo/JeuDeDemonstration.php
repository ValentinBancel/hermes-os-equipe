<?php

namespace App\Hermes\Demo;

use App\Hermes\Action;
use App\Hermes\Exceptions\PontErreur;
use Carbon\CarbonImmutable;
use Cron\CronExpression;

/**
 * Jeu de données de démonstration : contenus fictifs et neutres (équipe d'agents type, personnes en @example.test),
 * horodatages relatifs à « maintenant » pour que l'interface reste vivante. Mêmes clés que le pont (pont/contrat.json).
 */
final class JeuDeDemonstration
{
    /** Liste fermée de la messagerie (comme `--interlocuteurs` du pont), dans l'ordre d'affichage : id => [pôle, modèle]. */
    private const INTERLOCUTEURS = [
        'assistant' => ['assistance', 'claude-opus-5-5'],
        'recherche' => ['etudes', 'claude-opus-5-5'],
        'redaction' => ['contenus', 'claude-sonnet-5-5'],
    ];

    private const PROFILS = [
        // profil => [pôle, rôle, modèle, skills actifs, description]
        'default' => [null, null, 'claude-opus-5-5', ['grounded-citations', 'github'], null],
        'assistant' => ['assistance', 'lead', 'claude-opus-5-5', ['email-inbox-triage', 'weekly-review-planning', 'grounded-citations'], 'Assistant généraliste : tri des demandes, agenda, suivi des tâches.'],
        'recherche' => ['etudes', 'lead', 'claude-opus-5-5', ['searxng-search', 'arxiv', 'grounded-citations'], 'Recherche documentaire et synthèses sourcées.'],
        'veille' => ['etudes', 'executant', 'claude-sonnet-5-5', ['searxng-search', 'grounded-citations'], 'Veille quotidienne sur les sujets suivis.'],
        'redaction' => ['contenus', 'lead', 'claude-sonnet-5-5', ['humanizer', 'grounded-citations'], 'Rédaction et relecture des contenus.'],
    ];

    // [id, nom, planning, actif, état, dernier statut, livraison, prochaine exécution (heures), erreur de livraison]
    private const TACHES = [
        ['a1b2c3d4e5f6', 'Veille — actualité du secteur', '0 8 * * *', true, 'scheduled', 'ok', 'discord', 14, null],
        ['b2c3d4e5f6a1', 'Veille — publications scientifiques', '0 8 * * *', true, 'scheduled', 'ok', 'discord', 14, null],
        ['c3d4e5f6a1b2', 'Rapport hebdo de fréquentation', '0 18 * * 3', true, 'scheduled', 'delivery_failed', 'origin', 144, 'Livraison Discord impossible : salon introuvable.'],
        ['d4e5f6a1b2c3', 'Tri de la boîte de réception', '0 * * * *', true, 'scheduled', 'ok', 'discord', 1, null],
        ['e5f6a1b2c3d4', 'Sauvegarde des documents', '0 22 * * *', true, 'scheduled', 'ok', 'discord', 3, null],
        ['f6a1b2c3d4e5', 'Surveillance du site (essai)', 'every 15m', false, 'completed', 'ok', 'origin', null, null],
        ['a7b8c9d0e1f2', 'Point du matin', '30 8 * * 1-5', true, 'scheduled', 'ok', 'discord', 14, null],
        ['b8c9d0e1f2a7', 'Bilan de la semaine', '0 10 * * 6', true, 'scheduled', 'ok', 'discord', 39, null],
    ];

    // Tokens du profil principal sur 7 jours (pic le 08/10 : 226 M ; 119 M le 01/10), en millions.
    private const TOKENS_PAR_JOUR = [2.4, 3.1, 5.9, 2.9, 3.4, 2.3, 119.0, 1.7, 2.6, 4.8, 3.3, 2.1, 6.4, 3.9, 2.8, 1.9, 4.2, 3.5, 2.2, 5.1, 2.7, 3.6, 2.4, 3.1, 5.9, 2.9, 3.4, 2.3, 2.4, 226.0];

    private readonly CarbonImmutable $maintenant;

    /**
     * @param  bool  $gatewayArrete  état `gateway_arrete` : gateway arrêté depuis 47 min
     * @param  bool  $toutTourne  état `tout_tourne` : rien à décider, aucune erreur (accueil nominal « Tout tourne. »)
     */
    public function __construct(private readonly bool $gatewayArrete = false, private readonly bool $toutTourne = false)
    {
        $this->maintenant = CarbonImmutable::now('Europe/Paris');
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function taches(): array
    {
        if (! $this->toutTourne) {
            return self::TACHES;
        }

        return array_map(function (array $t) {
            $t[5] = 'ok';
            $t[8] = null;

            return $t;
        }, self::TACHES);
    }

    /**
     * @return array<string, mixed>
     */
    public function sante(): array
    {
        $verifications = [];
        $verifications[] = ['nom' => 'cron/jobs.json', 'ok' => true, 'detail' => count(self::TACHES).' tâche(s)'];
        $verifications[] = ['nom' => 'gateway_state.json', 'ok' => true, 'detail' => $this->gatewayArrete ? 'stopped' : 'running'];
        $verifications[] = ['nom' => 'cli hermes', 'ok' => true, 'detail' => 'exécutable'];

        return [
            'ok' => true,
            'version_pont' => 'demo',
            'horodatage' => $this->maintenant->toIso8601String(),
            'verifications' => $verifications,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function gateway(): array
    {
        $profils = array_keys(self::PROFILS);

        return [
            'etat' => $this->gatewayArrete ? 'stopped' : 'running',
            'processus_actif' => ! $this->gatewayArrete,
            'agents_actifs' => $this->gatewayArrete ? 0 : 2,
            'mis_a_jour_a' => $this->iso($this->gatewayArrete ? -47 * 60 : -40),
            'version' => '0.21.5',
            'raison_arret' => $this->gatewayArrete ? 'Arrêt demandé (redémarrage du service).' : null,
            'plateformes' => [[
                'nom' => 'discord',
                'etat' => $this->gatewayArrete ? 'disconnected' : 'connected',
                'code_erreur' => null,
                'message_erreur' => null,
                'attention' => false,
                'mis_a_jour_a' => $this->iso($this->gatewayArrete ? -47 * 60 : -3600),
                'reessai_depuis' => null,
            ]],
            'profils_servis' => $this->gatewayArrete ? [] : $profils,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * « À décider » (P2) : approbations et questions en attente dans la Messagerie, comme le pont.
     *
     * @return array<string, mixed>
     */
    public function aDecider(): array
    {
        if ($this->toutTourne) {
            return ['elements' => []];
        }
        $t = fn (int $minutes): string => $this->maintenant->subMinutes($minutes)->toIso8601String();

        return ['elements' => [
            ['type' => 'question', 'conversation' => 'c_d3d0000000000001', 'interlocuteur' => 'assistant', 'nom' => 'assistant',
                'titre' => 'Préparation du séminaire', 'texte' => "J'ai deux devis pour la salle.\nQUESTION : je réserve la salle la moins chère (1 200 €) ?\nCONTEXTE : l'autre est mieux desservie mais coûte 400 € de plus.", 'cree_a' => $t(170)],
            ['type' => 'approbation', 'conversation' => 'c_d3d0000000000004', 'interlocuteur' => 'redaction', 'nom' => 'redaction',
                'titre' => 'Lettre d’information de novembre', 'texte' => "La lettre est prête. Je dois l'envoyer aux 340 abonnés : c'est une action externe, j'attends votre accord.", 'cree_a' => $t(45)],
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    public function planifiees(): array
    {
        return ['taches' => array_map(fn (array $t) => $this->tache($t), $this->taches())];
    }

    /**
     * @return array<string, mixed>
     */
    public function planifiee(string $id): array
    {
        $t = $this->trouverTache($id);
        $historique = [];
        $sorties = [];
        if ($t[3]) {
            for ($i = 0; $i < 7; $i++) {
                // V5-13 : l'exécution en échec de livraison est datée de la même heure que la ligne du journal
                // (« Erreurs 24 h » de l'accueil) : la frise et la rubrique disent la même heure.
                $echoue = $t[5] === 'delivery_failed' && $i === 0;
                $debut = $echoue ? $this->echecLivraison() : $this->maintenant->subDays($i)->setTime(8, 0, 12);
                $historique[] = [
                    'id' => substr(hash('sha256', $id.$i), 0, 32), 'source' => 'builtin', 'statut' => 'completed',
                    'demarre_a' => $debut->toIso8601String(), 'termine_a' => $debut->addSeconds(88)->toIso8601String(),
                    'erreur' => null,
                    'livraison' => $echoue ? 'failed' : 'delivered',
                    'prevue_a' => ($echoue ? $debut->startOfMinute() : $debut->setTime(8, 0))->utc()->toIso8601String(),
                ];
                $sorties[] = ['nom' => $debut->addSeconds(76)->format('Y-m-d_H-i-s').'.md', 'octets' => 9800 + 211 * $i];
            }
        }

        return $this->tache($t) + ['historique' => $historique, 'sorties' => $sorties];
    }

    /**
     * @return array<string, mixed>
     */
    public function sortie(string $id, string $nom): array
    {
        $t = $this->trouverTache($id);
        if (! in_array($nom, array_column($this->planifiee($id)['sorties'], 'nom'), true)) {
            throw new PontErreur(404, 'Sortie introuvable.');
        }

        return [
            'tache' => $id, 'nom' => $nom, 'tronque' => false,
            'contenu' => "# {$t[1]}\n\n> Mode démonstration : sortie fictive.\n\n- 3 annonces retenues\n- 1 lien à vérifier\n",
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function consommation(int $jours = 30): array
    {
        $jours = max(1, min(365, $jours));
        $repartition = ['default' => 0.42, 'assistant' => 0.24, 'recherche' => 0.18, 'redaction' => 0.1, 'veille' => 0.06];
        $sources = ['cron' => 0.38, 'messagerie' => 0.24, 'subagent' => 0.18, 'oneshot' => 0.1, 'discord' => 0.08, 'cli' => 0.02];
        $modeles = ['claude-opus-5-5' => 0.86, 'claude-sonnet-5-5' => 0.14];

        $parJour = [];
        $total = $this->agregat(0);
        for ($i = $jours - 1; $i >= 0; $i--) {
            $millions = self::TOKENS_PAR_JOUR[count(self::TOKENS_PAR_JOUR) - 1 - ($i % count(self::TOKENS_PAR_JOUR))];
            $jour = $this->agregat((int) round($millions * 1_000_000), max(1, (int) round($millions / 2)));
            $parJour[] = ['date' => $this->maintenant->subDays($i)->toDateString()] + $jour;
            $total = $this->ajouter($total, $jour);
        }

        $ventiler = fn (array $parts, string $cle) => array_map(
            fn (string $nom, float $part) => [$cle => $nom] + $this->agregat((int) round($total['total'] * $part), max(1, (int) round($total['sessions'] * $part))),
            array_keys($parts), $parts,
        );

        return [
            'periode_jours' => $jours,
            'du' => $this->maintenant->subDays($jours - 1)->toDateString(),
            'au' => $this->maintenant->toDateString(),
            'total' => $total,
            'par_jour' => $parJour,
            'par_profil' => $ventiler($repartition, 'profil'),
            'par_modele' => $ventiler($modeles, 'modele'),
            'par_source' => $ventiler($sources, 'source'),
            'note' => 'Tokens attribués au jour de dernière activité de la session (Europe/Paris). Coût estimé : abonnement, le coût réel facturé peut être nul.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sessions(int $limite = 100): array
    {
        $modeles = [
            ['messagerie', 'assistant', 'claude-opus-5-5', 'Préparation du séminaire'],
            ['cron', 'veille', 'claude-sonnet-5-5', 'Veille — actualité du secteur'],
            ['messagerie', 'recherche', 'claude-opus-5-5', 'Synthèse sur les aides à l’innovation'],
            ['discord', 'default', 'claude-opus-5-5', 'Point du matin'],
            ['subagent', 'redaction', 'claude-sonnet-5-5', 'Relecture de la lettre d’information'],
            ['cron', 'assistant', 'claude-opus-5-5', 'Tri de la boîte de réception'],
        ];
        // Deux sessions actives (« En cours » de l'accueil) sauf gateway arrêté ; les autres sont closes.
        $actives = $this->gatewayArrete ? 0 : 2;
        $res = [];
        for ($i = 0; $i < min($limite, 40); $i++) {
            [$source, $profil, $modele, $titre] = $modeles[$i % count($modeles)];
            $debut = $this->maintenant->subMinutes(37 * $i + 5);
            $duree = 240 + 97 * ($i % 9);
            $active = $i < $actives;
            $res[] = [
                'id' => $debut->format('Ymd_His').'_'.substr(hash('sha256', (string) $i), 0, 6),
                'profil' => $profil, 'source' => $source, 'modele' => $modele, 'titre' => $titre,
                'demarre_a' => $debut->toIso8601String(), 'termine_a' => $active ? null : $debut->addSeconds($duree)->toIso8601String(),
                'derniere_activite' => $active ? $this->iso(-60 * ($i + 1)) : $debut->addSeconds($duree)->toIso8601String(),
                'duree_secondes' => $active ? null : $duree, 'fin' => $active ? null : 'completed',
                'messages' => 12 + $i % 30, 'outils' => 4 + $i % 17, 'tokens' => 180_000 + 41_311 * ($i % 13),
                'cout_estime_usd' => round(0.9 + 0.37 * ($i % 13), 4),
            ];
        }

        return ['sessions' => $res];
    }

    /**
     * @return array<string, mixed>
     */
    public function poles(): array
    {
        $profils = [];
        $poles = [];
        foreach (self::PROFILS as $nom => [$pole, $role, $modele, $skills, $description]) {
            $profils[] = ['profil' => $nom, 'pole' => $pole, 'role' => $role, 'description' => $description, 'modele' => $modele, 'skills_actifs' => $skills];
            if ($pole === null) {
                continue;
            }
            $poles[$pole] ??= ['pole' => $pole, 'lead' => null, 'executants' => [], 'auditeurs' => [], 'support' => []];
            match ($role) {
                'lead' => $poles[$pole]['lead'] = $nom,
                'executant' => $poles[$pole]['executants'][] = $nom,
                'auditeur' => $poles[$pole]['auditeurs'][] = $nom,
                default => $poles[$pole]['support'][] = $nom,
            };
        }

        return ['poles' => array_values($poles), 'profils' => $profils, 'skills_communs' => ['nextcloud-files', 'grounded-citations']];
    }

    /** Coffre de démonstration : métadonnées seulement (le pont ne renvoie jamais de valeur secrète). */
    private const VAULT = [
        ['default', 'vault_d3d000000001', 'login', 'messagerie pro', 'https://mail.example.test', 'email', 'camille.martin@example.test', false, 2],
        ['default', 'vault_d3d000000002', 'login', 'intranet', 'https://intranet.example.test', 'email', 'camille.martin@example.test', true, 9],
        ['default', 'vault_d3d000000003', 'login', 'forum', 'https://forum.example.test', 'username', 'cmartin', false, 19],
        ['assistant', 'vault_d3d000000004', 'address', 'adresse de facturation', 'https://boutique.example.test', null, null, false, 5],
    ];

    /**
     * @return array<string, mixed>
     */
    public function vault(): array
    {
        $elements = array_map(fn (array $v) => [
            'profil' => $v[0], 'id' => $v[1], 'kind' => $v[2], 'label' => $v[3], 'origin' => $v[4],
            'identifier_type' => $v[5], 'identifier' => $v[6], 'has_otp' => $v[7],
            'created_at' => $this->maintenant->subDays($v[8])->toIso8601String(),
        ], self::VAULT);

        return ['profils' => array_keys(self::PROFILS), 'elements' => $elements];
    }

    public const CONVERSATION_DEMO = 'c_d3d0000000000003';

    /**
     * Identifiant de la conversation que la démonstration « crée » à l'envoi d'un premier message
     * à cet interlocuteur (le jeu est sans état : une conversation fixe par interlocuteur).
     */
    public static function conversationNouvelle(string $interlocuteur): string
    {
        $i = array_search($interlocuteur, array_merge(['hermes'], array_keys(self::INTERLOCUTEURS)), true);

        return 'c_d3d10000'.sprintf('%08x', $i === false ? 0 : $i);
    }

    /**
     * Interlocuteurs (rôle lead, comme le pont) et trois conversations de démonstration.
     *
     * @return array<string, mixed>
     */
    public function messagerie(): array
    {
        $interlocuteurs = [['id' => 'hermes', 'nom' => 'Hermes', 'pole' => 'intendant', 'modele' => 'claude-opus-5-5', 'en_cours' => false]];
        // Liste fermée du pont (D10), dans cet ordre.
        foreach (self::INTERLOCUTEURS as $nom => [$pole, $modele]) {
            $interlocuteurs[] = ['id' => $nom, 'nom' => $nom, 'pole' => $pole, 'modele' => $modele, 'en_cours' => $nom === 'recherche'];
        }

        return ['interlocuteurs' => $interlocuteurs, 'conversations' => array_map(fn (array $c) => $this->resumeConversation($c), $this->conversations())];
    }

    /**
     * @return array<string, mixed>
     */
    public function conversationMessagerie(string $id, int $depuis = 0): array
    {
        $c = collect($this->conversationsAvecNouvelles())->firstWhere('id', $id);
        if ($c === null) {
            throw new PontErreur(404, 'Conversation inconnue.');
        }
        $evenements = array_values(array_filter($c['evenements'], fn (array $e) => $e['n'] >= $depuis));
        $enCours = $c['statut'] === 'en_cours';

        return [
            'conversation' => $this->resumeConversation($c),
            'evenements' => $evenements,
            'brouillon' => $enCours ? 'Je commence par vérifier l\'état du dépôt' : '',
            'en_cours' => $enCours,
            'etapes_en_cours' => $enCours ? ['utilise : fichiers'] : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function resumeConversation(array $c): array
    {
        return array_diff_key($c, ['evenements' => 1]);
    }

    /**
     * Les conversations de la liste + une conversation « nouvelle » par interlocuteur (cible de la redirection après
     * un premier envoi en démonstration ; absente des listes).
     *
     * @return list<array<string, mixed>>
     */
    private function conversationsAvecNouvelles(): array
    {
        $t = $this->maintenant->subMinutes(1)->toIso8601String();
        $nouvelles = [];
        foreach (array_merge(['hermes'], array_keys(self::INTERLOCUTEURS)) as $nom) {
            $nouvelles[] = [
                'id' => self::conversationNouvelle($nom), 'interlocuteur' => $nom, 'titre' => 'Nouvelle conversation',
                'cree_a' => $t, 'modifie_a' => $t, 'statut' => 'termine',
                'evenements' => [
                    ['n' => 1, 'a' => $t, 'type' => 'message', 'auteur' => 'utilisateur', 'texte' => 'Message envoyé en mode démonstration : aucune réponse ne sera produite.'],
                    ['n' => 2, 'a' => $t, 'type' => 'statut', 'statut' => 'termine'],
                ],
            ];
        }

        return [...$this->conversations(), ...$nouvelles];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conversations(): array
    {
        $t = fn (int $minutes): string => $this->maintenant->subMinutes($minutes)->toIso8601String();

        return [
            [
                'id' => 'c_d3d0000000000001', 'interlocuteur' => 'assistant', 'titre' => 'Préparation du séminaire',
                'cree_a' => $t(180), 'modifie_a' => $t(170), 'statut' => 'termine',
                'evenements' => [
                    ['n' => 1, 'a' => $t(180), 'type' => 'message', 'auteur' => 'utilisateur', 'texte' => 'Où en est la préparation du séminaire ? Résume-moi ce qui reste à trancher.'],
                    ['n' => 2, 'a' => $t(180), 'type' => 'etape', 'texte' => 'lit : seminaire.md'],
                    ['n' => 3, 'a' => $t(179), 'type' => 'etape', 'texte' => 'utilise : recherche web'],
                    ['n' => 4, 'a' => $t(170), 'type' => 'message', 'auteur' => 'manager', 'texte' => "La préparation avance, avec **un point à trancher** de votre côté.\n\n- **Programme** : validé.\n- **Traiteur** : devis reçu.\n- **Salle** : deux devis à départager.\n\nJ'ai deux devis pour la salle.\nQUESTION : je réserve la salle la moins chère (1 200 €) ?\nCONTEXTE : l'autre est mieux desservie mais coûte 400 € de plus.\n\nDétail dans le [compte rendu](https://example.org/seminaire)."],
                    ['n' => 5, 'a' => $t(170), 'type' => 'statut', 'statut' => 'termine'],
                ],
            ],
            [
                'id' => 'c_d3d0000000000002', 'interlocuteur' => 'redaction', 'titre' => 'Ton de la page d’accueil',
                'cree_a' => $t(1500), 'modifie_a' => $t(1490), 'statut' => 'echec',
                'evenements' => [
                    ['n' => 1, 'a' => $t(1500), 'type' => 'message', 'auteur' => 'utilisateur', 'texte' => 'Propose deux versions du texte de la page d’accueil.'],
                    ['n' => 2, 'a' => $t(1490), 'type' => 'statut', 'statut' => 'echec'],
                ],
            ],
            [
                'id' => self::CONVERSATION_DEMO, 'interlocuteur' => 'recherche', 'titre' => 'Synthèse sur les aides à l’innovation',
                'cree_a' => $t(2), 'modifie_a' => $t(1), 'statut' => 'en_cours',
                'evenements' => [
                    ['n' => 1, 'a' => $t(2), 'type' => 'message', 'auteur' => 'utilisateur', 'texte' => 'Prépare une synthèse des aides à l’innovation ouvertes ce trimestre.'],
                    ['n' => 2, 'a' => $t(2), 'type' => 'etape', 'texte' => 'lit : sources.md'],
                    ['n' => 3, 'a' => $t(1), 'type' => 'statut', 'statut' => 'en_cours'],
                ],
            ],
            [
                'id' => 'c_d3d0000000000004', 'interlocuteur' => 'redaction', 'titre' => 'Lettre d’information de novembre',
                'cree_a' => $t(60), 'modifie_a' => $t(45), 'statut' => 'termine',
                'evenements' => [
                    ['n' => 1, 'a' => $t(60), 'type' => 'message', 'auteur' => 'utilisateur', 'texte' => 'Finalise la lettre de novembre et envoie-la.'],
                    ['n' => 2, 'a' => $t(45), 'type' => 'message', 'auteur' => 'manager', 'approbation_requise' => true, 'texte' => "La lettre est prête. Je dois l'envoyer aux 340 abonnés : c'est une action externe, j'attends votre accord."],
                    ['n' => 3, 'a' => $t(45), 'type' => 'statut', 'statut' => 'termine'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function erreurs(int $heures = 24): array
    {
        $entrees = [
            ['quand' => $this->echecLivraison()->toIso8601String(), 'niveau' => 'ERROR', 'profil' => 'default', 'module' => 'cron.delivery', 'message' => 'Rapport hebdo de fréquentation : livraison Discord impossible (salon introuvable).'],
            ['quand' => $this->iso(-5 * 3600), 'niveau' => 'WARNING', 'profil' => 'default', 'module' => 'hermes_cli.tools_config', 'message' => "platform 'teams' has no valid toolsets configured"],
            ['quand' => $this->iso(-9 * 3600), 'niveau' => 'WARNING', 'profil' => 'recherche', 'module' => 'agent.compression', 'message' => 'Contexte compressé (seuil atteint).'],
        ];
        if ($this->toutTourne) {
            $entrees = array_values(array_filter($entrees, fn (array $e) => $e['niveau'] !== 'ERROR'));
        }
        if ($this->gatewayArrete) {
            array_unshift($entrees, ['quand' => $this->iso(-47 * 60), 'niveau' => 'ERROR', 'profil' => 'default', 'module' => 'gateway.run', 'message' => 'Gateway arrêté : plus aucun message Discord traité.']);
        }

        return [
            'heures' => max(1, min(168, $heures)),
            'comptes' => [
                'erreurs' => count(array_filter($entrees, fn (array $e) => $e['niveau'] === 'ERROR')),
                'avertissements' => count(array_filter($entrees, fn (array $e) => $e['niveau'] === 'WARNING')),
            ],
            'entrees' => $entrees,
        ];
    }

    /**
     * Mêmes refus métier que le pont (tâche, profil, conversation inconnus) : l'interface
     * se comporte pareil en démonstration et en production.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function verifierAction(Action $action, array $donnees): void
    {
        if (str_starts_with($action->value, 'vault.')) {
            $vault = $this->vault();
            if (! in_array($donnees['profil'] ?? null, $vault['profils'], true)) {
                throw new PontErreur(422, 'Profil inconnu.');
            }
            if ($action !== Action::VaultCreer
                && ! collect($vault['elements'])->contains(fn (array $e) => $e['id'] === ($donnees['handle'] ?? null) && $e['profil'] === $donnees['profil'])) {
                throw new PontErreur(422, 'Élément inconnu dans ce coffre.');
            }

            return;
        }

        if ($action->messagerie()) {
            if ($action === Action::MessagerieEnvoyer && ! in_array($donnees['interlocuteur'] ?? null, array_merge(['hermes'], array_keys(self::INTERLOCUTEURS)), true)) {
                throw new PontErreur(422, 'Interlocuteur inconnu.');
            }
            if ($action !== Action::MessagerieEnvoyer && ! collect($this->conversationsAvecNouvelles())->contains('id', $donnees['conversation'] ?? null)) {
                throw new PontErreur(422, 'Conversation inconnue.');
            }

            return;
        }

        if (! in_array($donnees['job_id'] ?? null, array_column($this->taches(), 0), true)) {
            throw new PontErreur(422, 'Tâche planifiée inconnue.');
        }
    }

    /**
     * @return array<int, mixed>
     */
    private function trouverTache(string $id): array
    {
        return collect($this->taches())->first(fn (array $t) => $t[0] === $id)
            ?? throw new PontErreur(404, 'Tâche planifiée inconnue.');
    }

    /**
     * @param  array<int, mixed>  $t
     * @return array<string, mixed>
     */
    private function tache(array $t): array
    {
        [$id, $nom, $planning, $actif, $etat, $statut, $livraison, $prochaine, $erreurLivraison] = $t;

        // D14 : la prochaine exécution d'une tâche cron est dérivée de son planning (même règle que la frise et la page
        // Planifiées) ; seul un intervalle « every … » garde le décalage en heures du jeu.
        $prevue = $prochaine === null ? null : $this->maintenant->addHours($prochaine)->startOfHour();
        if ($actif && $etat === 'scheduled' && ! str_starts_with($planning, 'every')) {
            $prevue = CarbonImmutable::instance((new CronExpression($planning))->getNextRunDate($this->maintenant, 0, false, $this->maintenant->getTimezone()->getName()))
                ->setTimezone($this->maintenant->getTimezone());
        }

        return [
            'id' => $id, 'nom' => $nom, 'planning' => $planning,
            'type_planning' => str_starts_with($planning, 'every') ? 'interval' : 'cron',
            'actif' => $actif, 'etat' => $etat, 'en_pause_depuis' => null, 'raison_pause' => null,
            'prochaine_execution' => $prevue?->toIso8601String(),
            'derniere_execution' => $statut === 'delivery_failed' ? $this->echecLivraison()->toIso8601String() : $this->iso(-10 * 3600), 'dernier_statut' => $statut, 'derniere_erreur' => null,
            'erreur_livraison' => $erreurLivraison, 'echecs_consecutifs' => $statut === 'ok' ? 0 : 1, 'livraison' => $livraison,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function agregat(int $total, int $sessions = 0): array
    {
        $cacheLecture = (int) round($total * 0.9);
        $cacheEcriture = (int) round($total * 0.05);
        $entree = (int) round($total * 0.03);
        $sortie = $total - $cacheLecture - $cacheEcriture - $entree;

        return [
            'entree' => $entree, 'sortie' => $sortie, 'cache_lecture' => $cacheLecture, 'cache_ecriture' => $cacheEcriture,
            'raisonnement' => (int) round($sortie * 0.3), 'total' => $total,
            'cout_estime_usd' => round($total / 1_000_000 * 1.9, 4), 'cout_reel_usd' => 0.0, 'sessions' => $sessions,
        ];
    }

    /**
     * @param  array<string, int|float>  $a
     * @param  array<string, int|float>  $b
     * @return array<string, int|float>
     */
    private function ajouter(array $a, array $b): array
    {
        foreach ($b as $k => $v) {
            $a[$k] += $v;
        }
        $a['cout_estime_usd'] = round($a['cout_estime_usd'], 4);

        return $a;
    }

    /**
     * Heure de l'échec de livraison du jeu (V5-13) : une seule source pour l'historique de la tâche (frise de l'accueil),
     * sa dernière exécution et la ligne ERROR du journal (« Erreurs 24 h »).
     */
    private function echecLivraison(): CarbonImmutable
    {
        return $this->maintenant->subHours(2)->startOfMinute();
    }

    private function iso(int $secondes): string
    {
        return $this->maintenant->addSeconds($secondes)->toIso8601String();
    }
}
