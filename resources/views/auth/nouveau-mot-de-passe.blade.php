<x-auth-ecran titre="Nouveau mot de passe" onglet="Nouveau mot de passe">
    <p class="champ-aide">Le mot de passe actuel est provisoire. En choisir un avant d'activer la double authentification.</p>

    <form method="POST" action="{{ route('mot-de-passe.enregistrer') }}" class="formulaire">
        @csrf
        <input type="email" name="email" value="{{ auth()->user()->email }}" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" readonly>
        <div @class(['champ', 'erreur' => $errors->has('password')])>
            <label for="password">Nouveau mot de passe</label>
            <input id="password" type="password" name="password" required autofocus autocomplete="new-password"
                   minlength="{{ config('hermes-os.auth.mot_de_passe_min') }}" aria-describedby="mdp-aide @error('password') erreurs @enderror" @error('password') aria-invalid="true" @enderror>
            <span id="mdp-aide" class="champ-aide">{{ config('hermes-os.auth.mot_de_passe_min') }} caractères minimum. Refusé s'il figure dans une fuite connue.</span>
        </div>
        <div @class(['champ', 'erreur' => $errors->has('password')])>
            <label for="password_confirmation">Confirmer</label>
            <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" @error('password') aria-invalid="true" aria-describedby="erreurs" @enderror>
        </div>
        <div class="formulaire-actions"><button type="submit" class="bouton">Enregistrer</button></div>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="bouton-texte">Se déconnecter</button>
    </form>
</x-auth-ecran>
