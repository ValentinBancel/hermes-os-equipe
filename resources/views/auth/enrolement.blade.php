<x-auth-ecran titre="Activer la double authentification" onglet="Double authentification">
    <p class="champ-aide">Obligatoire avant tout accès. Scanner le QR code avec l'application d'authentification (Aegis, 2FAS, Google Authenticator…), ou saisir la clé.</p>

    <div class="code-appairage">
        <div class="[&_svg]:size-40" role="img" aria-label="QR code d'enrôlement">{!! $qrCode !!}</div>
    </div>
    {{-- dc m6 L3 : clé groupée par 4 (lecture et saisie), copiable comme le mot de passe provisoire ; on copie la clé brute. --}}
    <div class="fiche-ligne"><span>Clé</span><span class="cle-totp">{{ implode(' ', str_split($cle, 4)) }}</span>
        <span><button type="button" class="bouton-texte" x-data="copie" data-texte="{{ $cle }}" data-libelle="Copier la clé" x-on:click="copier" x-text="libelle">Copier la clé</button></span></div>

    <form method="POST" action="{{ route('totp.confirmer') }}" class="formulaire" x-data="codeTotp">
        @csrf
        <div @class(['champ', 'erreur' => $errors->has('code')])>
            <label for="code">Code à 6 chiffres</label>
            <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus autocomplete="one-time-code"
                   aria-describedby="code-aide @error('code') erreurs @enderror" @input="saisir" @error('code') aria-invalid="true" @enderror>
            <span id="code-aide" class="champ-aide"><span x-text="compteur">0/6</span></span>
        </div>
        <div class="formulaire-actions"><button type="submit" class="bouton">Activer</button></div>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="bouton-texte">Se déconnecter</button>
    </form>
</x-auth-ecran>
