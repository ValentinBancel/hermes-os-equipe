<?php

namespace App\Livewire;

use App\Audit\Journal;
use App\Auth\CodeTotp;
use App\Hermes\Action;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\Exceptions\TotpAReconfirmer;
use App\Hermes\Exceptions\TropDActions;
use App\Hermes\ExecuteurActions;
use App\Hermes\HermesClient;
use App\Hermes\ResultatAction;
use App\Interface\Releve;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Base des pages de la console : relevé des lectures (fraîcheur, pont injoignable), boîte de confirmation des
 * actions d'écriture et message de résultat.
 *
 * Actions (ux/03 § 5, architecture « Flux d'écriture ») : un bouton n'écrit jamais directement. Il ouvre une
 * confirmation dont le contenu est verrouillé côté serveur (#[Locked] : le navigateur ne peut pas changer ce qui
 * sera envoyé) ; « Valider » passe par ExecuteurActions (TOTP < 15 min, 20/min, journal d'audit). Si le dernier
 * code TOTP date de plus de 15 min, la même boîte demande le code (étape 2), vérifié ici, puis rejoue l'action.
 */
abstract class Page extends Component
{
    /** Clé de la navigation active (coque). */
    protected string $actif = '';

    /**
     * Confirmation ouverte : action, arguments, textes. Verrouillée : seul le serveur la remplit.
     *
     * @var array{action: string, donnees: array<string, mixed>, titre: string, texte: string, verbe: string,
     *            avertissement: ?string, apercu: ?string}|null
     */
    #[Locked]
    public ?array $confirmation = null;

    /** Étape 2 : le code TOTP est redemandé (dernier code > 15 min). */
    #[Locked]
    public bool $etapeCode = false;

    public string $code = '';

    /** @var array{ok: bool, message: string, lien?: ?string}|null */
    #[Locked]
    public ?array $resultat = null;

    /** @var array{id: string, journal: int, action: string, donnees: array<string, mixed>}|null */
    #[Locked]
    public ?array $enAttente = null;

    private ?Releve $releve = null;

    protected function releve(): Releve
    {
        return $this->releve ??= new Releve(app(HermesClient::class));
    }

    /**
     * Ouvre la boîte de confirmation d'une action (rien n'est envoyé à ce stade).
     *
     * @param  array<string, mixed>  $donnees
     */
    protected function ouvrirConfirmation(Action $action, array $donnees, string $texte, string $verbe, ?string $avertissement = null, ?string $apercu = null, bool $danger = false, ?string $bouton = null): void
    {
        $this->resetErrorBag();
        $this->code = '';

        if ($this->releve()->fige()) {
            $this->resultat = ['ok' => false, 'message' => 'Actions indisponibles : pont injoignable.'];

            return;
        }

        $this->confirmation = [
            'action' => $action->value,
            'donnees' => $donnees,
            'titre' => $action->libelle(),
            'texte' => $texte,
            'verbe' => $verbe,
            'avertissement' => $avertissement,
            'apercu' => $apercu,
            // Action destructive : bouton final rouge plein ; `bouton` = son libellé quand il nomme l'objet.
            'danger' => $danger,
            'bouton' => $bouton,
        ];
        $this->etapeCode = ! app(ExecuteurActions::class)->totpRecent(request());
    }

    public function fermerConfirmation(): void
    {
        $this->confirmation = null;
        $this->etapeCode = false;
        $this->code = '';
        $this->resetErrorBag();
    }

    public function fermerResultat(): void
    {
        $this->resultat = null;
    }

    /**
     * « Valider » dans la boîte : code TOTP si demandé, puis exécution par ExecuteurActions.
     */
    public function confirmer(): void
    {
        if ($this->confirmation === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();

        if ($this->etapeCode && ! $this->verifierCode($user)) {
            return;
        }

        $action = Action::tryFrom($this->confirmation['action']);
        $donnees = $this->confirmation['donnees'];

        // Actions propres à la console (Compte) : même confirmation, même exigence TOTP < 15 min, même journal.
        if ($action === null) {
            if (! app(ExecuteurActions::class)->totpRecent(request())) {
                $this->etapeCode = true;
                $this->addError('code', 'Code à saisir de nouveau : la dernière vérification date de plus de 15 min.');

                return;
            }
            $cle = $this->confirmation['action'];
            $this->confirmation = null;
            $this->actionLocale($cle, $user);

            return;
        }

        try {
            $res = app(ExecuteurActions::class)->executer(request(), $user, $action, $this->completerDonnees($action, $donnees));
        } catch (TotpAReconfirmer) {
            $this->etapeCode = true;
            $this->code = '';
            $this->addError('code', 'Code à saisir de nouveau : la dernière vérification date de plus de 15 min.');

            return;
        } catch (TropDActions $e) {
            $this->terminer(false, "Trop d'actions en une minute. Réessayer dans {$e->secondesAvantReessai} s.");

            return;
        } catch (ValidationException $e) {
            $this->terminer(false, $this->messageEchec($action, collect($e->errors())->flatten()->first() ?? 'données invalides'));

            return;
        } catch (PontInjoignable) {
            $this->terminer(false, $this->messageEchec($action, 'pont injoignable'));

            return;
        } catch (PontErreur $e) {
            $this->terminer(false, $this->messageEchec($action, $e->getMessage()));

            return;
        }

        if ($res->attente !== null && $res->journal !== null) {
            $this->terminer(true, 'Action transmise : en attente de réponse du Hermes relié…');
            $this->enAttente = ['id' => $res->attente, 'journal' => $res->journal, 'action' => $action->value, 'donnees' => $donnees];

            return;
        }

        $this->conclure($action, $donnees, $res);
    }

    /** Pilote distant (L4) : suivi d'une action en file, appelé par wire:poll.2s tant qu'elle n'a pas abouti. */
    public function suivreAction(): void
    {
        $user = auth()->user();
        if ($this->enAttente === null || ! $user instanceof User) {
            return;
        }

        $res = app(ExecuteurActions::class)->suivre($user, $this->enAttente['id'], $this->enAttente['journal']);
        if ($res === null) {
            return;
        }

        $action = Action::from($this->enAttente['action']);
        $donnees = $this->enAttente['donnees'];
        $this->enAttente = null;
        $this->conclure($action, $donnees, $res);
    }

    /** @param  array<string, mixed>  $donnees */
    private function conclure(Action $action, array $donnees, ResultatAction $res): void
    {
        if (! $res->ok) {
            $this->terminer(false, $this->messageEchec($action, Str::limit($res->erreur ?: $res->sortie ?: 'refus du pont', 300)));

            return;
        }

        $this->terminer(true, $this->messageSucces($action, $donnees, $res->sortie));
        $this->apresAction($action, $donnees);
    }

    /**
     * Ouvre la confirmation d'une action propre à la console (hors pont).
     */
    protected function ouvrirConfirmationLocale(string $cle, string $titre, string $texte, string $verbe, ?string $avertissement = null, bool $danger = false): void
    {
        $this->resetErrorBag();
        $this->code = '';
        $this->confirmation = [
            'action' => $cle, 'donnees' => [], 'titre' => $titre, 'texte' => $texte, 'verbe' => $verbe,
            'avertissement' => $avertissement, 'apercu' => null, 'danger' => $danger, 'bouton' => null,
        ];
        $this->etapeCode = ! app(ExecuteurActions::class)->totpRecent(request());
    }

    /** Hook : exécution d'une action propre à la console, après confirmation et TOTP. */
    protected function actionLocale(string $cle, User $user): void {}

    /**
     * Hook : données ajoutées au moment d'exécuter, jamais stockées dans la confirmation (valeurs secrètes du coffre).
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    protected function completerDonnees(Action $action, array $donnees): array
    {
        return $donnees;
    }

    /** Hook : nettoyage d'un formulaire après une action réussie. */
    protected function apresAction(Action $action, array $donnees): void {}

    protected function terminer(bool $ok, string $message, ?string $lien = null): void
    {
        $this->resultat = ['ok' => $ok, 'message' => $message, 'lien' => $lien];
        $this->confirmation = null;
        $this->etapeCode = false;
        $this->code = '';
    }

    /**
     * Étape 2 de la confirmation : même contrôle qu'à la connexion (5 essais / min, verrouillage après 10 échecs,
     * journal d'audit). Le code n'est jamais journalisé.
     */
    private function verifierCode(User $user): bool
    {
        $this->resetErrorBag('code');
        $erreur = app(CodeTotp::class)->verifier($user, $this->code, 'action');
        if ($erreur !== null) {
            $this->code = '';
            $this->addError('code', $erreur);

            return false;
        }
        $this->etapeCode = false;

        return true;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function messageSucces(Action $action, array $donnees, string $sortie): string
    {
        $base = match ($action) {
            Action::CronLancer => 'Lancement demandé. Le résultat apparaîtra dans l’historique.',
            Action::CronSuspendre => 'Tâche en pause.',
            Action::CronReprendre => 'Tâche reprise.',
            Action::VaultCreer => 'Élément ajouté au coffre de '.($donnees['profil'] ?? '').'.',
            Action::VaultModifier => 'Élément remplacé (l’ancien est supprimé, nouvelle poignée).',
            Action::VaultSupprimer => 'Élément supprimé du coffre.',
            default => 'Fait.',
        };

        $sortie = trim($sortie);

        return $sortie === '' ? $base : $base.' '.Str::limit($sortie, 200);
    }

    private function messageEchec(Action $action, string $motif): string
    {
        return match ($action) {
            Action::CronLancer => "Lancement refusé : {$motif}.",
            Action::CronSuspendre => "Mise en pause refusée : {$motif}. La tâche reste active.",
            Action::CronReprendre => "Reprise refusée : {$motif}. La tâche reste en pause.",
            Action::VaultCreer => "Ajout refusé : {$motif}. Rien n'a été ajouté.",
            Action::VaultModifier => "Modification refusée : {$motif}. L'élément est inchangé.",
            Action::VaultSupprimer => "Suppression refusée : {$motif}. L'élément est conservé.",
            default => "Action refusée : {$motif}.",
        };
    }

    /** Intervalle de rafraîchissement (ADR 0005) : 5 s accueil, 30 s ailleurs. */
    protected string $rafraichissement = '30s';

    /**
     * Vue de page : ajoute le relevé (coque), l'onglet actif et l'intervalle de rafraîchissement
     * (aucun pendant une confirmation : la boîte ne doit pas bouger sous les doigts).
     *
     * @param  array<string, mixed>  $donnees
     */
    protected function page(string $vue, array $donnees = []): View
    {
        $releve = $this->releve();

        return view($vue, $donnees + [
            'releve' => $releve,
            'actif' => $this->actif,
            'poll' => $this->confirmation === null ? $this->rafraichissement : null,
            'fige' => $releve->fige(),
        ]);
    }
}
