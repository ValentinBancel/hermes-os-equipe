{{--
    Coque de toutes les pages de la console (DA-7 « Épure », composants.md § 1-2) : bande en arc (haute sur l'accueil,
    courte ailleurs), conteneur 1200 px, barre haute en verre (wordmark, « pour <client> », nav 6 entrées, présence,
    avatar → Compte), menu déroulant sous 760 px, état « Hermes ne répond pas » quand les données sont figées.
    Rendue DANS le composant Livewire de la page : présence et état suivent chaque rafraîchissement.
--}}
@props(['actif', 'releve' => null, 'accueil' => false])
@php
    use App\Interface\Capacites;
    use App\Interface\Presence;

    $liens = [
        ['accueil', 'Accueil', route('accueil')],
        ['planifiees', 'Planifiées', route('planifiees')],
        ['consommation', 'Consommation', route('consommation')],
        ['poles', 'Pôles', route('poles')],
    ];
    if (Capacites::coffre()) {
        $liens[] = ['vault', 'Coffre', route('vault')];
    }
    if (Capacites::messagerie()) {
        $liens[] = ['messagerie', 'Messagerie', route('messagerie')];
    }
    $client = config('hermes-os.client');
    $connectes = Presence::connectes();
@endphp
<div class="bande {{ $accueil ? '' : 'bande-interieure' }}" aria-hidden="true"></div>
<div class="conteneur">
    <header class="barre verre">
        <a class="marque" href="{{ route('accueil') }}"><span class="marque-nom">hermes-os</span>@if (filled($client))<span class="marque-client">pour {{ $client }}</span>@endif</a>
        <nav class="nav" aria-label="Navigation principale">
            @foreach ($liens as [$cle, $nom, $href])
                <a href="{{ $href }}" @class(['actif' => $cle === $actif]) @if ($cle === $actif) aria-current="page" @endif>{{ $nom }}</a>
            @endforeach
        </nav>
        <div class="presence">
            <div class="connectes"><i class="point-ok"></i><span>{{ Presence::libelle($connectes) }}</span></div>
            <a @class(['avatar-moi', 'actif' => $actif === 'compte']) href="{{ route('compte') }}" aria-label="Mon compte" title="Mon compte · {{ auth()->user()?->name }}">{{ Presence::initialesDe(auth()->user()) }}</a>
        </div>
        <details class="menu-mobile">
            <summary>Menu</summary>
            <nav class="verre" aria-label="Navigation principale (mobile)">
                @foreach ($liens as [$cle, $nom, $href])
                    <a href="{{ $href }}" @class(['actif' => $cle === $actif])>{{ $nom }}</a>
                @endforeach
                <a href="{{ route('compte') }}" @class(['actif' => $actif === 'compte'])>Compte</a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="bouton-texte">Se déconnecter</button></form>
            </nav>
        </details>
    </header>

    @if ($releve?->fige())
        @php
            $de = $releve->donneesDe();
            $depuis = $releve->injoignableDepuis();
        @endphp
        <div class="etat-erreur" role="alert">
            <b>Hermes ne répond pas</b>
            <p>@if ($de === null)Aucune donnée reçue du pont hermes-os : vérifier le service sur le serveur.@else Ce qui est affiché est figé : données de {{ \App\Interface\Temps::absolu($de) }}{{ $depuis ? ', pont injoignable depuis '.\App\Interface\Temps::heure($depuis) : '' }}. Lancer, suspendre et reprendre sont désactivés.@endif</p>
            <button type="button" class="bouton bouton-fin" wire:click="$refresh">Réessayer</button>
        </div>
    @endif

    {{-- .fige : les données affichées sont périmées (app.css, « Données figées »). --}}
    <main id="contenu" @if ($releve?->fige()) class="fige" @endif>
        {{ $slot }}
    </main>
</div>
