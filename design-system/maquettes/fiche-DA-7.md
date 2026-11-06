# DA-7 « Épure »

**Intention.** Réponse à « trop compliqué, fais une DA épurée » : peu d'éléments, beaucoup d'air, une hiérarchie lisible d'un coup d'œil (verdict → à décider → erreurs). Le glassmorphisme est conservé, mais limité à deux surfaces posées sur une seule forme calme.

**Composition.** Conteneur de 1200 px. Fond lavande #F5F6FB uni, avec une seule bande indigo clair à bord inférieur en arc (haute sur l'accueil, courte sur les pages intérieures pour ne jamais passer derrière du texte). Barre haute : wordmark « hermes-os », « pour Exemple », nav texte de 6 entrées (Accueil, Planifiées, Consommation, Pôles, Coffre, Messagerie ; ni Kanban ni Terminal), puis à droite une mention discrète « ● 3 connectés » et l'avatar du compte connecté (VB) qui mène à la page Compte. Plusieurs personnes ont chacune leur compte ; la 2FA est obligatoire pour tous, donc non affichée comme option. Pas de rôles, ni d'accès par page.
- **Accueil** (da-7-accueil.html) : bloc du jour (verdict h1, ligne d'état, 3 compteurs, frise 0-24 h + repère « maintenant »), puis À décider | Erreurs 24 h, Prochaines | Tokens, séparés par des filets. Mobile : menu déroulant + avatar, sections empilées.
- **Planifiées** (da-7-planifiees.html) : titre + « 14 tâches · 1 suspendue », liste dense de 14 tâches (nom + profil, fréquence en clair, dernière exécution avec point vert/rouge, prochaine). Échecs en rouge (veille-securite, rapport-hebdo), publication-hebdo suspendue en gris avec pastille. Lancer / Suspendre (Reprendre / Lancer pour la suspendue) au survol sur bureau, liens texte permanents sur mobile.
- **Compte** (da-7-compte.html) : « Mon compte » (nom, e-mail, mot de passe · changer, double authentification active, 2 sessions ouvertes) ; « Comptes » (5 personnes : nom, e-mail, connecté ou dernière connexion, point vert si connecté, bouton « Ajouter un compte ») ; panneau en verre « Hermes relié » (instance en 2 lignes, « Relier un autre Hermes » en 3 étapes, commande, code d'appairage). Sur mobile le panneau passe sous les comptes.

**Verre.** 2 surfaces par page (barre haute + bloc du jour sur l'accueil ; barre haute + panneau « Hermes relié » sur Compte ; barre haute seule sur Planifiées) : blanc .6, flou 20 px, liseré 1 px. Aucun reflet, lame ni ombre.

**Palette.** Lavande #F5F6FB, bande #BCCAF4 → #A8BBF0, indigo #253081 (seul accent), encre #1B1B2F, gris #4A4B60, filets #E1E4F1. Rouge #B02F2F pour les erreurs seulement, vert #14704B pour « ok ».

**Typo.** Outfit seule (400/500/600), chiffres tabulaires. JetBrains Mono uniquement pour la commande de liaison.

**Risques.** Verre peu spectaculaire (bande douce, par choix). Le « · » du séparateur peut rester en fin de ligne sur mobile dans la ligne d'état. Les actions de la liste Planifiées ne sont visibles qu'au survol sur bureau. Un wireframe est évité par les espacements, mais la page peut sembler sobre pour un client qui attend de l'effet.
