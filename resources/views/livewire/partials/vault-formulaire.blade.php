{{-- Formulaire du Coffre (ajout en haut de page, modification sous la ligne cliquée : dc M2 L6), suivi de sa
     confirmation. Variables du parent (vault.blade.php) : $edition, $modifie, $typeForm, $types, $typesId, $champs… --}}
<section class="{{ $classeFormulaire }}" aria-labelledby="v-form">
    <h2 id="v-form">{{ $edition === 'creer' ? 'Nouvel élément' : 'Modifier « '.($modifie['label'] ?? '').' »' }}</h2>
    @if ($edition !== 'creer')
        <p class="champ-aide">Coffre du profil {{ $editionProfil }} · {{ $types[$typeForm] ?? $typeForm }}. Les champs secrets laissés vides gardent leur valeur actuelle.</p>
    @endif
    <form wire:submit="demanderEnregistrement" class="formulaire" autocomplete="off">
        @if ($edition === 'creer')
            <div class="champ">
                <label for="v-profil">Profil</label>
                <select id="v-profil" wire:model="profil">
                    @foreach ($profils as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </div>
            <div class="champ">
                <label for="v-kind">Type</label>
                <select id="v-kind" wire:model.live="kind">
                    @foreach ($types as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                </select>
            </div>
        @endif
        <div class="champ">
            <label for="v-label">Libellé @if ($edition === 'creer')(requis)@endif</label>
            <input id="v-label" wire:model="label" maxlength="100" placeholder="malt, sharepoint ynov…">
        </div>
        <div class="champ">
            <label for="v-origin">Site @if ($edition === 'creer')(requis)@endif</label>
            <input id="v-origin" wire:model="origin" maxlength="300" inputmode="url" placeholder="https://www.malt.fr">
        </div>
        @if ($typeForm === 'login')
            <div class="champ">
                <label for="v-idtype">Type d’identifiant</label>
                <select id="v-idtype" wire:model="identifierType">
                    @foreach ($typesId as $k => $t)<option value="{{ $k }}">{{ $t }}</option>@endforeach
                </select>
            </div>
            <div class="champ">
                <label for="v-id">Identifiant @if ($edition === 'creer')(requis)@endif</label>
                <input id="v-id" wire:model="identifier" maxlength="254" autocomplete="off">
            </div>
        @endif
        @foreach ($champs[$typeForm] ?? [] as [$cle, $libelle, $type, $requis])
            <div class="champ" wire:key="s-{{ $typeForm }}-{{ $cle }}">
                <label for="v-s-{{ $cle }}">{{ $libelle }} @if ($requis && $edition === 'creer')(requis)@endif</label>
                <input id="v-s-{{ $cle }}" type="{{ $type }}" wire:model="secret.{{ $cle }}" maxlength="1024"
                       autocomplete="{{ $type === 'password' ? 'new-password' : 'off' }}" spellcheck="false"
                       @if ($edition !== 'creer') placeholder="inchangé" @endif>
            </div>
        @endforeach
        @if ($typeForm === 'login' && $edition !== 'creer' && ($modifie['has_otp'] ?? false))
            <label class="champ-aide"><x-case wire:model="retirerOtp" /> Retirer la clé 2FA</label>
        @endif
        @if ($errors->any())
            <p class="champ-erreur" role="alert">{{ $errors->first() }}</p>
        @endif
        <div class="formulaire-actions">
            <button type="submit" class="bouton" @disabled($fige)>{{ $edition === 'creer' ? 'Ajouter…' : 'Enregistrer…' }}</button>
            <button type="button" wire:click="annuler" class="bouton-texte">Annuler</button>
        </div>
    </form>
</section>
@if ($confirmation && ! $confirmationLigne)
    <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
@endif
