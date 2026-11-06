<x-auth-ecran titre="Connexion" onglet="Connexion" public>
    <form method="POST" action="{{ route('login.store') }}" class="formulaire">
        @csrf
        {{-- A3 : l'erreur générique (« identifiant ou mot de passe incorrect ») porte sur les deux champs ; #erreurs = le bloc role="alert". --}}
        @php $erreur = $errors->has('email') || $errors->has('password'); @endphp
        <div @class(['champ', 'erreur' => $erreur])>
            <label for="email">Identifiant</label>
            <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                   @if ($erreur) aria-invalid="true" aria-describedby="erreurs" @endif>
        </div>
        <div @class(['champ', 'erreur' => $erreur])>
            <label for="password">Mot de passe</label>
            <input id="password" type="password" name="password" required autocomplete="current-password"
                   @if ($erreur) aria-invalid="true" aria-describedby="erreurs" @endif>
        </div>
        <div class="formulaire-actions"><button type="submit" class="bouton">Se connecter</button></div>
    </form>
</x-auth-ecran>
