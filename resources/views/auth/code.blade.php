{{-- Écran public (ADR 0004) : onglet « Code de secours » quand le formulaire de secours est ouvert sur une erreur.
     Case « Faire confiance à ce PC » seulement depuis un réseau de confiance du compte en cours de connexion. --}}
@php
    $enCours = \App\Models\User::query()->find(session('login.id'));
    $confiancePossible = $enCours !== null && app(\App\Auth\AppareilsDeConfiance::class)->ipDeConfiance($enCours, request()->ip());
@endphp
<x-auth-ecran titre="Code de vérification" :onglet="$errors->has('recovery_code') ? 'Code de secours' : 'Code de vérification'" public>
    <form method="POST" action="{{ route('two-factor.login.store') }}" class="formulaire" x-data="codeTotp">
        @csrf
        <div @class(['champ', 'erreur' => $errors->has('code')])>
            <label for="code">Code à 6 chiffres</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"
                   aria-describedby="code-aide @error('code') erreurs @enderror" @input="saisir" @error('code') aria-invalid="true" @enderror>
            <span id="code-aide" class="champ-aide">Affiché par l'application d'authentification · <span x-text="compteur">0/6</span></span>
        </div>
        @if ($confiancePossible)
            <label class="champ-aide"><x-case name="confiance" value="1" /> Faire confiance à ce PC pendant {{ config('hermes-os.auth.appareil.jours_confiance') }} jours</label>
        @endif
        <div class="formulaire-actions"><button type="submit" class="bouton">Vérifier</button></div>
    </form>

    <details @if ($errors->has('recovery_code')) open @endif>
        <summary class="bouton-texte">Utiliser un code de secours</summary>
        <form method="POST" action="{{ route('two-factor.login.store') }}" class="formulaire mt-[var(--espace-4)]">
            @csrf
            <div @class(['champ', 'erreur' => $errors->has('recovery_code')])>
                <label for="recovery_code">Code de secours</label>
                <input id="recovery_code" type="text" name="recovery_code" required autocomplete="off" @error('recovery_code') aria-invalid="true" aria-describedby="erreurs" @enderror>
            </div>
            <div class="formulaire-actions"><button type="submit" class="bouton bouton-fin">Vérifier le code de secours</button></div>
        </form>
    </details>
</x-auth-ecran>
