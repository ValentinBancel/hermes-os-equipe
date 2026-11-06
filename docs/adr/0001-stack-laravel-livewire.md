# ADR 0001 — Stack : Laravel 13 + Livewire 4 + Tailwind 4, Sail en dev

- Statut : proposé (cto, 08/10/2026), imposé par le brief ; versions à reconfirmer au lot 04
- Contexte : le mainteneur travaille en Laravel/Livewire au quotidien ; il doit pouvoir maintenir l'outil seul.

## Décision
- Laravel 13 (v13.35.0 vérifiée le 08/10/2026), PHP 8.5 ; Livewire 4 (v4.4.7) en **mode `csp_safe`** ; Tailwind 4 + Vite 8 ; tests Pest 5.
- **Pas de starter kit** (Breeze, Jetstream, kits Livewire) : ils imposent une interface générique que le brief interdit. On part du squelette `laravel/laravel` nu, avec Fortify sans vues.
- **Pas de Filament**, pas de bibliothèque de composants d'interface (Flux…) : identité sur mesure décidée par le propriétaire.
- Pas de framework JS : Alpine (embarqué par Livewire) suffit.

## Conséquences
- `csp_safe` interdit les expressions Alpine complexes dans le HTML : la logique va dans des composants `Alpine.data()` déclarés dans le bundle. Contrainte transmise à `ui` et `dev`.
- Pest 5 exige PHP ^8.4 : compatible avec 8.5.
