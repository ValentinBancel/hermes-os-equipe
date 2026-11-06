{{-- Coffre (DA-7, composants.md § 10 « pages sans maquette ») : titre-page → filtre + « Ajouter » (bouton bouton-fin) →
     formulaire (champ) → une section par profil, une .ligne par élément ; suppression = bouton-danger après confirmation.
     Coffre (vault) de Hermes : un coffre par profil. Liste des métadonnées (jamais de valeur secrète), ajout, modification
     (= suppression + recréation, Hermes ne sait pas modifier), suppression. Chaque écriture passe par la confirmation + TOTP. --}}
@php
    $types = ['login' => 'Identifiant', 'payment' => 'Carte', 'address' => 'Adresse'];
    $typesId = ['email' => 'e-mail', 'phone' => 'téléphone', 'username' => 'nom d’utilisateur'];
    $champs = [
        'login' => [['password', 'Mot de passe', 'password', true], ['otp_secret', 'Clé 2FA (base32 ou otpauth://)', 'password', false]],
        'payment' => [['card_number', 'Numéro de carte', 'password', true], ['cardholder_name', 'Titulaire', 'text', false], ['exp_month', 'Mois d’expiration (MM)', 'text', true], ['exp_year', 'Année d’expiration (AAAA)', 'text', true], ['cvc', 'Cryptogramme', 'password', true], ['billing_postal_code', 'Code postal de facturation', 'text', false]],
        'address' => [['address_line1', 'Adresse', 'text', true], ['address_line2', 'Complément', 'text', false], ['postal_code', 'Code postal', 'text', true], ['city', 'Ville', 'text', true], ['state', 'Région', 'text', false], ['country', 'Pays', 'text', true]],
    ];
    $typeForm = $modifie['kind'] ?? $kind;
    $erreur = fn (string $cle) => $errors->first($cle);
    // dc M2 L6 : la confirmation de suppression prend la place de la ligne visée ; le formulaire « Modifier » s'ouvre
    // sous la ligne cliquée. Si la ligne n'est plus affichée (filtre), repli en haut de page.
    $cible = fn (array $e) => $e['profil'].'/'.$e['id'];
    $affichees = collect($groupes)->flatten(1)->map($cible)->all();
    $suppression = ($confirmation['action'] ?? null) === \App\Hermes\Action::VaultSupprimer->value
        ? ($confirmation['donnees']['profil'] ?? '').'/'.($confirmation['donnees']['handle'] ?? '') : null;
    $confirmationLigne = $suppression !== null && in_array($suppression, $affichees, true);
    $modifieCible = $edition !== null && $edition !== 'creer' ? $editionProfil.'/'.$edition : null;
    $formulaireLigne = $modifieCible !== null && in_array($modifieCible, $affichees, true);
@endphp
<div @if ($poll && $edition === null) wire:poll.{{ $poll }} @endif>
<x-coque actif="vault" :releve="$releve">
    <x-resultat :resultat="$resultat" :attente="$enAttente !== null" />
    <div @class(['fige' => $fige])>
        <div class="titre-page">
            <h1>Coffre</h1>
            <span class="titre-page-sous">{{ \App\Interface\Nombres::accord($total, 'élément', 'éléments') }} · {{ \App\Interface\Nombres::accord($coffresUtilises, 'profil', 'profils') }}</span>
        </div>

        <div class="formulaire-actions">
            <div class="champ">
                <label for="v-filtre">Profil</label>
                <select id="v-filtre" wire:model.live="filtre">
                    <option value="">tous les profils</option>
                    @foreach ($profils as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                </select>
            </div>
            @if ($edition === null)
                <button type="button" class="bouton bouton-fin" @if ($fige) disabled @else wire:click="nouveau" @endif>Ajouter</button>
            @endif
        </div>
        @if ($fige)<p class="champ-aide">Ajout, modification et suppression indisponibles tant que Hermes ne répond pas.</p>@endif

        @if ($edition !== null && ! $formulaireLigne)
            @include('livewire.partials.vault-formulaire', ['classeFormulaire' => 'section'])
        @elseif ($confirmation && ! $confirmationLigne && ! $formulaireLigne)
            <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" />
        @endif

        @if ($groupes === [])
            <div class="etat-vide"><p>{{ $filtre === '' ? 'Aucun élément dans les coffres.' : 'Aucun élément dans le coffre de '.$filtre.'.' }}</p></div>
        @endif
        @foreach ($groupes as $profilG => $elements)
            <section class="section" aria-labelledby="v-g-{{ $profilG }}" wire:key="g-{{ $profilG }}">
                <h2 id="v-g-{{ $profilG }}">{{ $profilG }} <span class="titre-page-sous">{{ \App\Interface\Nombres::accord(count($elements), 'élément', 'éléments') }}</span></h2>
                @foreach ($elements as $e)
                    @if ($confirmationLigne && $suppression === $cible($e))
                        <div wire:key="c-{{ $e['id'] }}">
                            <x-confirmation :confirmation="$confirmation" :etape-code="$etapeCode" classe="etat-vide confirmation-ligne" />
                        </div>
                    @else
                        <div class="ligne" wire:key="e-{{ $e['id'] }}">
                            <div class="ligne-texte">
                                <b class="[overflow-wrap:anywhere]">{{ $e['label'] }}</b>
                                <small class="[overflow-wrap:anywhere]">{{ $types[$e['kind']] ?? $e['kind'] }}@if ($e['origin']) · {{ $e['origin'] }}@endif @if ($e['identifier']) · {{ $typesId[$e['identifier_type']] ?? $e['identifier_type'] }} : {{ $e['identifier'] }}@endif @if ($e['has_otp']) · <span class="ok">2FA</span>@endif · {{ $e['id'] }}</small>
                            </div>
                            <span class="formulaire-actions">
                                <button type="button" wire:click="modifier('{{ $e['profil'] }}', '{{ $e['id'] }}')" class="bouton-texte" @disabled($fige) aria-expanded="{{ $modifieCible === $cible($e) ? 'true' : 'false' }}" aria-label="Modifier {{ $e['label'] }} ({{ $e['profil'] }})">Modifier</button>
                                <button type="button" wire:click="demanderSuppression('{{ $e['profil'] }}', '{{ $e['id'] }}')" id="v-suppr-{{ $e['id'] }}" class="bouton-danger bouton-fin" @disabled($fige) aria-label="Supprimer {{ $e['label'] }} ({{ $e['profil'] }})" x-data="declencheur" x-on:click="memoriser">Supprimer</button>
                            </span>
                        </div>
                        @if ($formulaireLigne && $modifieCible === $cible($e))
                            @include('livewire.partials.vault-formulaire', ['classeFormulaire' => 'formulaire-ligne'])
                        @endif
                    @endif
                @endforeach
            </section>
        @endforeach
        <p class="histo-detail">Les valeurs sont chiffrées par Hermes et ne reviennent jamais dans la console. Hermes ne sait pas modifier un élément : « Modifier » le supprime puis le recrée avec les nouvelles valeurs, sous une nouvelle poignée.</p>
    </div>
</x-coque>
</div>
