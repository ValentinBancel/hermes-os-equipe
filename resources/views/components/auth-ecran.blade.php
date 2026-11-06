{{--
    Écrans de connexion (DA-7, composants.md § 10 « Écrans de connexion ») : bande courte, conteneur étroit, carte
    centrée SANS verre (décision L2) : section sur la lavande avec h2, formulaire, bouton primaire, bouton texte.
    Écrans publics (connexion, code) : aucune marque ni « hermes » (ADR 0004). Écrans après le mot de passe : wordmark.
--}}
@props(['titre', 'onglet', 'public' => false])
<x-layout :title="$public ? $onglet : $onglet.' — hermes-os'">
    <div class="bande bande-interieure" aria-hidden="true"></div>
    <div class="conteneur">
        <main id="contenu" class="mx-auto max-w-[420px] pt-[var(--espace-24)]">
            @unless ($public)
                <p class="marque max-[760px]:mt-[var(--espace-3)]"><span class="marque-nom">hermes-os</span></p>
            @endunless
            <section class="section mt-[var(--espace-6)]">
                <h2>{{ $titre }}</h2>
                <div class="formulaire mt-[var(--espace-5)]">
                    @include('auth._messages')
                    {{ $slot }}
                </div>
            </section>
        </main>
    </div>
</x-layout>
