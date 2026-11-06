{{-- Accueil en chargement (composants.md § 9) : même coque et même bloc du jour, état « chargement » à la place des
     chiffres et de la frise ; un seul role="status". --}}
<div>
<x-coque actif="accueil" accueil>
    <section class="jour verre" aria-label="Le jour en cours">
        <div class="jour-legende">Situation au {{ lcfirst(\App\Interface\Temps::etiquette()) }}</div>
        <h1><span role="status">Chargement…</span></h1>
        <div class="chargement" aria-busy="true"><i></i><i></i><i></i></div>
    </section>
</x-coque>
</div>
