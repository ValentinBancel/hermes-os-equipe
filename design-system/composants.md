# Composants DA-7 « Épure »

Feuille : `composants.css` (jetons : `tokens.css`). Rendu de tous les composants : `specimen.html` (captures dans `captures/`). Noms de classes en français. Les extraits ci-dessous sont l'HTML exact à reproduire en Blade.
Dans Laravel : `resources/css/app.css` doit importer les deux fichiers (voir « Branchement »).

## Branchement
`@import 'tailwindcss'; @import '../../design-system/tokens.css'; @import '../../design-system/composants.css';` Les polices sont résolues par Vite (`fonts/*.woff2`, hachées au build). Les classes utilitaires Tailwind restent utilisables pour l'espacement local, avec les jetons (`mt-[var(--espace-6)]`).

## 1. Page
```html
<body>
  <div class="bande"></div>            <!-- accueil : haute -->
  <div class="bande bande-interieure"></div>  <!-- pages intérieures : courte (une seule des deux) -->
  <div class="conteneur"> … </div>
</body>
```
La bande est en `position:absolute` derrière le conteneur ; elle ne porte jamais de texte courant.

## 2. Coque (barre haute en verre)
```html
<header class="barre verre">
  <a class="marque" href="/"><span class="marque-nom">hermes-os</span><span class="marque-client">pour Exemple</span></a>
  <nav class="nav"><a href="/" class="actif">Accueil</a><a href="/planifiees">Planifiées</a>… 6 entrées texte</nav>
  <div class="presence"><div class="connectes"><i class="point-ok"></i><span>3&nbsp;connectés</span></div><a class="avatar-moi" href="/compte" aria-label="Mon compte">CM</a></div>
  <details class="menu-mobile"><summary>Menu</summary><nav class="verre">…mêmes liens, .actif sur la page courante</nav></details>
</header>
```
Sous 760 px : `.nav` et `.connectes` disparaissent, `.menu-mobile` apparaît (sans JavaScript). Sur la page Compte, `avatar-moi actif` (anneau indigo).

## 3. Bloc du jour (accueil)
```html
<section class="jour verre">
  <div class="jour-legende">Situation au mardi 3 novembre</div>
  <h1><span>2 erreurs depuis 24 h</span> <span class="separateur">·</span> <span>3 décisions</span></h1>
  <p class="jour-etat">Gateway en marche · <a href="…">1 livraison échouée</a></p>
  <div class="compteurs"><div><b>2</b><span>erreurs 24 h</span></div>… ×3</div>
  <div class="frise"><div class="frise-ligne"></div>
    <span class="frise-point frise-t-120"></span>            <!-- réussie : plein indigo ; 120 = minute de la journée (02:00) -->
    <span class="frise-point echec frise-t-555"></span>      <!-- échec : rouge -->
    <span class="frise-point prevu frise-t-990"></span>      <!-- prévue : anneau -->
    <span class="frise-maintenant frise-t-872"><i>maintenant 14:32</i></span></div>
  <div class="frise-heures"><span>00 h</span><span>06 h</span><span>12 h</span><span>18 h</span><span>24 h</span></div>
</section>
```
`frise-t-N` : N = minutes depuis 00:00 (0 à 1440). Calcul côté PHP : `hour*60+minute`.

## 4. Section à filet, liste dense
```html
<div class="grille-sections">          <!-- 2 colonnes, 1 colonne sous 900 px ; .s-erreurs/.s-decider/.s-prochaines/.s-tokens fixent l'ordre mobile -->
  <section class="s-decider"><h2>À décider</h2>
    <div class="ligne"><div class="ligne-texte"><b>Titre</b><small>profil · il y a 6 min</small></div><a class="pastille-action plein" href="#">Examiner</a></div>
    <div class="ligne"><div class="ligne-texte"><b class="rouge">tâche</b><small class="rouge">Livraison échouée</small></div><time>09:15</time></div>
    <div class="ligne ligne-prochaine"><span class="ligne-heure">16:30</span><div class="ligne-texte"><b>tâche</b></div><time>dans 2 h</time></div>
  </section>
```
`.pastille-action` = contour indigo ; `.plein` = l'action prioritaire de la liste (une par liste).
Histogramme : `<div class="chiffre-grand"><b>412 k</b><span>…</span></div><div class="histo"><div><i class="histo-h-76" title="28/10 · 388 k"></i></div>…×7</div><div class="histo-jours"><span>28</span>…</div><p class="histo-detail">…</p>` (le dernier jour est en indigo automatiquement).

## 5. Page intérieure : titre et liste Planifiées
```html
<div class="titre-page"><h1>Planifiées</h1><span class="titre-page-sous">14 tâches · 1 suspendue</span></div>
<div class="liste">
  <div class="tache tache-entete"><span>Tâche</span><span>Fréquence</span><span>Dernière exécution</span><span>Prochaine</span><span class="tache-actions"></span></div>
  <div class="tache">
    <div class="tache-nom"><b>sauvegarde-sessions</b><small>profil ops</small></div>
    <span class="tache-frequence">Chaque nuit à 02:00</span>
    <div class="tache-derniere reussie"><b>Réussie</b><small>03/11 · 02:00</small></div>   <!-- .echec : point et texte rouges -->
    <div class="tache-prochaine"><b>04/11 · 02:00</b></div>
    <span class="tache-actions"><a href="#">Lancer</a><a href="#">Suspendre</a></span>
  </div>
  <div class="tache suspendue"> … <b>nom<span class="pastille">suspendue</span></b> … </div>
</div>
```
Actions : visibles au survol/focus sur bureau (> 1100 px), liens texte permanents en dessous.

## 6. Comptes et fiche
```html
<div class="duo"><div>
  <div class="section"><h2>Comptes <a class="bouton bouton-fin" href="#">Nouveau compte</a></h2>
    <div class="compte"><div class="compte-qui"><span class="avatar connecte">TR</span><div><b>Nom</b><small>e-mail</small></div></div><time class="connecte">connecté</time></div></div>
  <div class="section"><h2>Mon compte</h2><div class="fiche-ligne"><span>Nom</span><span>Camille Martin</span><a href="#">Modifier</a></div></div>
</div><aside class="panneau-relie verre">…</aside></div>
```

## 7. Formulaires et boutons
`bouton` (pilule indigo, primaire) · `bouton bouton-fin` · `bouton-texte` (lien d'action) · `bouton-danger` (contour rouge, plein au survol, pour les actions destructrices) · `disabled` ou `aria-disabled="true"` = atténué.
```html
<form class="formulaire"><div class="champ"><label for="x">Libellé</label><input id="x"><span class="champ-aide">Aide</span></div>
  <div class="champ erreur"><label for="y">…</label><input id="y" aria-invalid="true" aria-describedby="e"><span class="champ-erreur" id="e">Message</span></div>
  <div class="formulaire-actions"><button class="bouton">Valider</button><button class="bouton-texte" type="button">Annuler</button></div></form>
```

- Cibles tactiles (sous 760 px) : tout cliquable fait au moins `var(--espace-10)` (40 px) de haut. `.bouton`, `.bouton-texte`, `.bouton-danger` : `min-height` + `inline-flex` centré. Liens de ligne ou de texte (nom de ligne, période, « Changer », retour) et libellé de case : padding vertical compensé par une marge négative, rendu inchangé. Contrôle : `scripts/mesure-cibles.sh` (0 cible < 40 px à 390).
- Confirmation d’une ligne de liste (`.confirmation-ligne`, Coffre) : elle prend la place de la ligne visée ; lavande, filet 1 px, rayon champ, jamais de verre ; bouton final `.bouton-danger-plein` qui nomme l’objet (« Supprimer « malt » »), « Annuler » en `.bouton-texte`. Un formulaire de modification s’ouvre sous la ligne cliquée (`.formulaire-ligne`).

## 8. Panneau verre « Hermes relié »
```html
<aside class="panneau-relie verre"><h2>Hermes relié</h2>
  <div class="instance"><b>serveur-principal</b><span><span class="ok">En ligne</span> · version 0.14 · vu à l’instant</span></div>   <!-- .hors-ligne en rouge -->
  <h3>Relier une instance</h3><ol><li>…</li><li>…</li><li>…</li></ol>
  <div class="commande">curl -fsSL https://… | sh</div>
  <div class="code-appairage"><b>AB7K-29QX</b><span>expire dans 9 min</span></div></aside>
```
- **« Copier » inline** : `.bouton-texte` sous la commande (`<p class="commande-copie"><button class="bouton-texte">Copier la commande</button></p>`) ou dans la ligne du code (`<span>expire dans 9 min <button class="bouton-texte">Copier</button></span>`) ; le libellé devient « Copié » deux secondes. Même forme pour le mot de passe provisoire et la clé 2FA.
- **Confirmation dans le panneau** : `<div class="etat-vide confirmation-panneau">` remplace le bouton qui l’a ouverte (fond menu du verre, filet 1 px, rayon champ) ; question en `b`, conséquence en `p`, puis le bouton final et « Annuler ».
- **Danger confirmé** : `.bouton-danger.bouton-danger-plein` = bouton final rouge plein d’une confirmation destructive (« Délier »). Jamais ailleurs que dans une confirmation.

## 9. États et messages
```html
<div class="etat-vide"><b>Aucune tâche planifiée</b><p>Explication d’une phrase.</p><a class="bouton" href="#">Action</a></div>
<div class="etat-erreur"><b>Hermes ne répond pas</b><p>Ce qui est affiché est figé.</p><a class="bouton-texte" href="#">Réessayer</a></div>
<div class="chargement" aria-busy="true"><i></i><i></i><i></i></div>      <!-- barres filet qui pulsent (réduit au minimum si prefers-reduced-motion) -->
<p class="flash ok" role="status">…</p>  <!-- .ok vert · .erreur rouge (role="alert") · sans modificateur : indigo neutre -->
```
Les états vivent sur la lavande entre filets : jamais sur du verre.

## 10. Pages sans maquette : comment les composer (aucune nouvelle forme)
- **Consommation** : `titre-page` (+ période en `titre-page-sous`) → `chiffre-grand` + `histo` pour le total sur 7 jours → `liste` dont chaque `ligne` = profil (`ligne-texte`) + volume (`time`) ; états vide/erreur/chargement standard. Pas de graphique supplémentaire.
- **Pôles** : `titre-page` → `grille-sections` (une `section` par pôle, `h2` = nom du pôle) → `ligne` par agent (`ligne-texte` : nom + rôle ; `time` : dernière activité). Un agent en erreur = `rouge`.
- **Coffre** : `titre-page` → `liste` de type Planifiées (`tache`, colonnes : nom, usage, dernière utilisation, expiration) ; ajout via `bouton bouton-fin`, suppression via `bouton-danger` précédée d’une confirmation sur la lavande (`etat-erreur` + deux boutons) ; une valeur secrète n’est jamais affichée, seulement « enregistrée le … ».
- **Messagerie** : `titre-page` → `liste` de lignes `ligne` (expéditeur en `b`, début du message en `small`, heure en `time`) ; non lu = `b` en poids 600 ; réponse = `formulaire` (champ + `bouton`) ; décision attendue = `pastille-action plein`.
- **Écrans de connexion** (connexion, double authentification, appairage) : `bande` + `conteneur` étroit, un unique `panneau-relie verre` (autorisé : 4e usage de verre à valider avec lead-design, sinon section sur lavande) contenant `h2`, `formulaire`, `bouton` primaire, `bouton-texte` ; code à 6 chiffres affiché avec `code-appairage`.

## 11. Classes d’appoint et d’état (référencées par les vues)
- `.cache` : masque l’élément (`display:none !important`), y compris quand une autre règle fixe son `display`. Pour un masquage conditionnel côté serveur ; pas pour l’accessibilité (un contenu à lire par lecteur d’écran reste visible ou utilise `aria-hidden`).
- `.section-titre` : titre de section à filet bas, équivalent visuel du `h2` (même règle). À utiliser quand le titre n’est pas un `h2` pour le plan du document. Dans une `section`, il passe en flex avec le compteur ou le lien d’action aligné à droite.
- `.instance .hors-ligne` : mention rouge « hors ligne » d’une instance Hermes (`<span class="hors-ligne">`), à côté de `.ok` (vert). Toujours accompagnée du texte, jamais la couleur seule.
- `.etapes` : liste ordonnée numérotée (`<ol class="etapes"><li>…</li></ol>`), chiffre indigo en colonne de 24 px. Même rendu que `ol` dans `panneau-relie` (§ 8).
- `.s-erreurs`, `.s-decider`, `.s-prochaines`, `.s-tokens` : identifiants des quatre sections de l’accueil. Sans effet sur desktop ; sous 900 px de large, ils fixent l’ordre d’affichage (erreurs, à décider, prochaines, tokens) via `order`. À poser sur la `section` correspondante dans `grille-sections`.
