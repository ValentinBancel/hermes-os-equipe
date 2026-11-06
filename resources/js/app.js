// Alpine et Livewire sont chargés par Livewire (build csp_safe, ADR 0001) : aucun script inline,
// aucune expression évaluée. Les comportements sont déclarés ici avec Alpine.data() et appelés par
// leur nom dans les vues (x-data="dialogue", @keydown.escape="echap"…).

let retourFocus = null;

// Focus rendu au bouton d'origine à la fermeture d'une confirmation (design-critic L4 M3). Le bouton peut n'être
// remis dans le DOM qu'après la fin du morph Livewire (confirmation rendue à sa place) : on réessaie quelques images.
// S'il a disparu ou est masqué (code affiché, Hermes délié), le focus va au repli visible du même panneau.
function rendreFocus(id, essais = 20) {
    const visible = (el) => el && el.getClientRects().length > 0;
    const cible = id ? document.getElementById(id) : null;
    if (visible(cible)) {
        cible.focus();
        return;
    }
    if (essais > 0) {
        requestAnimationFrame(() => rendreFocus(id, essais - 1));
        return;
    }
    const repli = [...document.querySelectorAll('[data-focus-repli]')].find(visible);
    if (repli && id) {
        repli.focus();
    }
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    // Mot de passe provisoire (L3, M1 secu) : reçu une seule fois par l'événement Livewire « mdp-provisoire »,
    // jamais rendu par le serveur. Affiché par groupes de 5 ; « Copier » copie les caractères bruts ; « Masquer »
    // l'efface définitivement (aucune bascule « Afficher »). Si l'API presse-papiers manque (HTTP), il reste lisible.
    Alpine.data('provisoire', () => ({
        visible: false,
        message: '',
        aide: '',
        motDePasse: '',
        groupes: [],
        copie: false,
        recevoir(evenement) {
            const { nom, email, motDePasse } = evenement.detail ?? {};
            if (!motDePasse) {
                return;
            }
            this.motDePasse = motDePasse;
            this.groupes = motDePasse.match(/.{1,5}/g) ?? [];
            this.message = `Compte de ${nom} créé.`;
            this.aide = `Mot de passe provisoire de ${email}, affiché une seule fois : à changer à la première connexion, puis double authentification.`;
            this.copie = false;
            this.visible = true;
            this.$nextTick(() => this.$el.focus());
        },
        async copier() {
            if (!this.motDePasse || !navigator.clipboard) {
                return;
            }
            await navigator.clipboard.writeText(this.motDePasse);
            this.copie = true;
            setTimeout(() => { this.copie = false; }, 2000);
        },
        libelle() {
            return this.copie ? 'Copié' : 'Copier';
        },
        masquer() {
            this.motDePasse = '';
            this.groupes = [];
            this.message = '';
            this.aide = '';
            this.visible = false;
            // dc m1 L3 : le focus revient au bouton d'en-tête de la section Comptes (et non sur BODY).
            this.$nextTick(() => document.getElementById('btn-nouveau-compte')?.focus());
        },
    }));

    // Code d'appairage (L4) : reçu une seule fois par l'événement « code-appairage », jamais rendu par le serveur.
    // Compte à rebours à la minute ; à l'expiration, « Code expiré à HH:MM. Générez-en un nouveau. » reste dans la
    // même zone jusqu'à la prochaine action (design-critic M4) ; « Délier » efface tout sans message.
    Alpine.data('codeAppairage', () => ({
        code: '',
        expireA: 0,
        reste: '',
        expire: '',
        minuterie: null,
        copie: false,
        // Fuseau d'affichage de l'application (Temps::fuseau(), config hermes-os.fuseau, injecté par la vue) : « Code expiré à HH:MM » concorde
        // avec les heures rendues côté serveur (« dernier relevé à »), quel que soit le fuseau du navigateur (m9).
        fuseau: undefined,
        init() {
            this.fuseau = this.$root.dataset.fuseau || undefined;
        },
        recevoir(evenement) {
            const { code, expireA } = evenement.detail ?? {};
            if (!code) {
                return;
            }
            this.code = code;
            this.expireA = expireA;
            this.expire = '';
            this.copie = false;
            this.actualiser();
            clearInterval(this.minuterie);
            this.minuterie = setInterval(() => this.actualiser(), 15000);
            // « Générer un code » vient d'être masqué : le focus passe à « Copier » au lieu de tomber sur <body>.
            retourFocus = 'btn-copier-code';
            this.$nextTick(() => rendreFocus('btn-copier-code'));
        },
        actualiser() {
            const secondes = this.expireA - Math.floor(Date.now() / 1000);
            if (secondes <= 0) {
                const heure = new Date(this.expireA * 1000).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', timeZone: this.fuseau });
                this.effacer();
                this.expire = `Code expiré à ${heure}. Générez-en un nouveau.`;
                return;
            }
            this.reste = `expire dans ${Math.max(1, Math.ceil(secondes / 60))} min`;
        },
        effacer() {
            clearInterval(this.minuterie);
            this.code = '';
            this.reste = '';
            this.expire = '';
        },
        // Copie le code tel qu'affiché : la saisie côté pont ignore le tiret.
        async copier() {
            if (!this.code || !navigator.clipboard) {
                return;
            }
            await navigator.clipboard.writeText(this.code);
            this.copie = true;
            setTimeout(() => { this.copie = false; }, 2000);
        },
        libelle() {
            return this.copie ? 'Copié' : 'Copier';
        },
        destroy() {
            clearInterval(this.minuterie);
        },
    }));

    // Bouton « Copier » d'un texte fixe (commande de liaison, design-critic m1) : « Copié » pendant 2 s.
    Alpine.data('copie', () => ({
        copie: false,
        async copier() {
            if (!navigator.clipboard) {
                return;
            }
            await navigator.clipboard.writeText(this.$el.dataset.texte);
            this.copie = true;
            setTimeout(() => { this.copie = false; }, 2000);
        },
        libelle() {
            return this.copie ? 'Copié' : (this.$el.dataset.libelle ?? 'Copier la commande');
        },
    }));

    // Formulaire replié derrière un bouton (« Créer le compte ») : ouvert si la vue le demande (erreurs).
    Alpine.data('depliable', () => ({
        ouvert: false,
        init() {
            this.ouvert = this.$el.dataset.ouvert === '1';
        },
        basculer() {
            this.ouvert = !this.ouvert;
        },
    }));

    // Bouton qui ouvre une boîte de confirmation : le focus y revient à la fermeture (ux/02, accessibilité).
    Alpine.data('declencheur', () => ({
        memoriser() {
            retourFocus = this.$el.id || null;
        },
    }));

    // Boîte de confirmation modale (role="dialog" aria-modal) : focus sur le premier champ, sinon sur
    // « Annuler » ; Échap = Annuler ; Tab reste dans la boîte ; focus rendu au bouton d'origine.
    Alpine.data('dialogue', () => ({
        init() {
            this.$nextTick(() => {
                const champ = this.$el.querySelector('input:not([type=hidden]):not(:disabled), textarea:not(:disabled)');
                const annuler = this.$el.querySelector('[data-annuler]');
                (champ || annuler || this.$el).focus();
            });
        },
        destroy() {
            rendreFocus(retourFocus);
        },
        echap() {
            this.$wire.fermerConfirmation();
        },
        boucler(e) {
            const focusables = [...this.$el.querySelectorAll('a[href], button:not(:disabled), input:not(:disabled), textarea:not(:disabled), select:not(:disabled)')];
            if (focusables.length === 0) {
                return;
            }
            const premier = focusables[0];
            const dernier = focusables[focusables.length - 1];
            if (e.shiftKey && document.activeElement === premier) {
                e.preventDefault();
                dernier.focus();
            } else if (!e.shiftKey && document.activeElement === dernier) {
                e.preventDefault();
                premier.focus();
            }
        },
    }));

    // Compositeur de la Messagerie : Entrée envoie, Maj+Entrée = retour à la ligne (build CSP : méthode, pas d'expression à bloc).
    Alpine.data('compositeur', () => ({
        entree(e) {
            if (e.shiftKey || e.isComposing) {
                return;
            }
            e.preventDefault();
            this.$wire.envoyer();
        },
    }));

    // Code TOTP à 6 chiffres : compteur « n/6 » (m12) et bouton actif au 6ᵉ chiffre seulement.
    Alpine.data('codeTotp', () => ({
        n: 0,
        saisir(e) {
            const propre = e.target.value.replace(/\D/g, '').slice(0, 6);
            if (propre !== e.target.value) {
                e.target.value = propre;
                e.target.dispatchEvent(new Event('input'));
            }
            this.n = propre.length;
        },
        get compteur() {
            return this.n + '/6';
        },
        get incomplet() {
            return this.n < 6;
        },
    }));

    // Champ de texte avec compteur « 38 / 200 » et bouton désactivé tant qu'il est vide.
    Alpine.data('compteurTexte', (max = 200) => ({
        n: 0,
        max,
        init() {
            const champ = this.$el.querySelector('[data-compte]');
            this.n = champ ? champ.value.length : 0;
        },
        saisir(e) {
            this.n = e.target.value.length;
        },
        get compteur() {
            return this.n + ' / ' + this.max;
        },
        get vide() {
            return this.n === 0;
        },
    }));

    // Codes de secours : « Continuer » actif une fois la case cochée.
    Alpine.data('caseRequise', () => ({
        coche: false,
        basculer(e) {
            this.coche = e.target.checked;
        },
        get bloque() {
            return !this.coche;
        },
        get tabindex() {
            return this.coche ? 0 : -1;
        },
    }));

    // Menu « Plus » de la barre basse (<details>) — A5 : Échap ferme et rend le focus au summary ; un clic ou un
    // focus hors du menu le ferme.
    Alpine.data('menuPlus', () => ({
        fermer() {
            this.$el.open = false;
        },
        echap() {
            if (this.$el.open) {
                this.$el.open = false;
                this.$el.querySelector('summary')?.focus();
            }
        },
        sortie(e) {
            if (e.relatedTarget && !this.$el.contains(e.relatedTarget)) {
                this.$el.open = false;
            }
        },
    }));
});
