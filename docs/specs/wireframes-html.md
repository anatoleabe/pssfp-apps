# Spec — Maquettes HTML cliquables (substitut Figma)

> **Référence** : Sprint Specs A11
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Décision source** : Anatole, 5 mai 2026 — pas de Figma, on génère des maquettes HTML/Tailwind cliquables avec Claude Code et on les présente au COPIL

Cette spec décrit la production d'un **mini-site statique navigable** servant à valider l'UX et le design avec le Comité de Pilotage avant le développement réel des modules. C'est notre alternative low-cost à un prototype Figma.

## 1. Pourquoi cette approche

Trois bénéfices vs Figma :

1. **Pas de coût ni d'apprentissage outil** — directement en HTML+Tailwind, généré par Claude Code, hébergé sur Vercel preview ou GitHub Pages gratuitement.
2. **Réutilisable** — les composants HTML produits sont partiellement transférables au vrai code Next.js avec quelques adaptations (Server Components, props), donc le travail n'est pas jeté.
3. **Authentique** — le COPIL voit ce qu'il aura réellement à l'écran (rendu navigateur), pas une simulation Figma qui peut différer du résultat final.

## 2. Pages à mocker

Six pages clés couvrant les parcours utilisateurs principaux. Pas besoin de tout mocker — on mocke ce qui pose des choix structurants à valider.

| # | Page | URL maquette | Justification |
|---|---|---|---|
| 1 | Accueil pssfp.net | `/` | Hero, chiffres clés, cards spécialités, partenaires, accès rapides — point d'entrée critique. |
| 2 | Fiche spécialité (exemple Fiscalité) | `/formations/specialites/fiscalite` | Représentative des 5 fiches spécialités. Valide le format UE + débouchés + enseignants. |
| 3 | Liste actualités | `/actualites` | Layout liste articles avec filtres catégories — modèle pour beaucoup d'autres listes. |
| 4 | Bibliothèque accueil | `/bibliotheque/` (sur sous-domaine) | Recherche proéminente, derniers ajouts, accès rapides — différenciation visuelle vs site principal à valider. |
| 5 | Formulaire candidature étape 2 | `/candidature/profil` | UX du parcours candidat — étape de saisie du profil avec validation inline. |
| 6 | Espace personnel auditeur | `/bibliotheque/mon-espace` | Tableau de bord auditeur connecté — favoris, historique téléchargements, notifications. |

## 3. Structure du livrable

Un dossier `wireframes/` à la racine du repo `/pssfp/`, indépendant du code applicatif (pas dans `frontend/` ou `library/`). Il contient :

```
wireframes/
├── index.html              # Page d'accueil mini-site, mène aux 6 maquettes
├── 01-accueil.html
├── 02-fiche-specialite.html
├── 03-actualites.html
├── 04-bibliotheque-accueil.html
├── 05-candidature-profil.html
├── 06-mon-espace.html
├── shared/
│   ├── header.html         # Header partagé inclus via Server-Side Includes ou JavaScript
│   ├── footer.html
│   └── styles.css          # Tokens design CDC §10.1 (palette + typographies)
├── assets/                 # Images placeholders, logos
└── README.md               # Instructions de visualisation et de feedback
```

Le mini-site fonctionne en ouvrant `index.html` dans un navigateur — pas besoin de serveur. Pour un déploiement public (lien à envoyer au COPIL), pousser sur Vercel ou GitHub Pages (~5 min).

## 4. Contraintes techniques

**HTML5 statique pur**. Pas de React, pas de framework — juste HTML + Tailwind CSS via CDN. Le but est qu'un navigateur charge le fichier et ça marche. La complexité n'est pas le but.

**Tailwind via CDN** — `<script src="https://cdn.tailwindcss.com"></script>`. En production réelle on utilisera la version compilée, mais pour les wireframes le CDN est suffisant.

**Composants shadcn/ui adaptés**. Les wireframes anticipent l'usage de shadcn/ui en production — donc ils utilisent les patterns shadcn (cards, buttons, alerts) avec les classes Tailwind correspondantes. Permet une transition fluide vers Next.js.

**Charte graphique CDC §10.1 strictement appliquée** :

- Violet institutionnel `#6B2FA0`
- Violet moyen `#9B59B6`
- Lavande `#EDE7F6`
- Or `#C9A227`
- Texte `#333333` sur fond `#FFFFFF`
- Polices : Playfair Display (titres), Inter (corps), DM Sans (UI) — chargées via Google Fonts CDN.

**Mobile-first**. Toutes les maquettes doivent être responsive et présentables en 375px (iPhone SE) jusqu'à 1920px. La maquette doit déclencher la conversation « comment ça se comporte sur mobile ? » dès l'ouverture.

**Aucune logique fonctionnelle**. Les liens entre maquettes naviguent vers d'autres fichiers HTML, mais aucune fonction n'est connectée à un backend. Les formulaires ne soumettent rien. Les recherches ne renvoient pas de vrais résultats — juste des résultats statiques de démo.

## 5. Processus de validation

**Production** : génération via Claude Code en Sprint B, après le bootstrap technique. Estimé à ~1 jour de génération + 0,5 jour de polish manuel par le Chef USI.

**Présentation au COPIL** : réunion dédiée d'1h-1h30, présidée par Pr. BASAHAG, animée par M. ABE ETOUMOU. Démonstration sur écran partagé en parcourant les 6 pages, suivie d'un tour de table de retours.

**Itération** : maximum **2 tours** de modifications. Tour 1 = retours majeurs (structure, navigation, ce qui manque). Tour 2 = retours fins (couleurs, formulations, ajustements). Au-delà = blocage du calendrier — on développe avec le compromis du tour 2.

**PV de validation** : compte-rendu écrit signé Pr. BASAHAG actant la validation visuelle des 6 maquettes. Conservé dans `docs/journal/2026-05-XX-validation-wireframes.md`.

## 6. Critères de qualité d'une bonne maquette

Pour qu'une maquette mérite d'être présentée au COPIL :

1. **Représente fidèlement les contenus réels** — les chiffres clés sont les vrais (13 promotions, ~1200 diplômés, 5 spécialités, 10 ans). Les noms de spécialités sont les vrais. Les textes sont rédigés (pas du « Lorem ipsum »).
2. **Adresse les choix structurants** — chaque page existe pour valider une décision (par exemple, doit-on afficher les chiffres clés en hero ou en section dédiée ? Faut-il afficher 1, 2 ou 3 colonnes pour les cards spécialités ?). Le COPIL doit pouvoir répondre.
3. **Inclut les états importants** — un formulaire montre un état vide ET un état avec validation d'erreur. Une liste montre un état avec contenu ET un état vide. Permet d'éviter les surprises en développement.
4. **Mobile et desktop côte à côte** — un screenshot des deux rendus dans un encadré commentaire de la maquette, ou une bascule navigateur DevTools démonstration en réunion.
5. **Pas de surconception** — les maquettes ne contiennent que ce qui sert à valider. Pas d'animations sophistiquées, pas de vidéos d'arrière-plan, pas d'illustrations sur-mesure. C'est un outil de validation, pas un portfolio.

## 7. Inputs nécessaires avant génération

Pour que Claude Code produise les maquettes, il faut que ces inputs soient prêts (relevant Sprint A) :

- `data-model.md` — pour connaître les structures à afficher.
- `api-contract.md` — pour connaître les champs disponibles.
- Spec module 1 (`module-1-site-institutionnel.md`) — pour la structure des pages.
- Spec module 3 (`module-3-bibliotheque.md`) — pour les pages biblio.
- Spec module 5 (`module-5-candidatures.md`) — pour les pages candidature.
- Logo PSSFP en SVG.
- 5-10 photos institutionnelles HD (peuvent être placeholders ou photos réelles si déjà collectées).
- Contenu rédigé pour la fiche spécialité Fiscalité (au moins, pour la maquette n°2).

Si ces inputs ne sont pas prêts, on génère avec des contenus placeholders réalistes (style Wikipédia) en notant clairement les zones à remplacer.

## 8. Sortie attendue de la session de génération

Un commit Git dans le repo `pssfp/` :

```
git log
> wireframes: 6 maquettes HTML cliquables pour validation COPIL
> URL preview Vercel : https://pssfp-wireframes-XXXX.vercel.app/
```

Un email récapitulatif au Président du Comité de Pilotage avec le lien de preview, une description de chaque maquette en 2 phrases, et une proposition de date de réunion de validation.

---

## Annexe — Prompt initial pour Claude Code

À copier-coller dans Claude Code lors du Sprint B après bootstrap, pour générer les wireframes.

```
Tâche : générer 6 maquettes HTML cliquables pour validation UX du PSSFP.

Spec : docs/specs/wireframes-html.md
Contraintes :
- HTML5 statique pur, pas de framework
- Tailwind via CDN
- Charte CDC §10.1 strictement (palette, polices)
- Mobile-first, responsive 375px → 1920px
- Aucune logique back, juste navigation entre fichiers
- Contenus PSSFP réalistes (pas de Lorem ipsum)

Pages à produire (cf. spec §2) :
1. Accueil pssfp.net
2. Fiche spécialité Fiscalité
3. Liste actualités
4. Bibliothèque accueil
5. Formulaire candidature étape 2 (profil)
6. Espace personnel auditeur

Plan attendu avant code :
- Liste de tous les fichiers à créer
- Composants partagés (header/footer/styles)
- Plan par maquette (sections présentes + dimensions)
- Stratégie de placeholders images

Aucun fichier produit avant que je valide le plan écrit.
```
