{{--
    Charte de l'administration (ADR-0008) : prune, or champagne, ivoire ;
    Cormorant Garamond pour les titres, Source Sans 3 pour le corps (chargée
    par le panneau), DM Sans pour les micro-libellés.

    Feuille injectée par un point d'accroche du panneau (HEAD_END), après
    les styles de Filament : aucune compilation (pas de Vite côté backend).
    Les sélecteurs s'appuient sur les classes stables `fi-*` de Filament 3 ;
    une montée de version majeure de Filament impose de les revérifier.

    Contrastes vérifiés (WCAG 2.1 AA) : ivoire sur prune profonde 12,6:1,
    lavande sur prune profonde 4,8:1, or sur prune profonde 6,5:1,
    graphite sur ivoire 10,3:1, blanc sur prune 11,2:1.
--}}
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=cormorant-garamond:600,700|dm-sans:500,600&display=swap">
<style>
    :root {
        --pssfp-prune: #4A2E67;
        --pssfp-prune-profonde: #3A2452;
        --pssfp-lavande: #A592BD;
        --pssfp-lavande-claire: #EDE7F3;
        --pssfp-or: #D4AF6A;
        --pssfp-ivoire: #FAF7F2;
        --pssfp-graphite: #3C3C3C;
        --pssfp-police-titre: 'Cormorant Garamond', Georgia, serif;
        --pssfp-police-ui: 'DM Sans', system-ui, sans-serif;
    }

    /* Fond de page et écran de connexion */
    html:not(.dark) .fi-body,
    html:not(.dark) .fi-simple-layout {
        background-color: var(--pssfp-ivoire);
        color: var(--pssfp-graphite);
    }

    .fi-simple-main {
        border-top: 4px solid var(--pssfp-or);
    }

    /* Le logo est prune sur fond blanc : en mode sombre, il garde son
       cartouche blanc pour rester lisible. */
    .dark .fi-logo {
        background-color: #FFFFFF;
        border-radius: 0.5rem;
        padding: 0.25rem 0.5rem;
        box-sizing: content-box;
    }

    /* Barre du haut : filet or */
    .fi-topbar > nav {
        border-bottom: 2px solid var(--pssfp-or);
    }

    /* Menu latéral prune */
    .fi-body .fi-sidebar {
        background-color: var(--pssfp-prune-profonde);
    }

    .fi-body .fi-sidebar .fi-sidebar-header {
        background-color: #FFFFFF;
        border-bottom: 3px solid var(--pssfp-or);
    }

    .fi-body .fi-sidebar .fi-sidebar-group-label {
        font-family: var(--pssfp-police-ui);
        font-size: 0.7rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--pssfp-lavande);
    }

    .fi-body .fi-sidebar .fi-sidebar-group-collapse-button,
    .fi-body .fi-sidebar .fi-sidebar-item-icon {
        color: var(--pssfp-lavande);
    }

    .fi-body .fi-sidebar .fi-sidebar-item-label {
        color: var(--pssfp-lavande-claire);
    }

    .fi-body .fi-sidebar .fi-sidebar-item-button:hover,
    .fi-body .fi-sidebar .fi-sidebar-item-button:focus-visible {
        background-color: rgb(255 255 255 / 0.08);
    }

    .fi-body .fi-sidebar .fi-sidebar-item-button:focus-visible {
        outline: 2px solid var(--pssfp-or);
        outline-offset: -2px;
    }

    .fi-body .fi-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-button {
        background-color: rgb(255 255 255 / 0.12);
        box-shadow: inset 3px 0 0 var(--pssfp-or);
    }

    .fi-body .fi-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-label {
        color: #FFFFFF;
        font-weight: 600;
    }

    .fi-body .fi-sidebar .fi-sidebar-item.fi-active .fi-sidebar-item-icon {
        color: var(--pssfp-or);
    }

    /* Titres de page */
    .fi-header-heading,
    .fi-simple-header-heading {
        font-family: var(--pssfp-police-titre);
        font-weight: 600;
        font-size: 2rem;
        line-height: 1.2;
        letter-spacing: 0;
    }

    html:not(.dark) .fi-header-heading,
    html:not(.dark) .fi-simple-header-heading {
        color: var(--pssfp-prune);
    }

    /* Bouton principal : survol prune profonde (et non plus clair) */
    html:not(.dark) .fi-btn.fi-color-primary:not([disabled]):hover {
        background-color: var(--pssfp-prune-profonde);
    }

    /* Mode sombre : Filament éclaircit le survol (blanc sur 400 = 3,5:1) ;
       prune claire, 8,9:1. */
    .dark .fi-btn.fi-color-primary:not([disabled]):hover {
        background-color: #5C3A7E;
    }

    /* Anneaux de focus restés visibles là où la prune se confond avec le
       fond : bouton de repli d'un groupe du menu (or), champs en mode
       sombre (primaire 400, 5:1). */
    .fi-body .fi-sidebar .fi-sidebar-group-collapse-button:focus-visible {
        --tw-ring-color: var(--pssfp-or);
    }

    .dark .fi-input-wrp:focus-within {
        --tw-ring-color: rgb(156 125 184);
    }

    /* Tableaux : en-têtes en micro-libellés, chiffres alignés */
    .fi-ta-header-cell-label {
        font-family: var(--pssfp-police-ui);
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.05em;
        text-transform: uppercase;
    }

    .fi-ta-cell {
        font-variant-numeric: tabular-nums;
    }

    @media (prefers-reduced-motion: reduce) {
        .fi-sidebar,
        .fi-sidebar * {
            transition: none !important;
        }
    }
</style>
