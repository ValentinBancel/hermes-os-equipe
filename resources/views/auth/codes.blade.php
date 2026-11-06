<x-auth-ecran titre="Codes de secours" onglet="Codes de secours">
    <p class="champ-aide">Affichés une seule fois. Chaque code permet une connexion sans le téléphone : les conserver hors de ce téléphone (gestionnaire de mots de passe).</p>

    <ol class="etapes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ol>

    <div x-data="caseRequise" class="formulaire">
        <label class="champ-aide"><x-case x-on:change="basculer" /> Codes enregistrés dans un endroit sûr</label>
        <div class="formulaire-actions"><a href="{{ route('accueil') }}" class="bouton" aria-disabled="true" tabindex="-1" x-bind:aria-disabled="bloque" x-bind:tabindex="tabindex">Continuer</a></div>
    </div>
</x-auth-ecran>
