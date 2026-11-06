<?php

namespace App\Livewire;

use App\Hermes\Action;
use App\Hermes\Exceptions\PontErreur;
use App\Hermes\Exceptions\PontInjoignable;
use App\Hermes\Exceptions\TotpAReconfirmer;
use App\Hermes\Exceptions\TropDActions;
use App\Hermes\ExecuteurActions;
use App\Hermes\HermesClient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Messagerie avec les managers (ADR 0008, lot 13) : liste des interlocuteurs, conversations, fil, saisie.
 * Aucune confirmation ni TOTP à chaque envoi (décision produit) ; le journal d'audit et la limite de débit restent actifs.
 */
class Messagerie extends Page
{
    protected string $actif = 'messagerie';

    protected string $rafraichissement = '10s';

    #[Locked]
    public ?string $interlocuteur = null;

    #[Locked]
    public ?string $conversation = null;

    #[Url(as: 'nouvelle')]
    public bool $nouvelle = false;

    /** Compositeur pré-rempli (« Écrire à », « Envoyer à… ») : hauteur jusqu'à 8 lignes pour relire le texte en entier. */
    #[Locked]
    public bool $prerempli = false;

    public string $texte = '';

    public string $erreurMessagerie = '';

    public function mount(?string $interlocuteur = null, ?string $conversation = null): void
    {
        $this->interlocuteur = $interlocuteur;
        $this->conversation = $conversation;
        // « Écrire à » : pré-remplit le compositeur, n'envoie jamais (GET sans effet).
        if ($conversation === null && is_string($t = request()->query('texte'))) {
            $this->texte = mb_substr(strip_tags($t), 0, 300);
            $this->prerempli = true;
        }
    }

    public function envoyer(): void
    {
        $this->erreurMessagerie = '';
        $texte = trim($this->texte);
        if ($texte === '' || $this->interlocuteur === null) {
            return;
        }

        $donnees = ['interlocuteur' => $this->interlocuteur, 'texte' => $texte];
        if ($this->conversation !== null) {
            $donnees['conversation'] = $this->conversation;
        }

        $res = $this->lancer(Action::MessagerieEnvoyer, $donnees);
        if ($res === null) {
            return;
        }

        $this->texte = '';
        $this->nouvelle = false;
        if ($this->conversation === null && preg_match('/^c_[a-f0-9]{16}$/', $res) === 1) {
            $this->redirectRoute('messagerie.conversation', ['interlocuteur' => $this->interlocuteur, 'conversation' => $res]);
        }
    }

    public function arreter(): void
    {
        if ($this->conversation !== null) {
            $this->erreurMessagerie = '';
            $this->lancer(Action::MessagerieArreter, ['conversation' => $this->conversation]);
        }
    }

    public function archiver(string $conversation): void
    {
        $this->erreurMessagerie = '';
        if ($this->lancer(Action::MessagerieArchiver, ['conversation' => $conversation]) !== null && $conversation === $this->conversation) {
            $this->redirectRoute('messagerie.interlocuteur', ['interlocuteur' => $this->interlocuteur]);
        }
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @return string|null identifiant de conversation renvoyé par le pont ('' si aucun), null en cas d'échec
     */
    private function lancer(Action $action, array $donnees): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $res = app(ExecuteurActions::class)->executer(request(), $user, $action, $donnees);
        } catch (TropDActions $e) {
            $this->erreurMessagerie = "Trop d'actions en une minute. Réessayer dans {$e->secondesAvantReessai} s.";

            return null;
        } catch (ValidationException $e) {
            $this->erreurMessagerie = (string) (collect($e->errors())->flatten()->first() ?? 'Message invalide.');

            return null;
        } catch (PontInjoignable) {
            $this->erreurMessagerie = 'Le pont est injoignable : message non envoyé.';

            return null;
        } catch (TotpAReconfirmer) {
            $this->erreurMessagerie = 'Vérification à refaire : se reconnecter.';

            return null;
        } catch (PontErreur $e) {
            $this->erreurMessagerie = Str::limit($e->getMessage(), 300);

            return null;
        }

        if (! $res->ok) {
            $this->erreurMessagerie = Str::limit($res->erreur ?: 'Refus du pont.', 300);

            return null;
        }

        return $res->sortie;
    }

    public function render(): View
    {
        $releve = $this->releve();
        $d = $releve->donnees(fn (HermesClient $c) => $c->messagerie());
        $interlocuteurs = $d['interlocuteurs'] ?? [];
        $conversations = collect($d['conversations'] ?? [])->where('statut', '!=', 'archive')->sortByDesc('modifie_a')->values();

        $courant = null;
        if ($this->interlocuteur !== null) {
            $courant = collect($interlocuteurs)->firstWhere('id', $this->interlocuteur);
            if ($courant === null && ! $releve->fige()) {
                abort(404);
            }
        }

        $fil = null;
        $id = $this->conversation;
        if ($id === null && $this->interlocuteur !== null && ! $this->nouvelle) {
            $id = $conversations->firstWhere('interlocuteur', $this->interlocuteur)['id'] ?? null;
        }
        if ($id !== null) {
            $fil = $releve->donnees(fn (HermesClient $c) => $c->conversationMessagerie($id));
            if ($fil !== [] && ($fil['conversation']['interlocuteur'] ?? null) !== $this->interlocuteur) {
                abort(404);
            }
            if ($fil === []) {
                $fil = null;
            }
        }

        return $this->page('livewire.messagerie', [
            'interlocuteurs' => $interlocuteurs,
            'conversations' => $conversations,
            'courant' => $courant,
            'fil' => $fil,
            'idFil' => $id,
            'blocs' => $fil === null ? [] : $this->blocs($fil['evenements'] ?? []),
        ])->title(($courant['nom'] ?? 'Messagerie').' — hermes-os');
    }

    /**
     * Regroupe les événements en messages : les étapes précèdent la réponse du manager qu'elles ont produite.
     *
     * @param  list<array<string, mixed>>  $evenements
     * @return list<array<string, mixed>>
     */
    private function blocs(array $evenements): array
    {
        $blocs = [];
        $etapes = [];
        foreach ($evenements as $e) {
            $heure = isset($e['a']) ? CarbonImmutable::parse($e['a'])->setTimezone('Europe/Paris')->format('H:i') : '';
            switch ($e['type'] ?? '') {
                case 'etape':
                    $etapes[] = (string) ($e['texte'] ?? '');
                    break;
                case 'message':
                    // Tout auteur autre que `manager` est l'humain (compatibilité avec les journaux antérieurs au renommage).
                    if (($e['auteur'] ?? '') !== 'manager') {
                        $blocs[] = ['auteur' => 'utilisateur', 'texte' => (string) $e['texte'], 'heure' => $heure];
                    } else {
                        $blocs[] = ['auteur' => 'manager', 'texte' => (string) $e['texte'], 'heure' => $heure, 'etapes' => $etapes, 'approbation' => (bool) ($e['approbation_requise'] ?? false)];
                        $etapes = [];
                    }
                    break;
            }
        }
        if ($etapes !== []) {
            $blocs[] = ['auteur' => 'manager', 'texte' => '', 'heure' => '', 'etapes' => $etapes, 'approbation' => false, 'encours' => true];
        }

        return $blocs;
    }
}
