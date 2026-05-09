# Checklist des assets à fournir

> **Référence** : actions humaines préalables à Sprint S2 (Module 5) et S3 (Module 1).
> **Date** : 2026-05-07
> **À déposer par** : M. ABE ETOUMOU Anatole (USI) avec contributions UPASS/UDCFC/UAAF/COPIL

Ce document liste **précisément** les fichiers à fournir pour que les modules développés aient un rendu institutionnel correct dès la première itération. Sans ces assets, on travaille avec des placeholders (effet de chantier visible).

## 1. Où déposer les assets

### Option A — Dossier local sur ton Mac (recommandée pour démarrer)

Crée un dossier `assets-source/` à la racine du projet :

```
/Users/anatole-savics/Documents/PS/03_PROJETS/SITE WEB PSSFP/pssfp/assets-source/
├── logos/
│   ├── pssfp.svg
│   ├── pssfp.png
│   ├── partenaires/
│   │   ├── minfi.svg
│   │   ├── minesup.svg
│   │   ├── uy2.svg
│   │   ├── expertise-france.svg
│   │   └── ...
│   └── institutions-coop/
│       ├── fmi.svg
│       ├── edx.svg
│       ├── institut-finances-maroc.png
│       └── assemblee-nationale-cm.png
├── photos/
│   ├── direction/
│   │   ├── pr-basahag-portrait.jpg
│   │   └── ...
│   ├── campus/
│   │   ├── facade-messa-1.jpg
│   │   └── ...
│   ├── amphis/
│   ├── salles/
│   ├── bibliotheque/
│   ├── cours/
│   ├── soutenances/
│   ├── seminaires/
│   ├── voyages/
│   └── promotions/
│       ├── promo-13-groupe.jpg
│       └── ...
├── conventions/
│   ├── convention-isfp-2024-signee.pdf
│   ├── convention-tripartite-2013.pdf
│   └── ...
├── textes/
│   ├── mot-president-pr-basahag.docx
│   ├── presentation-pssfp-historique.docx
│   └── fiches-specialites/
│       ├── 1-economie-publique-gestion-publique.docx
│       ├── 2-fiscalite-finance-comptabilite.docx
│       ├── 3-gouvernance-territoriale-fp-locales.docx
│       ├── 4-marches-publics-ppp.docx
│       └── 5-audit-controle-fp.docx
└── divers/
    ├── catalogue-formation-continue-2026.docx
    └── frais-scolarite-detail.docx
```

**Important** : ce dossier sera **gitignored** (trop lourd pour Git, contient potentiellement des données sensibles). Claude Code lit depuis là, traite, optimise (compression WebP, redimensionnement) et copie la version optimisée vers `frontend/public/`, `library/public/`, ou MinIO selon l'usage.

### Option B — Cloud partagé (si tu préfères)

Tu peux utiliser le NAS USI ou un Drive Google partagé. Communique-moi l'arborescence exacte et les credentials d'accès quand prêt — Claude Code a besoin de pouvoir télécharger les fichiers en local pour les intégrer.

## 2. Priorité 1 — Assets minimum pour démarrer (à fournir avant Module 1 dev)

Sans ces 5 catégories, le site institutionnel public ne peut pas démarrer.

### 2.1 Logo PSSFP (CRITIQUE)

- [ ] `logos/pssfp.svg` — version vectorielle, préférée
- [ ] `logos/pssfp.png` — fallback PNG transparent ≥ 500×500 px, fond transparent

**Note** : on a déjà `pssfp/docs/migration-candidature/logo.png` récupéré du système P13 — peut servir de fallback en attendant. Mais une version SVG officielle est préférable pour le scaling sur tous écrans.

### 2.2 Logos partenaires institutionnels et techniques

Format : **SVG** (préféré) ou **PNG transparent** ≥ 500×500 px.

Critique pour la page d'accueil et `/pssfp/partenaires` :

- [ ] `logos/partenaires/minfi.svg` — Ministère des Finances Cameroun
- [ ] `logos/partenaires/minesup.svg` — Ministère Enseignement Supérieur
- [ ] `logos/partenaires/uy2.svg` — Université de Yaoundé II-Soa
- [ ] `logos/partenaires/expertise-france.svg`
- [ ] `logos/partenaires/fmi.svg` — Fonds Monétaire International
- [ ] `logos/partenaires/edx.svg`
- [ ] `logos/partenaires/institut-finances-maroc.svg` (Basil Fuleihan)
- [ ] `logos/partenaires/assemblee-nationale-cm.svg`
- [ ] `logos/partenaires/afd.svg` (Agence Française de Développement, vu sur l'ancien site biblio)

**Astuce** : la plupart de ces logos sont disponibles publiquement sur les sites web officiels de ces institutions, en bas de page ou dans leur kit presse. Si SVG indisponible, capturer en PNG haute résolution.

### 2.3 Mot du Président + photo officielle (HAUTE)

- [ ] `textes/mot-president-pr-basahag.docx` — texte 300-500 mots du Pr. BASAHAG, à demander au Comité de Pilotage
- [ ] `photos/direction/pr-basahag-portrait.jpg` — portrait officiel HD ≥ 800×1000 px, fond neutre, format institutionnel

Délai cible : 2026-05-15 (cf. content-plan A4).

### 2.4 Photos institutionnelles minimum (10-15 pour démarrer)

Pour avoir un visuel propre dès Module 1. Cible CDC = 50+ photos, mais 10-15 suffisent pour le premier rendu :

- [ ] 2-3 photos façade Campus Messa (extérieur, identifiable)
- [ ] 2 photos amphithéâtre (vide ou avec étudiants)
- [ ] 2 photos salle de classe
- [ ] 1 photo bibliothèque physique
- [ ] 2-3 photos cours/séminaire en présentiel (avec personnes, ambiance académique)
- [ ] 1-2 photos soutenance ou cérémonie

Format : **JPG** ≥ 1920 px côté long, ≥ 300 dpi, sans flou, ratio variable acceptable (carré, paysage, portrait). Pas de filtre Instagram, pas de watermark tiers.

### 2.5 Convention ISFP 2024 (HAUTE)

- [ ] `conventions/convention-isfp-2024-signee.pdf` — version officielle scannée, signée par toutes les parties

C'est l'une des 12 exigences CAMES (table 37 du CDC). Affiché en téléchargement public sur `/pssfp/conventions`.

Optionnellement aussi :

- [ ] `conventions/convention-tripartite-2013.pdf`
- [ ] Lois et décrets de référence en finances publiques (peuvent venir plus tard via la biblio)

## 3. Priorité 2 — Pour les fiches spécialités (avant Module 1 page formations)

Action UPASS — chefs des départements de spécialité.

5 fiches spécialités à rédiger en docx (cf. plan éditorial A4 §3.3) :

- [ ] `textes/fiches-specialites/1-economie-publique-gestion-publique.docx`
- [ ] `textes/fiches-specialites/2-fiscalite-finance-comptabilite.docx`
- [ ] `textes/fiches-specialites/3-gouvernance-territoriale-fp-locales.docx`
- [ ] `textes/fiches-specialites/4-marches-publics-ppp.docx`
- [ ] `textes/fiches-specialites/5-audit-controle-fp.docx`

Structure attendue par fiche (~1500 mots) :

- Présentation courte (50 mots, pour cards)
- Présentation longue (300-500 mots)
- Objectifs et compétences visées (liste)
- Liste des UE détaillées (semestre 3 et semestre 4) avec : code, intitulé, volume horaire, crédits ECTS
- Débouchés professionnels (liste de métiers)
- Profil enseignant principal de la spécialité

Délai cible : 2026-05-22.

## 4. Priorité 3 — Pour la page d'accueil et galeries (peuvent venir plus tard)

### 4.1 Hero accueil — image principale

- [ ] `photos/hero-accueil.jpg` — image impactante 1920×1080 px minimum, qui représente l'esprit institutionnel : campus, étudiants en cours, ou groupe de promotion. Ratio paysage strict.

Si pas dispo, on prend une des photos campus/amphi de la priorité 1 et on la recadre.

### 4.2 13 photos de promotions (galerie historique)

Action UPASS + USI archives.

- [ ] `photos/promotions/promo-1-groupe.jpg` à `promo-13-groupe.jpg`

Format : photos de groupe officielles, JPG ≥ 1920 px. Si certaines manquent (les anciennes promotions), inscrire un placeholder « Photo non disponible » dans la fiche.

### 4.3 Biographies + photos enseignants

Action UPASS + chefs de département.

- [ ] `textes/enseignants/{prenom-nom}.docx` — bio courte (200-400 mots) par enseignant titulaire et chef de département
- [ ] `photos/enseignants/{prenom-nom}.jpg` — portrait HD ≥ 600×800 px, fond neutre

Estimation : 15-25 enseignants principaux à couvrir en V1. Le reste peut s'ajouter au fil de l'eau.

## 5. Priorité 4 — Pour l'admin et la communication

### 5.1 Catalogue Formation Continue 2026

Action UDCFC (Dr. MBALLA ZAMBO).

- [ ] `divers/catalogue-formation-continue-2026.docx` — 10 modules détaillés (cf. plan éditorial A4 §3.4)

### 5.2 Frais et modalités

Action UAAF (M. MBA Pierre).

- [ ] `divers/frais-scolarite-2026.docx`
- [ ] `divers/modalites-paiement-cca.docx` — procédure pour les candidats (étape par étape avec capture du formulaire CREMINCAM)

### 5.3 Photos officielles équipe direction

Pour `/pssfp/gouvernance` :

- [ ] `photos/direction/pr-basahag-portrait.jpg` — déjà en priorité 1
- [ ] `photos/direction/pr-avom-portrait.jpg` — Président Comité Scientifique
- [ ] `photos/direction/m-abe-etoumou-portrait.jpg` — Chef USI (toi !)
- [ ] `photos/direction/m-mbiana-portrait.jpg` — Chef UPASS
- [ ] `photos/direction/dr-mballa-zambo-portrait.jpg` — Chef UDCFC
- [ ] `photos/direction/m-mba-portrait.jpg` — Chef UAAF
- [ ] `photos/direction/m-benoh-benoh-portrait.jpg` — Centre Documentation

Format : portrait HD ≥ 800×1000 px, fond neutre.

## 6. Récapitulatif délais

| Priorité | Catégorie | Délai cible | Bloque quoi |
|---|---|---|---|
| 1 | Logos PSSFP + 9 partenaires SVG | 2026-05-15 | Module 1 page d'accueil |
| 1 | Photo + mot Pr. BASAHAG | 2026-05-15 | `/pssfp/mot-president` |
| 1 | 10-15 photos institutionnelles | 2026-05-15 | Module 1 hero + galeries |
| 1 | Convention ISFP 2024 PDF | 2026-05-15 | `/pssfp/conventions` (CAMES) |
| 2 | 5 fiches spécialités docx | 2026-05-22 | `/formations/specialites/[slug]` |
| 3 | Hero accueil image | 2026-05-22 | Page d'accueil |
| 3 | 13 photos promotions | 2026-06-08 | `/vie-academique/promotions` |
| 3 | Bios + photos 15-25 enseignants | 2026-06-08 | `/vie-academique/corps-enseignant` |
| 4 | Catalogue FC 2026 | 2026-05-22 | `/formations/continue` |
| 4 | Frais + modalités CREMINCAM | 2026-05-22 | Module 5 candidature + `/formations/frais-de-scolarite` |
| 4 | 7 photos direction | 2026-05-29 | `/pssfp/gouvernance` |

## 7. Note sur les images du système actuel

On a déjà récupéré dans `pssfp/docs/migration-candidature/` :

- `logo.png` — logo PSSFP (180 KB) — utilisable comme fallback
- `entete.png` — bandeau institutionnel (110 KB) — utilisé dans le PDF récépissé candidat

Le système P13 fournit aussi des images `bg1.jpg`, `bg2.jpg`, `bg3.jpg` (~17 MB total dans le zip) qui peuvent servir d'arrière-plan candidature. À compresser en WebP avant intégration (cible ≤ 500 KB chacune).

## 8. Que faire si certains assets manquent au démarrage Module 1

Stratégie de dégradation gracieuse pour ne pas bloquer le développement :

- **Logos partenaires manquants** : placeholder gris avec nom de l'institution écrit + lien vers leur site. Visuellement signalé comme « logo à fournir ».
- **Photos institutionnelles manquantes** : utiliser des illustrations stylisées (icônes Lucide grand format) ou des couleurs unies de la charte CDC. Aucune image stock générique (banque d'images) — c'est interdit pour une institution publique.
- **Mot du Président manquant** : page `/pssfp/mot-president` mise temporairement hors menu, ré-activée une fois le texte fourni.
- **Photos enseignants manquantes** : avatar par défaut avec initiales sur fond couleur de la spécialité.

Cette approche permet de démarrer Module 1 même avec un asset coverage de 30-40 %, et d'enrichir au fil de l'arrivée des contenus.

## 9. Action immédiate pour toi

1. **Crée le dossier** `assets-source/` à la racine du projet (gitignored automatiquement vu le `.gitignore` existant qui ignore tout ce qui n'est pas explicitement listé).
2. **Demande aux unités** (UPASS, UDCFC, UAAF, COPIL) les assets de priorité 1 — utilise le plan éditorial `docs/content-plan.md` comme support de réunion.
3. **Récupère les logos partenaires** depuis les sites publics — c'est probablement la tâche la plus rapide à faire toi-même (1-2h).
4. **Photo session** : si les photos institutionnelles HD manquent, organiser une session photo professionnelle (~300-500 kFCFA) la semaine prochaine — c'est l'investissement le plus rentable pour le site.
5. **Demande au Pr. BASAHAG** : photo officielle + texte du mot du Président, idéalement avant 2026-05-15.

À mesure que les assets arrivent dans `assets-source/`, dis-moi et je donne instructions à Claude Code pour les intégrer.
