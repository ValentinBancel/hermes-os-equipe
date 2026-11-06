{{-- M4 : bloc de chargement à la taille finale, sans texte, retiré de l'arbre d'accessibilité (.squelette du design system).
     Bloc par défaut ; « inline-block » passé en classe pour un squelette dans une ligne de texte. --}}
<span {{ $attributes->class(['squelette', 'block' => ! str_contains($attributes->get('class', ''), 'inline-block')]) }} aria-hidden="true"></span>
