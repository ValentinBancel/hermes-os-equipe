# DA-7 « Épure » — design system figé

Validée le 03/11/2026 (« go code »). Toute modification passe par lead-design et le cto.
Sources : `tokens.css` (jetons, seule source des valeurs), `composants.css` (briques), `composants.md` (usage), `specimen.html` (rendu), `maquettes/` (références DA-7 : accueil, planifiées, compte + `fiche-DA-7.md`).

## Principes
- Fond lavande uni `#F5F6FB`. Une seule bande bleue (`#BCCAF4` → `#A8BBF0`) à bord inférieur en arc par page : haute sur l'accueil, courte sur les pages intérieures, jamais derrière du texte courant.
- Indigo `#253081` = seul accent (wordmark, liens d'action, chiffres, bouton primaire, repère « maintenant »). Rouge `#B02F2F` = erreurs seulement. Vert `#14704B` = « ok » seulement.
- Verre (blanc 60 %, flou 20 px, liseré 1 px, sans ombre, sans reflet) sur 3 surfaces et pas une de plus : barre haute, bloc du jour (accueil), panneau « Hermes relié » (Compte).
- Le reste est posé sur la lavande, séparé par de l'espace et des filets `#E1E4F1`. Pas de cartes encadrées, pas d'ombres.
- Outfit seule (400/500/600), chiffres tabulaires. JetBrains Mono pour la commande de liaison uniquement. Polices auto-hébergées (`fonts/`, OFL 1.1 ; textes de licence `fonts/OFL-Outfit.txt` et `fonts/OFL-JetBrainsMono.txt`).
- Conteneur 1200 px, gouttière 24 px (16 px sous 760 px). Rayons : champ 14, barre 20, bloc du jour et panneau 28 (22 sous 760 px), pilule 999.

## À faire
- Écrire les valeurs uniquement via les jetons `var(--…)`.
- Distinguer un état par un mot ou un point en plus de la couleur (réussie/échec, connecté/hors ligne).
- Positions calculées (frise, barres) par classes générées (`frise-t-<minute>`, `histo-h-<pourcent>`) : la CSP interdit l'attribut `style`. Régénérer avec `python3 design-system/outils/generer-positions.py`.
- Garder des cibles d'action lisibles : liens d'action permanents sous 1100 px (l'affichage au survol seul n'existe que sur bureau).

## À ne pas faire
- Pas de deuxième couleur d'accent, pas de dégradé hors de la bande, pas d'ombre portée, pas de verre sur de la liste ou un formulaire.
- Pas de texte courant sur la bande. Pas de `style=""` dans les vues.
- Pas de gris `--couleur-gris-doux` pour du texte qui porte une information (3,07:1 sur le fond : réservé aux lignes suspendues et aux contours de champ).
- Pas de police distante (la CSP n'autorise que `'self'`).

## Contrastes mesurés (WCAG 2.2, rapport de luminance)
| Paire | Rapport | Verdict |
|---|---|---|
| encre / fond | 15,64 | AA |
| gris / fond | 7,89 | AA |
| indigo / fond | 10,70 | AA |
| erreur / fond | 5,91 | AA |
| ok / fond | 5,64 | AA |
| blanc / indigo (bouton) | 11,55 | AA |
| blanc / erreur (survol danger) | 6,38 | AA |
| indigo / entrée de nav active (13 %) | 8,48 | AA |
| gris / verre sur bande | 7,08 | AA |
| indigo / verre sur bande | 9,60 | AA |
| erreur / verre sur bande | 5,30 | AA |
| ok / verre sur bande | 5,06 | AA |
| pastille « suspendue » (#6B6D82 / fond) | 4,71 | AA |
| gris / bande #A8BBF0 | 4,47 | usage interdit (aucun texte courant sur la bande) |
| gris-doux / fond | 3,07 | texte décoratif d'une ligne suspendue seulement |
| contour de champ gris-doux / blanc | 3,31 | AA composant non textuel (≥ 3) |

Limite connue : les barres de l'histogramme (`#C9CEF2`, 1,43:1) sont décoratives ; la valeur est toujours écrite en toutes lettres (chiffre + `title`).
