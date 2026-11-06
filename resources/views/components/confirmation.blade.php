{{--
    Confirmation d'une action d'écriture (composants.md § 10 « Coffre » : confirmation sur la lavande, entre filets, avec
    deux boutons ; jamais sur du verre). Le contenu est verrouillé côté serveur. Étape 2 : code TOTP si le dernier date de
    plus de 15 min ; « Valider et [verbe] » actif au 6ᵉ chiffre. Échap = Annuler ; le focus va au champ (ou à Annuler)
    puis revient au bouton d'origine.
--}}
@props(['confirmation', 'etapeCode' => false, 'classe' => 'etat-vide'])
@php
    // Action destructive : le bouton qui confirme reste rouge, plein (design-critic L4 M2).
    $classeValider = ($confirmation['danger'] ?? false) ? 'bouton-danger bouton-danger-plein' : 'bouton';
    // Libellé final : celui fourni quand il nomme l'objet (« Supprimer « malt » », dc M2 L6), sinon le verbe.
    $libelle = $confirmation['bouton'] ?? null;
@endphp
@if ($confirmation)
    <section class="{{ $classe }}" role="dialog" aria-labelledby="dl-t" aria-describedby="dl-d" tabindex="-1" wire:key="confirmation"
             x-data="dialogue" @keydown.escape.prevent="echap" @keydown.tab="boucler">
        <b id="dl-t">{{ $confirmation['titre'] }}</b>
        <p id="dl-d">{{ $confirmation['texte'] }}</p>
        @if ($confirmation['apercu'])
            <p>{{ $confirmation['apercu'] }}</p>
        @endif
        @if ($confirmation['avertissement'])
            <p class="rouge">{{ $confirmation['avertissement'] }}</p>
        @endif

        <form wire:submit="confirmer" class="formulaire mt-[var(--espace-4)]">
            @if ($etapeCode)
                <div @class(['champ', 'erreur' => $errors->has('code')]) x-data="codeTotp">
                    <label for="dl-code">Code à 6 chiffres de l'application d'authentification</label>
                    <input id="dl-code" wire:model="code" @input="saisir" inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                           aria-describedby="dl-aide dl-erreur" @error('code') aria-invalid="true" @enderror>
                    <span id="dl-aide" class="champ-aide">Requis plus de 15 min après la dernière vérification · <span x-text="compteur">0/6</span></span>
                    @error('code')<span id="dl-erreur" class="champ-erreur">{{ $message }}</span>@enderror
                    <div class="formulaire-actions mt-[var(--espace-4)]">
                        <button type="submit" class="{{ $classeValider }}" x-bind:disabled="incomplet" disabled wire:loading.attr="aria-busy" wire:target="confirmer">Valider et {{ $libelle !== null ? \Illuminate\Support\Str::lcfirst($libelle) : $confirmation['verbe'] }}</button>
                        <button type="button" class="bouton-texte" wire:click="fermerConfirmation" data-annuler>Annuler</button>
                    </div>
                </div>
            @else
                <div class="formulaire-actions">
                    <button type="submit" class="{{ $classeValider }}" wire:loading.attr="aria-busy" wire:target="confirmer">{{ $libelle ?? ucfirst($confirmation['verbe']) }}</button>
                    <button type="button" class="bouton-texte" wire:click="fermerConfirmation" data-annuler>Annuler</button>
                </div>
            @endif
        </form>
    </section>
@endif
