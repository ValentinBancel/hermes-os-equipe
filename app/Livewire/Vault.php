<?php

namespace App\Livewire;

use App\Hermes\Action;
use App\Hermes\HermesClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;

/**
 * Coffre (vault) de Hermes : un coffre par profil. Liste (métadonnées seulement), ajout, modification, suppression.
 * Hermes ne sait pas modifier un élément : « Modifier » supprime l'ancien et crée le nouveau (poignée nouvelle) ; un
 * champ secret laissé vide garde l'ancienne valeur, que la console ne voit jamais.
 * Les valeurs secrètes ne passent ni dans la confirmation, ni dans le journal d'audit ; le formulaire est vidé après envoi.
 */
#[Title('Coffre — hermes-os')]
class Vault extends Page
{
    protected string $actif = 'vault';

    /** Filtre de la liste par profil (vide = tous). */
    #[Url(as: 'profil', except: '')]
    public string $filtre = '';

    /** Formulaire : null = fermé ; 'creer' ou la poignée de l'élément modifié. */
    #[Locked]
    public ?string $edition = null;

    #[Locked]
    public string $editionProfil = '';

    public string $profil = 'default';

    public string $kind = 'login';

    public string $label = '';

    public string $origin = '';

    public string $identifierType = 'email';

    public string $identifier = '';

    /** @var array<string, string> */
    public array $secret = [];

    public bool $retirerOtp = false;

    public function render(): View
    {
        $d = $this->releve()->donnees(fn (HermesClient $c) => $c->vault());
        $profils = $d['profils'] ?? [];
        $elements = $d['elements'] ?? [];
        usort($elements, fn (array $a, array $b) => [$a['profil'] !== 'default', $a['profil'], mb_strtolower((string) $a['label'])]
            <=> [$b['profil'] !== 'default', $b['profil'], mb_strtolower((string) $b['label'])]);
        $visibles = $this->filtre === '' ? $elements : array_values(array_filter($elements, fn (array $e) => $e['profil'] === $this->filtre));
        $groupes = [];
        foreach ($visibles as $e) {
            $groupes[$e['profil']][] = $e;
        }

        return $this->page('livewire.vault', [
            'profils' => $profils,
            'groupes' => $groupes,
            'total' => count($elements),
            'coffresUtilises' => count(array_unique(array_column($elements, 'profil'))),
            'modifie' => $this->edition !== null && $this->edition !== 'creer'
                ? collect($elements)->first(fn (array $e) => $e['id'] === $this->edition && $e['profil'] === $this->editionProfil)
                : null,
        ]);
    }

    // ---------------------------------------------------------------- formulaire

    public function nouveau(): void
    {
        $this->viderFormulaire();
        $this->edition = 'creer';
        $this->profil = $this->filtre !== '' ? $this->filtre : 'default';
    }

    public function modifier(string $profil, string $handle): void
    {
        $e = $this->trouver($profil, $handle);
        if ($e === null) {
            $this->resultat = ['ok' => false, 'message' => 'Cet élément n’existe plus.'];

            return;
        }
        $this->viderFormulaire();
        $this->edition = $e['id'];
        $this->editionProfil = $e['profil'];
        $this->profil = $e['profil'];
        $this->kind = $e['kind'];
        $this->label = (string) $e['label'];
        $this->origin = (string) ($e['origin'] ?? '');
        $this->identifierType = (string) ($e['identifier_type'] ?? 'email');
        $this->identifier = (string) ($e['identifier'] ?? '');
    }

    public function annuler(): void
    {
        $this->viderFormulaire();
    }

    public function demanderEnregistrement(): void
    {
        $this->resetErrorBag();
        if ($this->edition === null) {
            return;
        }
        if ($this->edition === 'creer') {
            $donnees = array_filter([
                'profil' => $this->profil, 'kind' => $this->kind, 'label' => trim($this->label), 'origin' => trim($this->origin),
                'identifier_type' => $this->kind === 'login' ? $this->identifierType : null,
                'identifier' => $this->kind === 'login' ? trim($this->identifier) : null,
            ], fn (mixed $v) => $v !== null);
            $this->ouvrirConfirmation(Action::VaultCreer, $donnees,
                "Ajouter « {$donnees['label']} » au coffre de {$this->profil} ? Les valeurs saisies sont chiffrées par Hermes ; la console ne pourra plus les afficher.",
                'ajouter');

            return;
        }

        $e = $this->trouver($this->editionProfil, $this->edition);
        if ($e === null) {
            $this->resultat = ['ok' => false, 'message' => 'Cet élément n’existe plus.'];
            $this->viderFormulaire();

            return;
        }
        $donnees = array_filter([
            'profil' => $e['profil'], 'handle' => $e['id'], 'label' => trim($this->label), 'origin' => trim($this->origin),
            'identifier_type' => $e['kind'] === 'login' ? $this->identifierType : null,
            'identifier' => $e['kind'] === 'login' ? trim($this->identifier) : null,
            'retirer_otp' => $e['kind'] === 'login' && $this->retirerOtp ? true : null,
        ], fn (mixed $v) => $v !== null && $v !== '');
        $this->ouvrirConfirmation(Action::VaultModifier, $donnees,
            "Remplacer « {$e['label']} » ({$e['profil']}) ? Hermes ne sait pas modifier : l’élément est supprimé puis recréé avec les nouvelles valeurs, sous une nouvelle poignée.",
            'remplacer',
            avertissement: 'Les champs secrets laissés vides gardent leur valeur actuelle. Une automatisation qui cite l’ancienne poignée devra être mise à jour.');
    }

    public function demanderSuppression(string $profil, string $handle): void
    {
        $e = $this->trouver($profil, $handle);
        if ($e === null) {
            $this->resultat = ['ok' => false, 'message' => 'Cet élément n’existe plus.'];

            return;
        }
        $this->ouvrirConfirmation(Action::VaultSupprimer, ['profil' => $e['profil'], 'handle' => $e['id']],
            "Supprimer « {$e['label']} » du coffre de {$e['profil']} ?", 'supprimer',
            avertissement: 'Suppression définitive : Hermes ne pourra plus utiliser cet identifiant.',
            danger: true, bouton: "Supprimer « {$e['label']} »");
    }

    /** Valeurs secrètes ajoutées au dernier moment : jamais dans `confirmation` (sérialisée vers le navigateur). */
    protected function completerDonnees(Action $action, array $donnees): array
    {
        if ($action === Action::VaultCreer || $action === Action::VaultModifier) {
            $secret = array_filter(array_intersect_key($this->secret, array_flip(Action::CHAMPS_SECRETS)),
                fn (mixed $v) => is_string($v) && $v !== '');
            if ($secret !== []) {
                $donnees['secret'] = $secret;
            }
        }

        return $donnees;
    }

    protected function apresAction(Action $action, array $donnees): void
    {
        $this->viderFormulaire();
    }

    private function viderFormulaire(): void
    {
        $this->reset('edition', 'editionProfil', 'profil', 'kind', 'label', 'origin', 'identifierType', 'identifier', 'secret', 'retirerOtp');
        $this->resetErrorBag();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function trouver(string $profil, string $handle): ?array
    {
        $elements = $this->releve()->donnees(fn (HermesClient $c) => $c->vault())['elements'] ?? [];

        return collect($elements)->first(fn (array $e) => $e['id'] === $handle && $e['profil'] === $profil);
    }
}
