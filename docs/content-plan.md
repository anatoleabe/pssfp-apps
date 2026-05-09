# Plan éditorial — Refonte pssfp.net

> **Référence** : Sprint Specs A4
> **Statut** : v1.0 — proposé pour validation Chef USI et Comité de Pilotage
> **Date** : 2026-05-05
> **Source CDC** : §16 et table 34 du CDC v5

Ce document est l'outil de pilotage opérationnel de la collecte des contenus auprès des unités contributrices (UPASS, UDCFC, UAAF, Centre de Documentation) et du Comité de Pilotage. Il transforme la liste contractuelle de la table 34 du CDC en **tableau de bord hebdomadaire** avec mécanisme d'escalade, et constitue le document de référence des relances par le Chef de Projet.

Sans ce plan, le **risque #1 du projet** identifié au CDC §14 (« Retard dans la fourniture des contenus ») se matérialise et décale mécaniquement le planning Phase 6 (intégration des contenus) puis Phase 8 (mise en production). Toute défaillance d'unité doit être tracée ici et signalée formellement.

## Sommaire

1. Méthodologie et cadence
2. Mécanisme d'escalade
3. Tableau de suivi par unité
4. Contenu minimum garanti pour le lancement
5. Checklist photos institutionnelles

---

## 1. Méthodologie et cadence

**Format livraison** : tous les contenus textuels en `.docx` ou `.md`, photos en JPG ou PNG haute résolution (≥ 1920px côté long, ≥ 300 dpi), logos en SVG (préféré) ou PNG transparent, conventions et documents officiels en PDF. Aucun contenu accepté en image scannée non OCRisée pour les textes éditoriaux.

**Lieu de dépôt** : un dossier partagé `pssfp-contenus/` est ouvert sur le NAS USI ou un Drive interne, structuré par unité. Chaque unité dépose ses livrables dans son sous-dossier nommé. L'arborescence cible :

```
pssfp-contenus/
├── 00-comite-pilotage/      (mot du Président, photos officielles)
├── 01-upass/                (fiches spécialités, programmes, promotions, enseignants)
├── 02-udcfc/                (catalogue FC, certifications, partenariats internationaux)
├── 03-uaaf/                 (frais, modalités paiement, conventions)
├── 04-centre-documentation/ (thèses, mémoires, articles, textes loi)
├── 05-usi/                  (logos, photos institutionnelles, accès techniques)
└── README.md                (statut consolidé, référence ce plan)
```

**Cadence de revue** : revue hebdomadaire le **vendredi 9h** pilotée par le Chef USI, sur 30 minutes maximum, avec présence physique ou Teams des chefs d'unité concernés. Ordre du jour standardisé : statut des livrables de la semaine, blocages identifiés, livrables de la semaine suivante, escalades nécessaires.

**Reporting** : le Chef USI consolide chaque vendredi soir un rapport d'avancement à 1 page transmis au Président du Comité de Pilotage. Format : trois colonnes vert / orange / rouge — vert pour ce qui avance, orange pour ce qui est en retard < 5 jours, rouge pour ce qui est en retard > 5 jours ou non livré sans alternative.

---

## 2. Mécanisme d'escalade

Trois niveaux d'escalade selon l'écart par rapport à la deadline contractuelle :

**Niveau 1 — Relance amicale (J-3)**. Trois jours avant la deadline, le Chef USI envoie un email de rappel au chef d'unité concerné, avec copie à son binôme USI. Format court : objet, livrable attendu, deadline rappelée, format requis. Pas d'urgence dramatisée.

**Niveau 2 — Relance formelle (J-jour)**. Le jour de la deadline si non livré : email formel au chef d'unité, copie en CC à M. ABE ETOUMOU et au Président du Comité de Pilotage. Mention explicite de l'impact sur le calendrier Phase X.

**Niveau 3 — Note au Président (J+5)**. Cinq jours après la deadline si toujours non livré : note formelle écrite par le Chef USI au Président du Comité de Pilotage, demandant arbitrage. Conformément à l'engagement CDC §12.3 : « tout écart significatif (délai > 5 jours, dépassement budget > 10%) doit faire l'objet d'une note formelle au Président du Comité de Pilotage ».

Les escalades sont documentées dans `docs/journal/YYYY-MM-DD-escalade-XX.md` pour traçabilité.

---

## 3. Tableau de suivi par unité

Trois colonnes essentielles : **Livrable** (que doit-on recevoir), **Deadline** (alignée sur le calendrier), **Statut** (vert / orange / rouge — à mettre à jour chaque vendredi). La colonne **Criticité** est gelée et ne change pas.

### 3.1 Comité de Pilotage — Pr. BASAHAG Achile Nestor

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Mot du Président pour /pssfp/mot-president | docx — 300 à 500 mots | 2026-05-15 | HAUTE | À demander |
| Photo officielle Pr. BASAHAG | JPG HD portrait | 2026-05-15 | HAUTE | À demander |
| Validation des wireframes HTML (réunion COPIL) | Réunion + PV | 2026-05-22 | CRITIQUE | À organiser |
| Validation contenus académiques (Comité Scientifique Pr. AVOM) | PV de séance | 2026-06-15 | CRITIQUE | À planifier |

### 3.2 USI — M. ABE ETOUMOU Anatole

Il s'agit de livrables que l'USI fournit à elle-même — c'est l'auto-tracking interne.

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Logo PSSFP haute résolution PNG + SVG | SVG vectorisé + PNG 2000px | 2026-05-12 | CRITIQUE | À vérifier — disponible NAS ? |
| Logos partenaires (MINFI, MINESUP, UY2, Expertise France, FMI, edX, Institut Maroc) | SVG ou PNG transparent HD | 2026-05-22 | HAUTE | À collecter — beaucoup en PNG basse def |
| Photos institutionnelles HD (minimum 50) | JPG ≥ 1920px | 2026-06-01 | HAUTE | À organiser session photo si manquantes |
| Configuration DNS pssfp.net | Accès registrar | 2026-06-15 | CRITIQUE | OK — domaine actif |
| Comptes email @pssfp.net (admin, contact, info, noreply) | Compte SMTP fonctionnel | 2026-05-12 | CRITIQUE | OK — noreply@ + creawebhosting |
| Audit VPS Contabo | Rapport audit-vps.md | 2026-05-15 | HAUTE | À faire (tâche B8) |

### 3.3 UPASS — M. MBIANA Jean Paul

L'unité la plus chargée en livrables critiques.

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Fiche complète Spécialité 1 — Économie Publique et Gestion Publique | docx — 1500 mots + UE détaillées | 2026-05-22 | CRITIQUE | À demander |
| Fiche complète Spécialité 2 — Fiscalité, Finance et Comptabilité Publique | docx | 2026-05-22 | CRITIQUE | À demander |
| Fiche complète Spécialité 3 — Gouvernance Territoriale et Finances Publiques Locales | docx | 2026-05-22 | CRITIQUE | À demander |
| Fiche complète Spécialité 4 — Marchés Publics et Partenariats Public-Privés | docx | 2026-05-22 | CRITIQUE | À demander |
| Fiche complète Spécialité 5 — Audit et Contrôle des Finances Publiques | docx | 2026-05-22 | CRITIQUE | À demander |
| Programme tronc commun 1ère année + UE détaillées | docx | 2026-05-22 | CRITIQUE | À demander |
| Calendrier académique 2026-2027 | xlsx ou docx | 2026-05-29 | HAUTE | À demander |
| Conditions d'admission et critères de sélection | docx | 2026-05-29 | HAUTE | À demander |
| Frais de scolarité validés et modalités | docx | 2026-05-29 | CRITIQUE | À demander |
| Liste nominative des 13 promotions | xlsx — par promotion et spécialité | 2026-06-08 | MOYENNE | À demander |
| Biographies + photos des enseignants permanents et chefs de département | docx + JPG HD | 2026-06-08 | HAUTE | À demander |
| Liste validée des thèses et mémoires existants par promotion | xlsx avec métadonnées | 2026-06-08 | HAUTE | À demander — coordination avec Centre Doc |

### 3.4 UDCFC — Dr. MBALLA ZAMBO Edouard Georges

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Catalogue formation continue 2026 — 10 modules détaillés | docx — un module = 1 page (cibles, objectifs, programme, durée, tarifs) | 2026-05-22 | CRITIQUE | À demander |
| Fiches certifications internationales | docx | 2026-05-29 | HAUTE | À demander |
| Programme MEDIAFIP — description et bénéficiaires | docx | 2026-05-29 | MOYENNE | À demander |
| Voyages d'étude Maroc / Expertise France — descriptions | docx + photos si disponibles | 2026-05-29 | MOYENNE | À demander |
| Fiches partenariats internationaux (Institut Maroc, FMI, edX, Assemblée Nationale, réseau CEMAC) | docx | 2026-06-01 | MOYENNE | À demander |

### 3.5 UAAF — M. MBA Pierre

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Convention ISFP 2024 signée — version officielle | PDF scanné HD | 2026-05-22 | HAUTE | À demander |
| Convention tripartite 2013 | PDF scanné HD | 2026-05-22 | MOYENNE | À demander |
| Mentions légales — statut juridique, accréditations, responsable de publication | docx | 2026-05-22 | HAUTE | À rédiger conjointement USI + UAAF |
| Politique de confidentialité et gestion des cookies | docx | 2026-05-29 | HAUTE | À rédiger conjointement |
| Modalités de paiement frais candidature (procédure CREMINCAM) | docx — étapes pour candidat | 2026-05-29 | HAUTE | À cadrer avec CREMINCAM |
| Validation tarifs formation continue (table 4995/500/250 kFCFA) | Note de service | 2026-05-22 | HAUTE | À demander |

### 3.6 Centre de Documentation — M. BENOH BENOH Pierre Tanguy

| Livrable | Format | Deadline | Criticité | Statut |
|---|---|---|---|---|
| Accès admin à l'ancienne bibliothèque bibliotheque.pssfp.net | Identifiants + dump SQL | 2026-05-15 | CRITIQUE | À demander |
| Inventaire exhaustif documents existants (audit Phase 1) | xlsx — type, titre, auteur, promo, qualité | 2026-05-29 | HAUTE | Réalisé par Centre Doc + USI |
| Fonds des thèses et mémoires complet en PDF | PDF — un fichier par document | 2026-06-15 | HAUTE | Migration progressive |
| Articles scientifiques des enseignants | PDF | 2026-06-15 | MOYENNE | À collecter auprès des enseignants |
| Textes législatifs de référence finances publiques | PDF — versions officielles | 2026-06-15 | MOYENNE | À constituer |
| Politique d'accès validée par COPIL (public / restreint / payant) | PV de validation | 2026-05-29 | HAUTE | Décision COPIL requise |
| Désignation du ou des bibliothécaires opérateurs CMS | Note nominative | 2026-06-01 | HAUTE | À demander Centre Doc |

---

## 4. Contenu minimum garanti pour le lancement

Si le calendrier glisse et que tous les contenus ne sont pas prêts à temps, le site **doit** au moins contenir ce qui suit pour pouvoir être mis en ligne (cf. CDC §15.4) :

1. Page d'accueil complète avec hero, chiffres clés et cards spécialités.
2. Section LE PSSFP avec présentation, mot du Président, gouvernance, conventions, conformité CAMES, partenaires.
3. Les 5 fiches spécialités avec **au minimum** description + débouchés (UE détaillées peuvent attendre Phase 6 polish).
4. Page contact fonctionnelle avec formulaire opérationnel.
5. Page conformité CAMES complète avec 12 exigences documentées.
6. Mentions légales et politique de confidentialité.
7. Au moins 3 articles d'actualités publiés.
8. Bibliothèque virtuelle avec au moins 20 thèses/mémoires migrés et indexés.
9. Module candidature opérationnel avec campagne 2026 ouverte.

Tout le reste (catalogue FC complet, biographies enseignants détaillées, galerie 13 promotions, etc.) peut être ajouté en mode incrémental après mise en ligne.

---

## 5. Checklist photos institutionnelles

Cible CDC §10.3 : **minimum 50 photos** institutionnelles haute qualité. Si le fonds existant ne suffit pas, organiser une session photo dédiée avec un photographe professionnel (budget ~300-500 kFCFA).

| Lieu / Sujet | Quantité cible | Statut |
|---|---|---|
| Façade Campus de Messa | 3-5 | À vérifier |
| Trois amphithéâtres | 6 (2 par amphi) | À vérifier |
| Cinq salles de classe | 10 (2 par salle) | À vérifier |
| Bibliothèque physique | 3-5 | À vérifier |
| Cours en présentiel (étudiants en activité) | 8-10 | À organiser |
| Soutenances et examens | 4-6 | À programmer |
| Séminaires de formation continue | 4-6 | À programmer prochaine session FC |
| Voyages d'étude (Maroc, Expertise France) — archives | 3-5 | À solliciter UDCFC |
| Photos officielles équipe de direction | 8-10 (portrait + en situation) | À programmer |
| Cérémonies (remise de diplômes promotions passées) | 4-6 | À solliciter archives |

**Total cible** : 53-67 photos. Toutes en JPG ≥ 1920px côté long, sans flou de mouvement, contraste correct, cadrage sérieux et institutionnel. Aucune photo de stock ni image générique de banque d'images.

---

## Annexe — Modèle d'email de relance niveau 1

Texte à copier-coller, à personnaliser entre crochets.

> **Objet** : [Livrable XYZ] — Rappel deadline [JJ/MM/2026]
>
> Bonjour [Prénom],
>
> Petit rappel amical : la deadline pour le livrable « [Nom du livrable] » est fixée au [JJ/MM/2026]. Ce contenu est nécessaire pour la phase [X] du projet de refonte du site web PSSFP.
>
> Format attendu : [docx/PDF/JPG, taille minimum, etc.]
> Lieu de dépôt : `pssfp-contenus/[03-uaaf]/`
>
> Si tu rencontres une difficulté ou si tu as besoin que je t'aide à structurer le contenu, n'hésite pas à m'appeler.
>
> Bien cordialement,
> Anatole
> Chef USI — Chef de Projet
