{{-- Pages d'erreur neutres (ux/03 § Erreurs d'accès) : pas de mention de hermes-os sur un 403 hors liste d'IP.
     DA-7 : bande courte, état d'erreur sur la lavande (composants.md § 9), ni marque ni verre. --}}
<x-layout :title="trim($__env->yieldContent('title'))">
    <div class="bande bande-interieure" aria-hidden="true"></div>
    <div class="conteneur">
        <main id="contenu" class="mx-auto max-w-[420px] pt-[var(--espace-24)]">
            <div class="titre-page"><h1>@yield('code')</h1></div>
            <div class="etat-erreur">
                <b>@yield('message')</b>
            </div>
        </main>
    </div>
</x-layout>
