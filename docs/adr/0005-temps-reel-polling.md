# ADR 0005 — « Temps réel » par polling Livewire, sans WebSocket

- Statut : proposé (cto, 08/10/2026)

## Décision
`wire:poll.5s` sur l'accueil (et la messagerie), 30 s ailleurs ; le kanban a été retiré au lot 01 (03/11/2026) ; Livewire suspend le polling quand l'onglet est masqué. Le client Laravel met les réponses du pont en cache 3 s.

## Raisons
Un seul utilisateur ; les cartes changent à l'échelle de la minute. Reverb ajouterait un processus, une route WebSocket dans Traefik et une règle CSP `connect-src` de plus. Coût du polling : environ 12 requêtes/min sur un socket local, négligeable.

## Réévaluer si
Flux de logs en direct ou chat (v2).
