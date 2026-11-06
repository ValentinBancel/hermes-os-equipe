{{-- Titre de page intérieure (DA-7, composants.md § 5) : h1 + mention secondaire à droite (« 14 tâches · 1 suspendue »).
     Page d'objet (fiche) : h1 = l'objet, mention = lien de retour vers la liste. Slots facultatifs : $droite, $sous. --}}
@props(['titre', 'objet' => null, 'retour' => null])
<div class="titre-page">
    <h1>{{ $objet ?? $titre }}</h1>
    @if ($objet !== null && $retour !== null)
        <a class="titre-page-sous" href="{{ $retour }}">{{ $titre }}</a>
    @endif
    @isset($droite)<div class="titre-page-sous">{{ $droite }}</div>@endisset
</div>
@isset($sous)<div class="titre-page-sous">{{ $sous }}</div>@endisset
