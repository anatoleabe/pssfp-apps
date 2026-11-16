# Module 4 — Lot 15 : reprise d'ACMA

**Date** : 2026-11-15
**Statut** : proposé (spec seule, avant réalisation) ; cas à arbitrer au §12
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026, §2)
**Décision d'architecture** : [ADR-0014](../adr/0014-reprise-acma-archive-lecture-seule.md)
**Suite** : [réinscription des anciens](module-4-lot15-reinscription-anciens.md) (16/11/2026 : promotions antérieures, réinscription d'un ancien depuis l'archive, coordonnées reprises ; modifie la décision 4, Q7 et Q16)
**Specs liées** : [feuille de route LMD](module-4-lmd-feuille-de-route.md) (lots 6 et 15) · [compte de l'auditeur, D1](module-4-scolarite-comptes-d1.md) et [ADR-0013](../adr/0013-compte-de-l-auditeur.md) · [demandes d'autorisation, 14c](module-4-lmd-lot14c-demandes-autorisation.md) · [documents vérifiables, lot 7](module-4-lmd-lot7-resultats-documents.md) · [import des auditeurs antérieurs](module-4-import-auditeurs-anterieurs.md)
**Sources** : export complet d'ACMA du 15/11/2026 (base `gestionacademic`, chargée dans un conteneur jetable, lu en lecture seule) ; analyse du schéma faite en octobre (dossier « PSSFP Système », annexe 5) ; scripts de reprise du dossier « PSSFP Système » (`20_copie_acma.py` à `30_controles_reprise.sql`), dont on reprend la connaissance des pièges d'ACMA, pas le schéma cible.

Ce document ne contient que des comptes, des codes de référentiel (années, niveaux, UE) et des exemples fictifs. Aucun nom, matricule, téléphone ni date de naissance réel.

## 1. Objectif

Reprendre dans la plateforme tout ce qu'ACMA sait des auditeurs depuis 2013-2014, puis retirer ACMA :

- **une archive consultable** de chaque ancien auditeur : identité, inscriptions, résultats et notes par année, paiements, soutenances, documents. L'administration seule la consulte et en délivre relevés et attestations ;
- **les arriérés** des années passées, pour les auditeurs encore en formation, entrés au compte un par un après validation ;
- **un rapport des écarts 2026-2027** entre ACMA et la plateforme, remis à la scolarité, et l'arrêt de la saisie dans ACMA ;
- **les acquis des redoublants** encore en formation, reconnus par des correspondances d'UE validées (pont attendu par le lot 6).

Ce qui change, pour qui :

| Qui | Changement |
|---|---|
| Scolarité (agents, responsable) | Nouvel écran « Archives ACMA » (fiche d'ancien auditeur, relevés et attestations historiques), onglet « Historique ACMA » sur la fiche des auditeurs P13, écran « Arriérés ACMA » (vérifier, demander, approuver), rapport des écarts 2026-2027. ACMA ne sert plus. |
| Service financier | Les arriérés validés apparaissent au compte des auditeurs concernés (relevé de compte du sous-lot D2). |
| Auditeurs en formation | Rien de visible dans le lot 15, sauf un arriéré porté au compte après validation (§8). |
| Anciens auditeurs | Rien : aucun compte, aucun SMS, aucun e-mail, aucun compte eCampus (décision 4). Exception depuis le 16/11 : l'ancien réinscrit dans l'année en cours depuis l'archive ([réinscription des anciens](module-4-lot15-reinscription-anciens.md)). |
| USI | Commandes de transit, de reprise, de contrôle et de gel ; répétition sur une copie locale de la production ; retrait d'ACMA. |

## 2. Décisions du chef de projet (15/11/2026)

Appliquées telles quelles :

1. **Notes : archive consultable.** Par année : moyennes, crédits, rattrapages, décision (admis ou non). Elle est visible sur la fiche de l'auditeur et dans un relevé historique. On ne refait pas les délibérations passées, on ne recrée pas de PV dans le module LMD.
2. **2026-2027 : la plateforme fait foi.** Les versements 2026-2027 d'ACMA ne sont pas importés. Une liste d'écarts est remise à la scolarité, et la saisie dans ACMA s'arrête.
3. **Arriérés des années passées** (surtout le M1 2025-2026 des P13) : une liste calculée depuis ACMA est validée **ligne par ligne** par la scolarité. Seuls les arriérés validés entrent dans le compte de l'auditeur (décision Q10 du lot D : « au cas par cas, jamais en masse »).
4. **Anciens auditeurs** (avant la P13) : pas d'accès à l'espace auditeur pour l'instant. Aucun SMS, e-mail ni compte eCampus lors de la reprise. Leur dossier n'est visible que par l'administration, qui délivre relevés et attestations.

   **Modifiée le 16/11/2026** pour les seuls anciens **réinscrits** dans l'année en cours (M1 ou M2, toute promotion, P14 comprise) : ils sont créés depuis leur dossier d'archive, matricule ACMA conservé, et reçoivent leurs accès par le chemin normal (paiement confirmé ou déblocage). Les autres anciens restent sans compte, sans espace et sans envoi. Voir [réinscription des anciens](module-4-lot15-reinscription-anciens.md), §2 et §9.

## 3. Inventaire de l'export du 15/11/2026

Comptes exacts, établis sur la copie de transit. La commande `acma:inventaire` (§10.2) les reproduit pour tout export.

### 3.1 Tables utiles

| Table ACMA | Lignes | Contenu |
|---|---:|---|
| `etudiant` | 2 778 | Fiche de l'auditeur (identité, matricule, catégorie) |
| `inscription_academic` | 10 038 | Inscription par année et niveau, avec la décision enregistrée (`admis`, `moydunivo`, `Totalcredit`) |
| `notes` | 49 987 | Note d'un auditeur pour une ligne de programme (CC, TP, examen, rattrapage) |
| `notes_hist` | 309 580 | Versions successives des notes (déclencheur ACMA), de 2021 à 2026 |
| `programe` | 706 | Programme d'une année et d'un niveau : UE, groupe, semestre, crédits, coefficient, pondérations, volumes |
| `ue` / `grpue` | 130 / 14 | Catalogue des UE (96 intitulés bilingues « français (anglais) ») et groupes d'UE (semestres) |
| `niveau` / `filiere` | 6 / 2 | Niveaux (`FPTC4`, `AUCO5`, `EPGP5`, `FFCP5`, `GTFP5`, `MPPP5`) ; filières `MFP` et `POURTEST` |
| `anneeacademic` | 14 | 2013-2014 à 2026-2027 |
| `categorie` | 7 | `TC`, `AC`, `EPGP`, `FFCP`, `GTFP`, `MPPP`, `FP` |
| `configurationdu` | 546 | Barèmes : 13 années × 7 catégories × 6 niveaux |
| `du` | 7 453 | Paiements (une ligne par opération, ventilée en colonnes) |
| `phasepayement` | 6 | Phases de paiement |
| `pvsoutenance` | 801 | PV de soutenance du mémoire |
| `certificat` | 197 | Certificats délivrés |
| `prisecours` / `nonperceptionb` / `misedispos` / `attestationr` | 102 / 31 / 5 / 2 | Lettres de stage, attestations de non-perception de bourse, mises à disposition, attestations de réussite |

Hors périmètre : `utilisateur` (17 comptes, dont 4 mots de passe en clair), `tachesutili` (journal d'ACMA, 241 196 lignes), les tables `gpw*` (groupware WinDev), `stat_*`, `etudiant_hist`, `ue_hist`, `grpue_hist`, `encaissement` (6 lignes), les tables vides et les tables parasites (`azer`, `azerty`, `test`, `asupbis`). Ils restent dans l'export chiffré (Q14).

### 3.2 Années

| Années | Statut dans ACMA | Contenu |
|---|---|---|
| 2013-2014 à 2015-2016 | CLÔTURÉE | Inscriptions et paiements. Aucune note, aucune décision |
| 2016-2017 | CLÔTURÉE | Aucune inscription |
| 2017-2018 à 2025-2026 | EN COURS (à tort) | Inscriptions, notes, décisions, paiements |
| 2026-2027 | EN COURS | Ouverte en parallèle de la plateforme (§3.8) |

Correction du constat de départ : 10 années sont marquées « EN COURS », mais 9 le sont à tort, puisque 2026-2027 est bien l'année en cours. Les notes commencent en 2017-2018 pour le M1 et en 2018-2019 pour le M2.

### 3.3 Inscriptions

- **10 038 lignes**, dont :
  - **5 104 sans année ni niveau**. Elles ont été créées par les déclencheurs d'ACMA au premier paiement ou à la première note. Elles concernent 329 auditeurs, presque tous des préfixes 17 et 18. Elles ne sont pas reprises ;
  - **4 934 datées**, soit 4 932 distinctes : 2 lignes sont en double (même auditeur, même année, même niveau). L'archive en garde 4 675 jusqu'en 2025-2026, soit 4 673 distinctes, dont 2 184 admis. 2026-2027 en compte 259.
- **441 auditeurs-années à deux niveaux** : M2, plus des « dettes » du M1, la même année. ACMA tient alors deux inscriptions, et l'archive les garde toutes les deux.
- **Parcours :**
  - 1 124 auditeurs inscrits une seule année, 1 369 deux années, 189 de trois à six ;
  - 189 ont redoublé le M1 (deux inscriptions `FPTC4` ou plus) et 255 ont plusieurs inscriptions en M2.
- **95 fiches n'ont aucune inscription datée.** Elles n'ont ni note ni paiement ; une seule a un PV de soutenance, une seule un certificat (Q1).

| Année | M1 inscrits | M1 admis | M2 inscrits | M2 admis |
|---|---:|---:|---:|---:|
| 2013-2014 | 85 | 0 | 0 | 0 |
| 2014-2015 | 101 | 0 | 73 | 0 |
| 2015-2016 | 145 | 0 | 91 | 0 |
| 2017-2018 | 206 | 152 | 111 | 0 |
| 2018-2019 | 196 | 133 | 169 | 125 |
| 2019-2020 | 151 | 111 | 167 | 119 |
| 2020-2021 | 199 | 135 | 193 | 94 |
| 2021-2022 | 272 | 186 | 206 | 109 |
| 2022-2023 | 247 | **3** | 299 | 147 |
| 2023-2024 | 270 | 193 | 262 | 135 |
| 2024-2025 | 335 | 204 | 296 | 133 (dont FFCP : **1** sur 66) |
| 2025-2026 | 325 | 205 | 276 | **0** |
| 2026-2027 | 241 | 0 | 18 | 0 |

(Lignes d'inscription ; un auditeur à deux niveaux compte dans les deux colonnes.)

**Décisions absentes d'ACMA.** Plusieurs cohortes ont des notes mais aucune décision enregistrée. Le recalcul par les règles d'ACMA le montre :

| Cohorte | Notes | Décision enregistrée | Recalcul : toutes les UE ≥ 12 |
|---|---|---|---:|
| M1 2022-2023 | 226 auditeurs | 3 admis ; 219 sans moyenne enregistrée | 183 |
| M2 2025-2026, toutes options | 206 auditeurs | aucun admis, aucune moyenne enregistrée | 165 |
| FFCP 2024-2025 | 55 auditeurs | 1 admis | 40 |
| M2 2017-2018, années 2013 à 2016 | aucune | aucune | sans objet |

Ailleurs, la décision enregistrée et le recalcul concordent à quelques cas près (§10.5, C05). Ce sont souvent des « admis sans toutes les UE » en M2 2024-2025 (28 cas), probablement des autorisés à soutenir (Q3, Q4).

### 3.4 Notes

- **49 987 notes**, de 2017-2018 à 2026-2027 : 49 746 jusqu'en 2025-2026, et 241 en 2026-2027 (§3.8).
- **Défauts :**
  - l'unicité de `notes` porte sur (UE, matricule), sans l'année. Une reprise d'UE par un redoublant écrase donc la note de l'année précédente, qui ne survit que dans `notes_hist` ;
  - **3 978 couples (ligne de programme, auditeur) n'existent plus que dans l'historique** :
    - 2 468 dont le programme existe encore (année connue), donc reconstituables ;
    - 1 510 dont le programme a été supprimé (année inconnue, Q6) ;
  - `notes.anneeacademic` est vide pour 29 428 lignes et diffère du programme pour 3 850 : l'année se lit dans le programme, jamais dans la note ;
  - `notes.moy` (moyenne enregistrée) diffère du recalcul pour la plupart des lignes : elle n'est pas fiable (« PSSFP Système » l'avait déjà relevé) ;
  - 4 notes sortent de l'intervalle 0 à 20 ;
  - 827 notes de rattrapage sont saisies mais pas appliquées (`ok` = 0 et rattrapage > 0), dont 808 en M1 2020-2021.
- **Programmes :**
  - 704 lignes jusqu'en 2025-2026, chacune de 60 crédits par année et par niveau, sauf le M1 2024-2025 (64) et le M2 2025-2026 (44, et 47 en `AUCO5` : le mémoire n'y figure pas) ;
  - 14 lignes ont des pondérations dont la somme n'est pas 1, et 2 lignes sont rattachées au groupe d'un autre niveau.

### 3.5 Paiements

| Année | Lignes | Colonne scolarité (`Montant`) | Total des 5 colonnes |
|---|---:|---:|---:|
| 2013-2014 | 85 | 86 950 000 | 95 875 000 |
| 2014-2015 | 174 | 176 655 000 | 194 715 000 |
| 2015-2016 | 235 | 228 700 000 | 253 375 000 |
| 2017-2018 | 292 | 256 125 000 | 293 695 000 |
| 2018-2019 | 172 | 122 610 000 | 138 365 000 |
| 2019-2020 | 278 | 193 670 855 | 226 010 855 |
| 2020-2021 | 509 | 216 352 000 | 251 517 001 |
| 2021-2022 | 1 180 | 272 183 100 | 333 873 100 |
| 2022-2023 | 1 104 | 329 237 226 | 393 032 226 |
| 2023-2024 | 1 041 | 328 475 391 | 392 120 391 |
| 2024-2025 | 1 248 | 388 969 100 | 463 979 100 |
| 2025-2026 | 990 | 343 290 100 | 409 710 100 |
| **Jusqu'en 2025-2026** | **7 308** | **2 943 217 772** | **3 446 267 773** |
| 2026-2027 | 145 | 46 595 000 | 65 495 000 |
| **Total** | **7 453** | **2 989 812 772** | **3 511 762 773** |

Les « 5 colonnes » sont `Montant` (scolarité), `inscriptions`, `bibliotheque`, `fraisperiscol` et `tenuesport`. Une même ligne peut en remplir plusieurs. Le total versé d'une ligne est leur somme : c'est la règle du script « PSSFP Système » `23_ecarts_paiements.sql`, vérifiée ici. Les colonnes `Versement`, `RestePayer` et `montantautres` sont des cumuls ou des copies sans signification fiable : `Versement` totalise par exemple 258 milliards en 2021-2022.

Anomalies :

- **324 lignes négatives**, pour −82,0 millions. 148 ont une contrepartie positive de même valeur absolue (même auditeur, même année) : ce sont des annulations à la manière d'ACMA. Les 176 autres sont isolées ;
- **18 lignes au total nul** ;
- **dates de reçu aberrantes** : 5 dans le futur (de 2045 à 2230), 6 avant 2013 (dont 1120 et 2002), 1 vide, et 1 121 hors de l'année académique à un an près ;
- **49 groupes de doublons exacts** (même auditeur, année, montant, date et phase), soit 102 lignes ;
- le numéro de reçu n'identifie pas une opération : 781 numéros sont partagés par 5 155 lignes ;
- 14 lignes portent sur un niveau où l'auditeur n'est pas inscrit cette année-là.

Banques : CREMINCAM 7 207 lignes, espèces 234, CRI 11, prise en charge 1.

### 3.6 Barèmes et phases

**Barèmes** (`configurationdu`) : identiques pour les 7 catégories d'une même année et d'un même niveau.

| Années | M1 (`FPTC4`) | M2 (5 options) |
|---|---:|---:|
| 2013-2014 à 2020-2021 | 1 145 000 | 1 105 000 |
| 2021-2022 à 2026-2027 | 1 185 000 | 1 135 000 |

Composantes : inscription 50 000, scolarité 1 000 000, bibliothèque 35 000, périscolaire 20 000 puis 50 000, et tenue de sport en M1 (40 000 puis 50 000). Les colonnes d'ACMA sont mal nommées : `tranche2` porte la bibliothèque et `tranche3` le périscolaire. Pour certaines catégories, le total `montantAregler` dépasse la somme des composantes : la tenue de sport y est comptée sans être ventilée. Le dû de référence est donc `montantAregler`, comme dans « PSSFP Système ».

**Phases** (`du.codephase`) :

| Phase | Lignes |
|---|---:|
| AVANCE FRAIS SCOLARITE | 5 788 |
| FRAIS INSCRIPTION | 1 498 |
| TENUE DE SPORT | 70 |
| FRAIS PERISCOLAIRES | 47 |
| BIBLIOTHEQUES M2 | 44 |
| BIBLIO-FRAISPERI-TENUE | 6 |

### 3.7 Identités et matricules

- **Longueur des matricules :**

  | Longueur | Fiches |
  |---:|---:|
  | 6 | 2 |
  | 7 | 169 |
  | 8 | 2 556 |
  | 9 | 47 |
  | 10 | 4 |

- **Formes :**
  - `AAEFPNNN` : la quasi-totalité depuis 2015 ;
  - `AAEFSCNNN` : 40 fiches, en 2025 ;
  - formes anciennes des promotions 13 et 14 : `GB`, `CP`, `FPL`, `A` suivis de chiffres ;
  - 36 fiches à numéro sur 2 chiffres (`AAXXXNN`), dont 24 de l'ancien format `FPL`.

  `AA` est l'année civile de création du matricule, pas l'année académique. Ainsi, 35 matricules `25…` ont leur première inscription en 2024-2025.
- **Défauts de saisie :**
  - 10 matricules en minuscules ;
  - 2 avec des espaces autour, 3 avec une espace interne ;
  - 4 avec un autre caractère (tiret final, par exemple) ;
  - 1 préfixé `zz` (fiche neutralisée, avec une inscription et un paiement en 2025-2026).
- **Doublons et collisions :**
  - **1 matricule porté par deux fiches** à la casse ou à une espace près, avec des notes des deux côtés ;
  - **15 collisions** si l'on retire les zéros de tête de la partie numérique (forme fictive : `99XX12` et `99XX012`), à ne jamais fusionner d'office.
- **Dates de naissance :**
  - 136 vides ;
  - 313 aberrantes (années 1 à 31, et une en 1900) ;
  - 5 « né vers ».
- **Sexe** : `M` 1 685, `F` 893, `H` 197 (à lire `M`), 3 vides.
- **Contacts :**
  - téléphone renseigné pour 2 056 fiches ;
  - aucun e-mail réel : le champ `email` sert à autre chose ;
  - aucun numéro de pièce : le champ `numcni` porte la région d'origine.
- **Le nom est un seul champ** (« NOM PRÉNOMS »), limité à 50 caractères (1 nom tronqué).

### 3.8 ACMA en 2026-2027

ACMA a continué de servir en parallèle de la plateforme :

- **259 inscriptions :**
  - 240 de la P14 (préfixe `26`), toutes en M1 ;
  - 16 de la P13 en M2 ;
  - 3 auditeurs de la P12 (préfixe `24`, 1 en M1 et 2 en M2), **absents de la plateforme**.
- **145 paiements :**

  | Promotion | Lignes | Auditeurs | Colonne scolarité | Total des 5 colonnes | Plateforme (constat du 15/11) |
  |---|---:|---:|---:|---:|---|
  | P14 | 127 | 122 | 43 080 000 | **59 550 000** | 131 versements, 59,8 M |
  | P13 | 15 | 15 | 2 965 000 | 4 990 000 | 10 versements (M2), 2,95 M |
  | P12 | 3 | 3 | 550 000 | 955 000 | absents de la plateforme |

  Précision sur le constat de départ : les 43,1 M de la P14 ne comptent que la colonne scolarité. Avec les droits d'inscription et les frais annexes, ACMA compte 59,55 M, contre 59,8 M dans la plateforme. L'écart réel se lit ligne par ligne (§9).
- **241 notes (IPS03 et RAD35-4) : des grilles vides.**
  - Elles portent sur l'UE `IPS03` du M1, pour 240 auditeurs de la P14, et sur l'UE `RAD35-4` de l'option `AUCO5`, pour 1 auditeur de la P13.
  - Les 241 lignes valent 0 en CC, en TP et à l'examen. Elles n'ont ni anonymat, ni rattrapage, ni validation, et aucune version de l'historique ne porte de valeur.
  - Toutes ont été créées par un même compte d'ACMA entre le 27/10 et le 09/11/2026 : c'est l'ouverture des grilles de saisie, pas une saisie de notes.
  - Effet de bord : pour au moins un auditeur, la ligne de 2026-2027 a écrasé sa note de la même UE d'une année antérieure (unicité sans l'année). L'archive la reconstitue depuis l'historique (§7.5).
  - Les 241 inscriptions M1 2026-2027 d'ACMA ont probablement été créées par le déclencheur « première note ».

### 3.9 Constats de départ vérifiés

| Constat | Vérification |
|---|---|
| 2 778 auditeurs, 10 038 inscriptions dont 5 104 sans année ni niveau | Exact |
| 49 987 notes, 309 580 versions | Exact |
| 7 453 versements, environ 3,0 milliards | Exact : 2 989 812 772 en colonne scolarité ; 3 511 762 773 sur les 5 colonnes |
| 801 PV de soutenance, 197 certificats | Exact |
| 14 années, dont 10 « EN COURS » à tort | 10 « EN COURS », dont 9 à tort (§3.2) |
| `FPTC4` = M1 ; `AUCO5`, `EPGP5`, `FFCP5`, `GTFP5`, `MPPP5` = M2 ; `POURTEST` filière de test | Exact. `POURTEST` n'a aucune fiche |
| Reçu en 2050, montants négatifs, matricules `zz`, longueurs de 7, 8 et 9 caractères | Exact, et plus large : reçus de 2045 à 2230, 324 négatifs, longueurs de 6 à 10, 1 fiche `zz` |
| 95 auditeurs sans inscription datée | Exact |
| 241 notes 2026-2027 sur `IPS03` et `RAD35-4` | Exact : grilles vides (§3.8) |
| 4 mots de passe en clair dans `utilisateur` | Exact. S'y ajoutent l'identifiant et le mot de passe FTP et la clé de plateforme en clair dans `etablissement` (§13) |
| 543 matricules en production, 535 dans ACMA, 8 absents | Non vérifiable sans la production : `acma:reprendre` le recalcule (C08). ACMA compte 240 matricules `26…` et 302 `25…` |

## 4. Notre modèle : ce qu'il permet et ce qu'il interdit

| Question | Réponse (code au 15/11/2026) | Conséquence |
|---|---|---|
| Un auditeur peut-il exister sans candidature ? | **Non.** `auditeurs.candidature_id` est NOT NULL et unique. L'identité, le matricule, le téléphone et le mot de passe eCampus se lisent par la candidature (ADR-0010 ; `Auditeur`, `ImportAuditeurs`). | Un ancien auditeur ne peut pas être un `Auditeur` sans fabriquer une candidature. |
| Peut-on fabriquer une candidature d'archive ? | Techniquement oui (précédent de l'import P13 : retenu hors PV, `submitted_at` nul), mais téléphone, date de naissance, adresse, lieu de naissance et spécialité sont NOT NULL. ACMA n'a pas de date exploitable pour 449 fiches, ni d'adresse. | Il faudrait inventer des valeurs. Les listes, relances, statistiques de campagne et la synchronisation eCampus verraient ces dossiers. **Écarté** (ADR-0014). |
| Une inscription annuelle peut-elle porter une année passée ? | Oui : `inscriptions_annuelles` est unique par (auditeur, année), avec un barème obligatoire (`baremes_scolarite`, unique par année et niveau, M1 ou M2). L'inscription la plus récente commande l'accès (`inscriptionCourante`). | Un arriéré peut entrer comme une inscription d'une année passée, à condition qu'il existe une inscription plus récente (§8.5). |
| Les résultats LMD peuvent-ils porter l'historique ? | `resultats_annuels` exige une délibération, `acquis_ue` une ligne de maquette, et nos maquettes refusent des pondérations dont la somme n'est pas 1. | Pas de reprise dans le LMD (décision 1). Seul pont : `acquis_ue` d'origine `correspondance` pour les redoublants (§7.6). |
| Un document officiel peut-il viser une archive ? | `documents_delivres` exige `auditeur_id` et `inscription_annuelle_id`. | Il faut élargir la table (§7.8). |
| Les codes de spécialité de la plateforme ? | Parcours `TC` (M1), `ACFP`, `EPGP`, `FFCP`, `GTFP`, `MPP` (M2), vérifiés dans `parcours` et dans les tests du lot 4. | Correspondance du §5.2, dont deux codes différents : `AUCO5` devient `ACFP`, `MPPP5` devient `MPP`. |

## 5. Correspondances

### 5.1 Années

| ACMA | Archive |
|---|---|
| 2013-2014 à 2025-2026 | Reprises. Statut « close » pour toutes, quel que soit le statut d'ACMA. |
| 2016-2017 | Reprise comme année sans inscription (aucune donnée) |
| 2026-2027 | **Non reprise.** Elle sert seulement au rapport des écarts (§9). |

Le format `AAAA-AAAA` est celui de `inscriptions_annuelles.annee_academique`. Une valeur hors de ce format est refusée et journalisée.

### 5.2 Niveaux et parcours

| Code ACMA | Niveau | Parcours de la plateforme | Libellé |
|---|---|---|---|
| `FPTC4` | M1 | `TC` | Tronc commun |
| `AUCO5` | M2 | `ACFP` | Audit et contrôle des finances publiques |
| `EPGP5` | M2 | `EPGP` | Économie publique et gestion publique |
| `FFCP5` | M2 | `FFCP` | Fiscalité, finance et comptabilité publique |
| `GTFP5` | M2 | `GTFP` | Gouvernance territoriale et finances publiques locales |
| `MPPP5` | M2 | `MPP` | Marchés publics et partenariats public-privé |

La table de correspondance est une constante du code (`CorrespondancesAcma::NIVEAUX`). Un code inconnu bloque l'étape. L'archive garde aussi le code ACMA d'origine.

### 5.3 Groupes d'UE et UE : une archive, pas des UE ni des ECUE

Un groupe d'ACMA (`grpue`) est un semestre ou un regroupement de semestres, propre à un niveau. Une « UE » d'ACMA (`ue`, programmée par `programe`) a la taille de nos ECUE : 16 « UE » d'ACMA pour le M1 2025-2026, contre 6 UE et 19 ECUE dans notre maquette M1 2026-2027. Les codes n'ont rien de commun : `IPS03` d'un côté, `MPMPP71x` de l'autre.

**Décision proposée : `programe`, `ue` et `grpue` deviennent une photographie annuelle `acma_unites`**, une ligne par ligne de programme. Elle porte le code et l'intitulé de l'UE ACMA (français et anglais séparés à la parenthèse finale), le groupe et son libellé, le semestre recodé, les crédits, le coefficient, les pondérations CC, TP et examen, et les volumes. Elle ne crée aucune UE, aucune ECUE, aucune maquette. Les anomalies (pondérations hors norme, groupe d'un autre niveau) sont gardées telles quelles et signalées.

Semestre recodé : M1, semestres 1 et 2 ; M2, semestres 3 et 4. Un semestre 1 ou 2 en M2 est relu 3 ou 4 ; une valeur incohérente reste vide (règle du script « PSSFP Système » `21_reprise_socle.sql`).

Les correspondances vers nos UE ne servent qu'aux redoublants encore en formation (§7.6, sous-lot 15g).

### 5.4 Catégories et barèmes

- **Catégorie** (`etudiant.codcategorie`) : c'est la spécialité visée ou suivie, pas une catégorie tarifaire, puisque les barèmes sont identiques pour toutes. Elle est gardée telle quelle sur le dossier, à titre informatif. Le libellé erroné de la catégorie `AC` (« …zeerzerze » dans un ancien export) est corrigé s'il réapparaît.
- **Barèmes d'archive** (`acma_baremes`) : une ligne par année et par niveau, avec le dû total (`montantAregler`) et les composantes telles qu'ACMA les nomme. Ils servent à l'affichage et au calcul des arriérés.
- **Barèmes du compte** (`baremes_scolarite`) : seulement pour une année passée qui reçoit un arriéré validé (2024-2025 et 2025-2026 en pratique). Ils sont créés par migration avec la même répartition qu'en 2026-2027, puisque les totaux sont égaux (Q8) :

  | Niveau | Droits d'inscription | 1re tranche | 2e tranche | Total |
  |---|---:|---:|---:|---:|
  | M1 | 185 000 | 500 000 | 500 000 | 1 185 000 |
  | M2 | 135 000 | 500 000 | 500 000 | 1 135 000 |

  Les échéances restent vides : une année passée n'a pas d'échéance de relance.

### 5.5 Phases de paiement

| Phase ACMA | Correspond à (affichage) |
|---|---|
| FRAIS INSCRIPTION ; BIBLIOTHEQUES M2 ; FRAIS PERISCOLAIRES ; TENUE DE SPORT ; BIBLIO-FRAISPERI-TENUE | Droits d'inscription (y compris bibliothèque, périscolaire et tenue) |
| AVANCE FRAIS SCOLARITE | Frais de scolarité (1re et 2e tranches) |

La phase et les cinq colonnes sont gardées telles quelles. Une ligne d'avance porte souvent aussi des droits d'inscription. Aucune ventilation par palier n'est faite pour les années passées : seuls comptent le total versé de l'année et le dû.

### 5.6 Règles de calcul d'ACMA, pour l'archive seulement

| Règle | ACMA (vue `vue_pv_ue`) | Plateforme (lots 4 à 6) | Usage dans l'archive |
|---|---|---|---|
| Moyenne d'UE | `pcc × CC + ptp × TP + pexam × examen`, pondérations de la ligne de programme | CC 30 %, assiduité 15 %, examen 55 % par défaut ; note d'UE = moyenne des ECUE pondérée par les crédits | Recalcul « selon ACMA », affiché comme indicatif |
| Rattrapage | Si `ok` = 1, la note de rattrapage **remplace la moyenne** | Idem (remplace la moyenne) | Session retenue = rattrapage si `ok` = 1 |
| Seuil | 12/20 (`MoyenSeuil`) | 12/20 | UE acquise si moyenne ≥ 12 |
| Note éliminatoire | Aucune | 7/20 à l'examen, à partir de 2026-2027 | Non appliquée |
| Décision | Admis si aucun groupe sous 12 ; table `lmd` aux bornes chevauchantes | Par crédits : M1 60 = AD ; M2 44 = AS, 60 = AD | **Décision enregistrée par ACMA** ou transcrite d'un PV (§7.5) ; jamais recalculée comme décision |
| Moyenne générale | Moyenne simple des groupes (non pondérée) | Pondérée par les crédits | Moyenne enregistrée (`moydunivo`) si présente, sinon « non enregistrée » ; moyenne pondérée recalculée affichée comme indicative |

ACMA tenait une seconde règle dans `notes.moy`, où le rattrapage remplaçait l'examen. Elle n'explique pas ses décisions (contrôle C06e de « PSSFP Système ») : elle est ignorée.

## 6. Matricules et rapprochement

### 6.1 Normalisation

Classe pure `NormalisationAcma::matricule(string): ?string` :

1. retirer toutes les espaces (bords et intérieur) ;
2. passer en majuscules ;
3. refuser (`null`) un matricule vide ou qui ne contient ni chiffre ni lettre.

Rien d'autre :

- les zéros de tête ne sont **jamais** ajoutés ni retirés, et les préfixes ne sont jamais réécrits (règle R1 de « PSSFP Système » : un matricule ne change pas) ;
- le matricule d'origine est gardé à côté (`matricule_source`) ;
- un caractère parasite (tiret final, par exemple) n'est pas corrigé : la fiche est signalée.

Exclusions par défaut, journalisées et listées :

- fiche de la filière `POURTEST` : aucune dans l'export du 15/11 ;
- matricule préfixé `zz`, `ZZ` ou `Zz` : 1 fiche, neutralisée dans ACMA (Q2) ;
- fiche dont toutes les inscriptions datées sont en 2026-2027 : 240 fiches de la P14. L'archive n'a rien à en dire, et ils figurent dans le rapport des écarts ;
- fiche sans inscription datée : 95 (Q1).

**Fusion.** Deux fiches au même matricule normalisé (1 cas) forment un seul dossier. Leurs inscriptions, notes et paiements y sont rattachés. Les conflits (deux notes pour la même ligne de programme) restent visibles côte à côte, et rien n'est choisi d'office (C04).

**Collisions sans les zéros de tête** (15 groupes) : jamais fusionnées. Elles sont listées pour information, car ce sont probablement des personnes différentes aux formats anciens.

### 6.2 Identité de l'archive

| Champ | Règle |
|---|---|
| `nom_complet` | Nom ACMA, espaces réduites, en majuscules. Pas de séparation nom et prénoms : ACMA ne la connaît pas. |
| `sexe` | `M` (M ou H), `F`, sinon vide |
| `date_naissance` | Date valide, année comprise entre 1940 et 2010 ; sinon vide, avec `date_naissance_a_verifier`. Valeur d'origine gardée en texte. « Né vers » : année seule, précision `vers`. |
| `lieu_naissance`, `nationalite` | Texte d'origine, nettoyé des espaces |
| `categorie_acma`, `filiere_acma` | Codes d'origine |
| Téléphone, adresse, région | ~~Non repris (Q16)~~ **Repris depuis le 16/11/2026**, bruts, avec toutes les colonnes de `etudiant` (décision 3 du 16/11 ; [réinscription des anciens](module-4-lot15-reinscription-anciens.md), §4) |

### 6.3 Rapprochement avec la plateforme

Le rapprochement s'applique aux dossiers de l'archive et aux auditeurs de la plateforme : matricule de la candidature liée. Classes pures : `NormalisationAcma::jetons(nom)` (sans accents, majuscules, jetons de deux lettres au moins) et `ComparaisonIdentite::comparer(...)`.

**Comparaison des noms.** Score = jetons communs / plus petit des deux ensembles. On compare le nom ACMA aux jetons de `nom`, `prenom` et `epouse` de la candidature. « Compatible » si le score est d'au moins 0,66. Le seuil est une constante testée.

| Cas | Matricule | Date de naissance | Noms | Traitement |
|---|---|---|---|---|
| R1 | Égal | Égale | Compatibles | **Lien automatique** |
| R2 | Égal | Absente ou à vérifier dans ACMA | Compatibles | Lien automatique, avec l'avertissement « date ACMA inexploitable » |
| R3 | Égal | Égale | Non compatibles | **À arbitrer** : variante d'écriture, nom d'épouse ou erreur |
| R4 | Égal | Différente (les deux connues) | Peu importe | **À arbitrer, pas de lien** : autre personne, ou erreur de saisie |
| R5 | Absent de l'archive | — | — | Information : « dans la plateforme, absent d'ACMA » (8 attendus selon le constat du 15/11) |
| R6 | Différent | Égale | Compatibles (score 1) | **À arbitrer** : même personne probable sous un autre matricule, par exemple un ancien réadmis |

- **Arbitrage** (sous-lot 15d, permission `archives.rapprocher`) :
  - choix : lier, ne pas lier (personnes différentes) ou écarter le dossier (fiche de test) ;
  - motif d'au moins 10 caractères ;
  - l'arbitrage est inscrit dans `acma_rapprochements` (en ajout seul) et au journal.
- **Un lien n'est jamais posé** sur une identité divergente sans arbitrage. Un lien à tort mettrait les notes d'une personne sur la fiche d'une autre.
- **La plateforme fait foi pour l'identité vivante.** L'archive garde l'identité d'ACMA, et un écart ne modifie jamais la candidature.

Dossiers attendus après la reprise : environ 2 441. C'est 2 443 fiches avec une inscription datée jusqu'en 2025-2026, moins la fiche `zz` et la fusion du doublon, plus Q1 le cas échéant. Environ 295 seront liés à des auditeurs de la P13. Les autres sont des anciens auditeurs.

### 6.4 Listes à arbitrer

La commande produit un classeur `rapprochement-AAAAMMJJ.xlsx`, rangé dans le stockage privé et téléchargeable avec `archives.rapprocher`. Il comprend :

1. cas R3, R4 et R6, avec les deux identités côte à côte ;
2. cas R5 ;
3. fiches exclues (`zz`, sans inscription datée) ;
4. fusions et collisions ;
5. auditeurs actifs dans ACMA en 2026-2027 mais absents de la plateforme (3 de la P12, §3.8 ; Q7).

## 7. Modèle cible

### 7.1 Principes (ADR-0014)

- **Transit** : schéma `acma_transit`, brut, tout en texte, jetable (§10.1).
- **Archive** : tables `acma_*`, écrites seulement par `acma:reprendre` en mode reprise, puis gelées.
- **Liens vers le vivant** : `acma_dossiers.auditeur_id` (facultatif) ; `acma_rapprochements`, `acma_complements` et `acma_arrieres`, en ajout seul ou à états fermés.
- **Pas d'écriture dans le modèle vivant pendant la reprise** (C09). Les seules écritures vers le vivant sont ailleurs : l'entrée d'un arriéré (§8) et l'acquis d'un redoublant (§7.6), une ligne à la fois, après validation.
- `uuid` sur toute table exposée dans une URL (règle 10 du projet) ; `id` bigint interne.

### 7.2 Tables de l'archive

| Table | Clé naturelle (unique) | Colonnes principales | Volume attendu |
|---|---|---|---:|
| `acma_reprises` | `uuid` | export (nom, date, SHA-256 du manifeste), étapes, mode (`essai`, `appliquee`), statut (`appliquee`, `gelee`), comptes par étape (JSONB), lancée par, début et fin | quelques |
| `acma_annees` | `code` | statut ACMA d'origine, `reprise` (booléen) | 13 |
| `acma_unites` | `id_prog_acma` | année, niveau ACMA, niveau, parcours, code et intitulés de l'UE, groupe et libellé, semestre ACMA et recodé, crédits, coefficient, pondérations, volumes, `ponderations_hors_norme`, `groupe_autre_niveau` | 704 |
| `acma_dossiers` | `matricule` | `uuid`, matricule d'origine, identité (§6.2), première et dernière année, `auditeur_id` (facultatif, indexé), `statut_rapprochement` (`lie_auto`, `lie_arbitre`, `sans_auditeur`, `a_arbitrer`, `ecarte`), `fiches_fusionnees`, `retire_de_la_source_at` | environ 2 441 |
| `acma_inscriptions` | (`dossier`, `annee`, `niveau_acma`) | `uuid`, niveau, parcours, ids ACMA fusionnés. **Valeurs ACMA** : admis, moyenne, total de crédits, nombre de dettes, frais de scolarité. **Valeurs recalculées** : crédits du programme, crédits acquis, moyenne pondérée, nombre de rattrapages. **Décision d'archive** : `admis`, `non_admis` ou `non_enregistree`, avec sa source (`acma` ou `complement`). Drapeau `deux_niveaux_meme_annee` | 4 673 |
| `acma_resultats_ue` | (`inscription`, `unite`) | CC, TP, examen, rattrapage (bruts et bornés 0 à 20), `rattrapage_applique`, session retenue, moyenne recalculée, crédits, `acquise`, origine (`notes`, `historique`), `id_note_acma` | 49 746 + 2 468 |
| `acma_notes_non_rattachees` | (`id_prog_acma`, `dossier`) | Dernière version connue d'une note dont le programme a disparu (Q6) | 1 510 couples |
| `acma_versions_notes` | `id_version` = (`id_note_acma`, `horodatage`) | dossier, ligne de programme, valeurs, rattrapage, `ok`, identifiant ACMA de l'auteur, horodatage | 309 580 (Q5) |
| `acma_baremes` | (`annee`, `niveau_acma`) | dû total et composantes | 72 |
| `acma_paiements` | `id_acma` | dossier, année, niveau, phase, les 5 colonnes, `total`, date de reçu (valide ou vide) et texte d'origine, numéro de reçu, banque, identifiant ACMA de la saisie. Drapeaux : `negatif`, `contrepartie_id`, `total_nul`, `date_aberrante`, `hors_annee`, `doublon_exact`, `niveau_sans_inscription` | 7 308 |
| `acma_soutenances` | `id_acma` | dossier, niveau, date (lue dans le texte, sinon date de l'opération) et texte d'origine, thème, note, mention normalisée (`TRES-BIEN` et `TRES BIEN` deviennent « Très bien ») et texte d'origine, président, rapporteur, membres, observation | 801 |
| `acma_documents` | (`type`, `id_acma`) | type (`certificat`, `attestation_reussite`, `lettre_stage`, `non_perception_bourse`, `mise_a_disposition`), dossier, niveau, date, détails (JSONB : spécialité, parcours, thème et structure de stage, mémoire) | 337 |
| `acma_rapprochements` | `id` | dossier, auditeur proposé, cas (R1 à R6), score, décision (`lie`, `non_lie`, `ecarte`), motif, décidé par, date ; **ajout seul** | environ 300 |
| `acma_complements` | `id` | cible (dossier ou inscription), champ (`date_naissance`, `decision`, `moyenne`), valeur, motif, pièce (référence du PV ou du dossier papier, fichier facultatif), auteur, date ; **ajout seul** (§7.5) | — |
| `acma_arrieres` | (`dossier`, `annee`) | §8 | environ 150 |
| `acma_correspondances` | (`unite`, `maquette_ue_id`) | §7.6 | — |

Contraintes :
- clés étrangères en RESTRICT entre les tables `acma_*` ;
- `acma_dossiers.auditeur_id` est une clé étrangère RESTRICT vers `auditeurs` : un auditeur lié ne se supprime pas, ce qui ne change rien puisque les auditeurs ne se suppriment déjà pas ;
- les `CHECK` portent sur les états fermés, les années au format `AAAA-AAAA`, les niveaux `M1` et `M2`, et les notes bornées entre 0 et 20 dans les colonnes bornées ;
- les colonnes brutes gardent la valeur d'origine, même hors bornes.

Les montants sont des entiers en FCFA (`bigint`). ACMA les stocke en flottant, mais aucun n'a de partie décimale ; une décimale bloquerait l'étape.

### 7.3 Lecture seule et gel

SQL brut autorisé par l'ADR-0014 :

```sql
CREATE OR REPLACE FUNCTION acma_archive_lecture_seule() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF EXISTS (SELECT 1 FROM acma_reprises WHERE statut = 'gelee') THEN
    RAISE EXCEPTION 'Archive ACMA gelée : aucune écriture' USING ERRCODE = 'check_violation';
  END IF;
  IF coalesce(current_setting('app.reprise_acma', true), '') <> 'on' THEN
    RAISE EXCEPTION 'Archive ACMA en lecture seule : écriture réservée à acma:reprendre' USING ERRCODE = 'check_violation';
  END IF;
  RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END $$;
-- sur chaque table de l'archive : acma_annees, acma_unites, acma_inscriptions, acma_resultats_ue,
-- acma_notes_non_rattachees, acma_versions_notes, acma_baremes, acma_paiements, acma_soutenances,
-- acma_documents ; acma_dossiers a sa propre fonction (lien modifiable, voir ci-dessous)
CREATE TRIGGER acma_<table>_lecture_seule BEFORE INSERT OR UPDATE OR DELETE ON acma_<table>
  FOR EACH ROW EXECUTE FUNCTION acma_archive_lecture_seule();
CREATE TRIGGER acma_<table>_sans_truncate BEFORE TRUNCATE ON acma_<table>
  FOR EACH STATEMENT EXECUTE FUNCTION acma_archive_lecture_seule();
```

- **Mode reprise** : la commande ouvre une transaction et pose `SET LOCAL app.reprise_acma = 'on'`. La variable meurt avec la transaction. Elle protège contre une écriture accidentelle de l'application ou d'une console, pas contre un administrateur de la base, qui reste tracé par le journal du serveur.
- **Le lien d'un dossier** (`auditeur_id`, `statut_rapprochement`) échappe au gel. `acma_dossiers` a donc sa propre fonction de déclencheur :
  - en mode reprise et avant le gel, tout est permis ;
  - sinon, une modification n'est acceptée que si elle ne touche que ces deux colonnes et `updated_at`, et l'insertion comme la suppression sont refusées.

  Seul `RapprochementAcma` (§6.3) fait ce geste, après avoir écrit la décision dans `acma_rapprochements`.
- **Le gel** (`acma:geler --auteur=`) :
  - il exige une reprise appliquée sans contrôle bloquant en échec ;
  - il passe la dernière reprise au statut `gelee` et l'inscrit au journal ;
  - il est irréversible par l'application : défaire un gel demande une migration motivée.
- `acma_complements`, `acma_rapprochements` : déclencheur d'ajout seul. Il refuse `UPDATE`, `DELETE` et `TRUNCATE`, sur le précédent de `historique_notes`.
- `acma_arrieres`, `acma_correspondances` : tables de travail à états fermés. Leurs transitions sont contrôlées par le service et par un déclencheur qui refuse un retour en arrière (§8.3).

### 7.4 L'ancien auditeur

- **Un dossier d'archive** (`acma_dossiers`), avec ses inscriptions, résultats, paiements, soutenances et documents. Il n'a ni candidature, ni compte, ni `Auditeur`, ni espace, ni API publique.
- **Visible** dans l'écran « Archives ACMA » par les titulaires de `archives.consulter`.
- **Aucun envoi.** Aucun code de la reprise n'appelle un service d'envoi, eCampus ou une notification. Les tests le vérifient : `Notification::fake`, `Queue::fake`, `Http::fake` et `assertNothingSent` (C10).
- **Un ancien qui revient** (nouvelle candidature, par exemple) devient un auditeur par le chemin normal, et son dossier d'archive s'y lie par arbitrage (cas R6). Le lien n'est pas unique : une même personne peut avoir deux dossiers d'archive sous deux matricules.
- **Accès en ligne des anciens** : hors du lot 15 (décision 4). Depuis le 16/11, un ancien réinscrit dans l'année en cours est créé comme auditeur depuis son dossier, sans changer l'archive : [réinscription des anciens](module-4-lot15-reinscription-anciens.md).

### 7.5 Archive des résultats et des notes

- **Par inscription d'archive** : décision, moyenne, crédits acquis sur crédits du programme, nombre de rattrapages, et drapeau « deux niveaux la même année ».
- **Décision d'archive :**
  - `admis` si ACMA a enregistré `admis` = 1 ;
  - `non_admis` si `admis` = 0 et que la cohorte a des décisions enregistrées ;
  - `non_enregistree` pour les cohortes listées au §3.3, et pour toute inscription sans note.

  Les cohortes « sans décision enregistrée » sont une liste fermée, constante du code (Q3) : M1 2022-2023 ; M2 2025-2026 ; FFCP 2024-2025 ; M2 2017-2018 ; années 2013-2014 à 2015-2016.
- **Moyenne** : `moydunivo` si elle est enregistrée et non nulle, sinon « non enregistrée ». La moyenne pondérée recalculée s'affiche à côté, marquée « indicatif (recalcul selon ACMA) ».
- **Crédits acquis** : recalculés depuis les notes, UE par UE (§5.6), puisque `Totalcredit` est faux pour plusieurs cohortes. Un écart avec la décision (par exemple un admis sans toutes ses UE) est signalé sur la fiche, sans rien changer.
- **Par UE** :
  - code et intitulé ACMA, crédits ;
  - CC, TP, examen et rattrapage ;
  - session retenue, moyenne, acquise ou non.
- **Notes reconstituées** : un couple (programme, auditeur) absent de `notes` mais présent dans `notes_hist` reprend sa dernière version, avec l'origine `historique` (2 468 couples). C'est le cas de la note écrasée par la grille vide de 2026-2027 (§3.8).
- **Conflits** : deux fiches fusionnées qui notent la même ligne de programme. Les deux résultats restent visibles et signalés, aucun n'est choisi, et la ligne n'entre ni dans les crédits ni dans un relevé tant qu'elle n'est pas arbitrée par complément.
- **Compléments** (`acma_complements`, permission `archives.completer`) : la scolarité ne modifie jamais l'archive, elle la complète.
  - Elle transcrit **une décision d'un PV signé** pour une inscription `non_enregistree`. Il faut la référence du PV et un motif, et la fiche affiche alors « décision transcrite du PV n° … le … par … ».
  - Elle complète **une date de naissance** d'après le dossier papier.
  - Elle choisit **la note retenue en cas de conflit**.

  Le dernier complément d'un champ l'emporte, et tous restent visibles. Ce n'est pas une délibération (décision 1) : on recopie un acte qui existe.
- **Versions des notes** (Q5) : consultables depuis une ligne de résultat (« Historique ACMA de cette note »), avec la date et l'identifiant ACMA de l'auteur.

### 7.6 Acquis des redoublants (pont vers le LMD, sous-lot 15g)

Le lot 6 attend les correspondances d'UE et les acquis des redoublants avec la reprise d'ACMA. La question ouverte n° 3 de la feuille de route concerne les 100 P13 non admis en M1 2025-2026.

1. **Correspondances** (`acma_correspondances`) :
   - une ligne par UE ACMA du programme de l'année d'origine (M1 2024-2025 ou 2025-2026) et par UE de la maquette d'accueil (M1 2026-2027, validée), avec un poids en crédits ;
   - proposition automatique par intitulé normalisé, puis validation ligne par ligne par le responsable (`lmd.maquette_valider`).
2. **Note de l'UE d'accueil** : moyenne des notes ACMA correspondantes (moyenne retenue, rattrapage compris), pondérée par les crédits ACMA. L'UE est proposée « acquise » si cette note atteint 12 et que **toutes** ses ECUE sont couvertes par une correspondance (Q13).
3. **Validation ligne par ligne**, puis écriture dans `acquis_ue` avec l'origine `correspondance`, l'année et la session d'origine, et la note calculée. `deliberation_id` reste vide. Une trace relie l'acquis à l'inscription d'archive et à la correspondance.
4. **Prérequis** : la réinscription du redoublant (sous-lot D3), puisque l'acquis ne vaut que pour l'inscription d'accueil. La délibération du M1 2026-2027 (lot 6, scénario de base) le capitalise ensuite comme un acquis antérieur.

### 7.7 Historique des paiements

- **Lecture seule**, sur la fiche d'archive et dans l'onglet « Historique ACMA » de l'auditeur lié. Il présente :
  - par année : dû (barème), total versé, différence, et nombre de lignes signalées ;
  - le détail des lignes : date du reçu, numéro, banque, phase, colonnes et drapeaux.
- **Pas de création de versement, pas de reçu, pas d'imputation.** Les paiements d'ACMA ne sont pas des `Versement`.
- **La différence affichée n'est pas un arriéré.** Elle porte le libellé « écart selon ACMA (non vérifié) ». Seul un arriéré validé (§8) entre au compte.

### 7.8 Documents délivrés depuis l'archive (sous-lot 15e)

Extension de `documents_delivres` (migration additive, `down()` refusé tant qu'un document historique existe) :

- `auditeur_id` et `inscription_annuelle_id` deviennent facultatifs ;
- nouvelle colonne `acma_inscription_id` : clé étrangère RESTRICT vers `acma_inscriptions`, indexée ;
- types `releve_historique` et `attestation_historique` ajoutés au `CHECK` des types ;
- nouveau `CHECK` sur le propriétaire :
  - type historique : `acma_inscription_id` renseigné et `inscription_annuelle_id` vide ;
  - autres types : `auditeur_id` et `inscription_annuelle_id` renseignés et `acma_inscription_id` vide ;
- index unique partiel `(type, acma_inscription_id) WHERE revoque_at IS NULL AND acma_inscription_id IS NOT NULL` : un seul document en vigueur par type et par inscription d'archive.

`auditeur_id` est renseigné si le dossier est lié, pour que le document apparaisse aussi sur la fiche de l'auditeur.

Documents :

| Document | Numéro | Préalables | Contenu |
|---|---|---|---|
| Relevé de notes historique | `RH-AAAA-NNNNNN` | Inscription avec des notes ; aucun conflit de note non arbitré ; date de naissance connue (ACMA ou complément) | En-tête bilingue ; identité ; année, niveau, parcours ; par UE ACMA : code, intitulé, crédits, note retenue sur 20, session, acquise ou non ; crédits acquis ; moyenne (enregistrée ou « non enregistrée ») ; décision d'archive ; mention « Établi d'après les archives du logiciel ACMA, repris le … » |
| Attestation de réussite historique | `AH-AAAA-NNNNNN` | Décision d'archive `admis`, enregistrée ou transcrite ; date de naissance connue | Identité ; année, niveau, parcours ; « a été déclaré(e) admis(e) » ; même mention de source |

- **Pas de grade en lettres.** La grille de mentions d'ACMA est fausse (constat de « PSSFP Système », script 22), et notre grille ne s'applique pas à l'historique (Q12).
- **Délivrance** :
  - permission `archives.documents_delivrer`, réservée au responsable ;
  - le service `DocumentsHistoriques` réutilise la numérotation, le code de vérification, le scellement SHA-256 et le stockage privé de `DocumentsOfficiels` ;
  - un second téléchargement rend le même fichier.
- **Vérification publique** (`/verifier/{code}`) inchangée : type, numéro, date, titulaire, statut. Aucune note ni aucun matricule.
- **Révocation** : celle du lot 7 (`lmd.documents_revoquer`, motif obligatoire).
- **Règle « en règle »** (D9) : elle ne s'applique pas aux anciens, faute de compte. Pour un auditeur lié, l'attestation suit `DocumentsOfficiels::enRegle`, arriérés compris (D2).

### 7.9 Soutenances et documents d'ACMA

- **Soutenances** (801) :
  - sur la fiche d'archive : date, thème, note, mention, jury ;
  - 14 dossiers ont plusieurs PV, et tous sont gardés ;
  - date lue dans le texte : « 29 septembre 2021 », abréviations et variantes de mois comprises. À défaut, date de l'opération ; à défaut, vide.
- **Documents ACMA** (337) : listés comme « documents délivrés par ACMA », sans fichier (ACMA n'en garde pas), avec leur date et leurs détails.

### 7.10 Permissions (migration additive)

Nouveau module « Archives ACMA » dans `ModulePermissionMap`. Les droits sont posés par une migration additive, sans relancer `RolePermissionSeeder`. Listes figées, sur le précédent `2026_11_29_100100_grant_scolarite_deblocage_permissions`.

| Permission | Nature | Rôles |
|---|---|---|
| `archives.consulter` | Lecture | `super_admin`, `admin`, `scolarite_responsable`, `scolarite_agent` |
| `archives.documents_delivrer` | Sensible | `super_admin`, `admin`, `scolarite_responsable` |
| `archives.completer` | Sensible | `super_admin`, `admin`, `scolarite_responsable` |
| `archives.rapprocher` | Sensible | `super_admin`, `admin`, `scolarite_responsable` |
| `scolarite.arrieres_preparer` | Gestion (module Scolarité) | `super_admin`, `admin`, `scolarite_agent`, `scolarite_responsable` |
| `scolarite.arrieres_valider` | Sensible (module Scolarité) | `super_admin`, `admin`, `scolarite_responsable` |

- Le rapport des écarts 2026-2027 se télécharge avec la permission existante `scolarite.export`.
- Les commandes artisan n'ont pas de permission. Elles exigent `--auteur=<e-mail>` d'un compte `super_admin` ou `admin`, comme `scolarite:importer-auditeurs`.
- **Séparation des tâches** :
  - un couple s'ajoute au contrôle `SEC_SEPARATION` : « Préparer et valider un arriéré » (`scolarite.arrieres_preparer`, `scolarite.arrieres_valider`). Il est **informatif**, car le responsable détient les deux droits ;
  - la vraie garantie est de quatre yeux par ligne (§8.3).

### 7.11 Journal

| Journal | Événements |
|---|---|
| `archives` | `reprise_essai` et `reprise_appliquee` : export, SHA-256 du manifeste, comptes par étape, contrôles. Puis `archive_gelee`, `transit_charge`, `transit_purge`, `rapprochement_decide`, `complement_ajoute`, `document_historique_delivre` |
| `scolarite` | `arriere_acma_prepare`, `arriere_acma_porte` (montant, année, versement de reprise, imputations décrites) et `arriere_acma_rejete`, en plus des événements du lot 14c (journal `messagerie`) pour la demande |
| `lmd` | `correspondance_acma_validee`, `acquis_acma_valide` |

- **Pas d'entrée par ligne d'archive** : 400 000 lignes noieraient le journal scellé (14d-1).
- **Les données de l'archive ne vont pas au journal** : ni identités, ni notes. On n'y met que des uuid et des comptes.
- **Le journal applicatif** (fichiers) ne reçoit jamais de nom ni de matricule.

## 8. Arriérés

### 8.1 Périmètre

- **Auditeurs** : dossiers d'archive **liés** à un auditeur de la plateforme. Ce sont la P13 en pratique, avec les 35 auditeurs `25…` entrés en 2024-2025. Un ancien sans compte n'a pas d'arriéré au compte : ses écarts restent un affichage d'archive (§7.7, Q11).
- **Années** : 2013-2014 à 2025-2026. 2026-2027 relève de la plateforme (décision 2).
- **Ordre de grandeur** (export du 15/11) :
  - **P13 en 2025-2026 (M1) : 302 auditeurs.** 159 sont soldés, 11 en trop-perçu, 50 en paiement partiel et **82 sans aucune opération**, soit 132 lignes candidates pour **129 210 000 FCFA**.
  - **Sur tout l'historique : 1 701 auditeurs-années ont un écart, pour 1,51 milliard.** On y trouve par exemple 205 auditeurs sans aucune opération en 2018-2019. Ce n'est pas crédible comme dette : ACMA n'a pas enregistré tous les paiements, notamment les prises en charge par l'employeur. C'est la raison de la validation ligne par ligne.

### 8.2 Calcul d'une ligne candidate

Pour un dossier lié et une année :

1. **Inscriptions de l'année.** Avec deux niveaux la même année (M2 et dettes du M1), le dû est celui du niveau le plus élevé : ACMA n'encaisse pas deux barèmes pour une même année. Une ligne par (dossier, année).
2. **Dû** : `acma_baremes.du_total` de l'année et du niveau retenu.
3. **Versé** : somme des `total` des paiements de l'auditeur pour l'année, négatifs compris, puisqu'ils annulent.
4. **Écart** : dû moins versé.
   - S'il est positif, la ligne est **candidate**.
   - S'il est nul, rien.
   - S'il est négatif (trop-perçu), la ligne est listée sans être candidate (Q10).
5. **Avertissements**, affichés sur la ligne :
   - aucune opération dans l'année ;
   - ligne négative isolée ;
   - doublon exact ;
   - date de reçu aberrante ou hors de l'année ;
   - paiement sur un niveau sans inscription ;
   - redoublant (deuxième inscription au même niveau, barème complet par défaut : décision 5 du lot D) ;
   - versement de la plateforme daté de l'année (un paiement saisi deux fois ?).
6. **Empreinte de la ligne** : SHA-256 du dû, du versé et des identifiants des paiements. Elle fige ce que l'agent a examiné (§8.3).

`acma:arrieres` (sous-lot 15f) calcule les lignes une fois, sur l'archive gelée. Il est idempotent : une ligne déjà demandée, portée ou rejetée n'est pas recalculée.

### 8.3 Circuit de validation : une demande du lot 14c, ligne par ligne

`acma_arrieres` porte les colonnes suivantes : `uuid` ; dossier ; auditeur ; année ; niveau ; dû ; versé selon ACMA ; écart calculé ; avertissements (JSONB) ; empreinte ; **statut** (`a_verifier`, `demandee`, `portee`, `rejetee`, `en_attente_reinscription`) ; montant constaté ; motif ; pièce ; demande liée ; inscription et versement créés ; auteurs et dates.

| Étape | Qui | Geste |
|---|---|---|
| 1. Vérifier | Agent (`scolarite.arrieres_preparer`) | Ouvre la ligne : détail des paiements ACMA, avertissements, versements de la plateforme de l'auditeur. Il vérifie les pièces (bordereaux, prise en charge) et saisit le **montant constaté**, entre 1 et le dû, avec un motif d'au moins 10 caractères et une pièce facultative. |
| 2. Demander | Agent | « Demander l'entrée au compte » crée une demande `porter_arriere_acma` (lot 14c). Elle porte l'objet (la ligne), les paramètres `{"montant": …, "justificatif_path": …}` et l'empreinte. La ligne passe en `demandee`. |
| 2 bis. Rejeter | Agent ou responsable | « Pas d'arriéré » (paiement prouvé, prise en charge…) : motif obligatoire, la ligne passe en `rejetee`. Rien n'entre au compte. |
| 3. Décider | Responsable (`scolarite.arrieres_valider`), autre que le demandeur | Approuve ou rejette dans l'écran « Demandes ». Un aperçu montre l'effet calculé par le planificateur (§8.4). |
| 4. Exécuter | La plateforme, au nom du responsable | §8.4. En cas d'échec (garde-fou, empreinte changée), la demande passe en « échec d'exécution » avec la raison (règle 4 du lot 14c). |

Le type `porter_arriere_acma` :

- famille « validation » ;
- décideurs : `scolarite.arrieres_valider` ;
- sans visa du contrôle interne (Q9) ;
- délai de réponse : 72 h.

Migration :

- élargissement du `CHECK` des types de demandes ;
- `CHECK` de `parametres` : montant entier positif.

Elle reprend le précédent `accorder_moratoire` (A3).

**Quatre yeux obligatoires, pas d'action directe.** Contrairement à la règle générale du lot 14c, le titulaire de `scolarite.arrieres_valider` n'a pas de geste direct. Il doit approuver la demande d'un autre. Le service refuse donc une demande dont l'auteur est aussi le seul décideur possible, et l'écran l'annonce. Cette exception est motivée par la décision 3 et par la Q10 du lot D.

**Jamais en masse** :

- aucune action groupée sur l'écran, aucune commande d'entrée au compte ;
- un compte soumet au plus 10 demandes par heure (règle du lot 14c) ;
- un test vérifie que la table Filament n'a aucune `BulkAction`.

### 8.4 Entrée au compte (ADR-0013)

Service `ArrieresAcma::porter(ligne, montant, approbateur)`, appelé par l'exécution de la demande. Tout se passe dans `ImputationsCompte::sousVerrou(auditeur)`, verrou de l'auditeur pris le premier (R8) :

1. **Garde-fous**, relus sous verrou :
   - la ligne est `demandee` et son empreinte n'a pas changé ;
   - le dossier d'archive est toujours lié à cet auditeur ;
   - le dossier de l'auditeur n'est pas retiré ;
   - **l'auditeur a une inscription d'une année plus récente** (§8.5) ;
   - **aucune inscription n'existe pour l'année de l'arriéré** : sinon, on traite par une remise ou un versement ordinaire ;
   - le barème `baremes_scolarite` (année, niveau) existe ;
   - le montant est compris entre 1 et le dû du barème.
2. **Inscription de l'année passée** : `InscriptionAnnuelle`, aux montants copiés du barème (droits, 1re et 2e tranches). Elle est créée sans parcours ni inscription pédagogique, avec `acces_notifie_at` posé, ce qui empêche tout SMS « accès disponibles ».
3. **Versement de reprise**, si l'auditeur a payé une partie, c'est-à-dire si le dû moins le montant validé est positif :
   - `mode` = `reprise_acma`, `payeur` = `auditeur`, inscription désignée = celle de l'étape 2 ;
   - montant = dû moins montant validé ;
   - date = celle du dernier reçu ACMA valide de l'année, sinon la date d'approbation ;
   - `reference` = `ACMA-<année>-<uuid de la ligne>`, unique par l'index `(mode, reference)` ;
   - `saisi_par` = l'approbateur ;
   - puis `apresVersement` (R2).
4. **Imputation de l'avance** : `imputerAvance(auditeur, 'reprise_acma', approbateur)` (R3). Un nouveau déclencheur s'ajoute à `ImputationsCompte::DECLENCHEURS`. Une avance libre de l'auditeur comble d'abord l'arriéré, qui est le plus ancien (R1). C'est voulu (décision 1 du lot D).
5. **Ligne** : statut `portee`, avec l'inscription, le versement, l'approbateur et la date. Journal `scolarite`, événement `arriere_acma_porte`, avec le plan d'imputations décrit.
6. **Aucun envoi** : pas de SMS, pas de reçu, pas de `NotificationAcces`.

Changements du compte (sous-lot 15f, tests à l'appui) :

- **Versement réservé par son mode.**
  - `Versement::estReserve()` vaut vrai pour un payeur réservé, et aussi pour le mode `reprise_acma`. `EtatCompte` transmet ce drapeau au planificateur, qui reste pur.
  - Effet : le versement de reprise ne s'impute que sur son année (R2, passe des réservés). Il ne peut pas descendre sur une autre année, même si un arriéré plus ancien a déjà été porté.
- **Reçus.** `RecusVersements::raisonRefus` répond « Versement repris d'ACMA : pas de reçu », et `enAttente` exclut les modes `reprise` et `reprise_acma`.
- **Formulaires.** Le mode `reprise_acma` n'est jamais proposé aux agents, comme `reprise`. `Versement::libelleMode` affiche « Paiements repris d'ACMA ».
- **Annulation.** Annuler un versement de reprise reste un geste du responsable, avec motif (R4). Il touche une seule année, donc il n'est pas bloqué par la règle multi-années.

**Aperçu donné au décideur** (planificateur, sans écriture) :

- dû, versement de reprise, arriéré restant ;
- avance qui sera imputée ;
- et l'avertissement : « Les prochains versements de cet auditeur régleront d'abord cet arriéré (N FCFA), avant l'année en cours. »

### 8.5 Garde-fous et cas particuliers

- **Pas d'inscription plus récente** (un P13 non admis pas encore réinscrit, par exemple) :
  - la ligne passe en `en_attente_reinscription` (échec d'exécution motivé) ;
  - sinon, l'année passée deviendrait l'année courante, et l'accès, l'espace et les relances se calculeraient sur elle ;
  - la ligne se redemande après la réinscription du sous-lot D3.
- **Arriéré sur plusieurs années** (2024-2025 et 2025-2026) : deux lignes, deux demandes. L'ordre d'approbation est indifférent, puisque chaque versement de reprise est réservé à son année.
- **Annulation ultérieure d'un versement ordinaire.** Une fois un arriéré porté, un versement ordinaire peut s'imputer sur deux années. Son annulation exige alors la demande d'autorisation du sous-lot D4, qu'elle refuse en D1. **D4 est donc un prérequis de 15f**, comme il l'est de D3.
- **Affichage.** L'arriéré n'est visible au compte qu'avec le sous-lot D2 : relevé de compte, colonne « Arriérés », règle « en règle » des documents. **D2 est un prérequis de 15f.**
- **Erreur découverte après coup.** Arriéré trop élevé : remise motivée sur l'inscription passée (R5). Trop bas : nouvelle ligne impossible (une ligne par année), donc versement ou correction ordinaires. Aucune suppression.
- **Contrôle interne `ACMA_ARRIERES`** (mineur, quotidien, responsable de la scolarité) :
  - lignes `demandee` sans décision après l'échéance (déjà couvert par `DEM_ATTENTE`) ;
  - lignes `portee` dont l'inscription ou le versement ne correspond plus au montant validé ;
  - inscriptions d'années passées créées hors de ce circuit.

### 8.6 Exemples de référence (fictifs)

Barème M1 2025-2026 : 1 185 000 (185 000 + 500 000 + 500 000). Auditeur fictif A, inscrit en M2 2026-2027 (dû 1 135 000).

| Cas | Situation | Validé | Effet au compte |
|---|---|---|---|
| X1 | ACMA : 685 000 versés en 2025-2026 ; A n'a pas d'avance | 500 000 | Inscription M1 2025-2026 (1 185 000) ; versement de reprise 685 000 imputé sur elle ; arriéré 500 000 ; M2 inchangée |
| X2 | ACMA : aucune opération ; l'agent trouve un bordereau de 1 000 000 | 185 000 | Inscription (1 185 000) ; versement de reprise 1 000 000 ; arriéré 185 000 |
| X3 | Comme X1 ; A a une avance libre de 200 000 | 500 000 | Après R2, R3 impute 200 000 de l'avance sur l'arriéré : il reste 300 000, l'avance tombe à 0 |
| X4 | Comme X1 ; A n'a pas d'inscription 2026-2027 | 500 000 | Refusé : `en_attente_reinscription`, rien d'écrit |
| X5 | Comme X1 ; une inscription 2025-2026 existe déjà | 500 000 | Refusé (échec d'exécution) : à traiter par une remise ou un versement |
| X6 | Arriérés 2024-2025 (300 000) et 2025-2026 (500 000), approuvés dans l'ordre inverse | — | Chaque versement de reprise reste sur son année ; arriérés 300 000 et 500 000 |
| X7 | L'agent demande et approuve lui-même | — | Refusé (quatre yeux) |

## 9. Rapport des écarts 2026-2027

Commande `acma:ecarts-2026 --auteur= [--sortie=]`. Elle est en lecture seule sur le transit et sur la plateforme, et produit un classeur dans le stockage privé, téléchargeable avec `scolarite.export` depuis l'écran « Archives ACMA » (lien signé de 30 minutes, journalisé).

| Feuille | Contenu |
|---|---|
| Synthèse | Par promotion (P12 et antérieures, P13, P14) : lignes et totaux ACMA (colonne scolarité, 5 colonnes) ; versements valides et total de la plateforme ; écart |
| Absents de la plateforme | Paiements ACMA 2026-2027 sans versement correspondant. Rapprochement : même référence normalisée (`NumeroRecu` contre `reference`) ; sinon même total à 7 jours près ; sinon somme des lignes ACMA d'un même reçu contre un versement |
| Montants différents | Couples rapprochés par la référence dont les montants diffèrent |
| Absents d'ACMA | Versements de la plateforme sans ligne ACMA (information : ACMA n'est plus la référence) |
| Auditeurs hors plateforme | Inscriptions et paiements ACMA 2026-2027 d'auditeurs absents de la plateforme (3 de la P12 au 15/11 ; Q7) |
| Inscriptions | Inscriptions ACMA 2026-2027 sans inscription de la plateforme, et l'inverse, avec les écarts de niveau ou d'option |
| Notes 2026-2027 | Comptes par UE (`IPS03` : 240 ; `RAD35-4` : 1). Constat : grilles vides, aucune valeur, non reprises |

- **Rien n'est importé ni modifié.** La scolarité traite chaque ligne dans la plateforme par une saisie ordinaire.
- **Le classeur contient des données personnelles.** Il est remis par le stockage privé, jamais par courriel en pièce jointe.

## 10. Mécanique de reprise

### 10.1 Copie de transit : de MySQL à PostgreSQL

1. **Poste de l'USI, hors production.**
   - L'export est chargé dans un MariaDB jetable, comme le conteneur actuel, sans réseau sortant.
   - La connexion `acma_source` (`config/database.php`, variables `ACMA_DB_*`) n'est définie que sur ce poste.
2. **`php artisan acma:extraire --sortie=<dossier>`** :
   - **Tables lues** : celles du §3.1, hors exclusions.
   - **Colonnes exclues d'office** : `utilisateur` entière ; dans `etablissement`, `userftp`, `paswdftp`, `serverftp`, `repftp`, `keyplateforme` et `plateforme` ; `gpw*`. L'exclusion est une liste figée dans le code, et le test vérifie qu'aucune de ces colonnes n'apparaît dans la sortie.
   - **Format** : un CSV par table (UTF-8, RFC 4180, `\N` pour NULL, binaire en hexadécimal, dates en texte brut tel que MySQL les rend, `0000-00-00` compris).
   - **Manifeste** `manifeste.json` :
     - source : nom du fichier d'export, SHA-256, date de l'export, version du serveur ;
     - par table : colonnes, types MySQL, lignes, SHA-256 du CSV ;
     - exclusions.
   - **Sortie** : une archive chiffrée (AES-256, phrase de passe tenue par l'USI hors du dépôt), et le dossier en clair est supprimé.
3. **Transfert** : `scp` vers le serveur, dans un dossier privé de l'utilisateur `pssfp`.
4. **`php artisan acma:charger <archive> --auteur=`** (production) :
   - déchiffre dans un dossier temporaire privé et vérifie chaque SHA-256 et le nombre de lignes du manifeste ;
   - **remplace** le schéma : `DROP SCHEMA IF EXISTS acma_transit CASCADE`, `CREATE SCHEMA acma_transit`, une table par CSV, **toutes les colonnes en `text`**, puis `COPY … FROM STDIN` (`pgsqlCopyFromArray`) ;
   - note le chargement dans `acma_transit._chargement` (manifeste et date) ;
   - supprime les fichiers en clair, même en cas d'échec ;
   - journalise `transit_charge`.
5. **Rejouable** : un nouvel export suit le même chemin et remplace le transit. L'archive, elle, se met à jour par `acma:reprendre` (§10.4).

Pourquoi tout en texte : ACMA contient des dates impossibles (`2021-06-00`), des années de 1 à 2230 et des montants en flottant. Toute conversion est faite par la reprise, explicitement, et chaque échec est compté et listé. C'est la leçon de `20_copie_acma.py`.

Alternatives écartées (ADR-0014) : pgloader, connexion MySQL depuis la production, ou reprise faite en local puis `pg_dump` des tables `acma_*`. Cette dernière ne verrait pas les auditeurs de la production au moment du rapprochement.

### 10.2 Commandes

| Commande | Où | Effet | Défaut |
|---|---|---|---|
| `acma:extraire --sortie=` | Poste USI | CSV et manifeste chiffrés | — |
| `acma:charger <archive> --auteur=` | Serveur | Schéma `acma_transit` | — |
| `acma:inventaire` | Serveur | Comptes du §3, sans donnée nominative | Lecture seule |
| `acma:reprendre --auteur= [--etapes=…] [--appliquer]` | Serveur | Archive `acma_*` et contrôles | **À blanc** |
| `acma:controler` | Serveur | Contrôles du §10.5 sur l'archive en place | Lecture seule |
| `acma:ecarts-2026 --auteur=` | Serveur | Rapport du §9 | Lecture seule |
| `acma:geler --auteur=` | Serveur | Gel de l'archive | Demande confirmation |
| `acma:arrieres --auteur= [--appliquer]` | Serveur | Lignes candidates (§8.2) | **À blanc** |
| `acma:purger-transit --auteur=` | Serveur | Supprime le schéma de transit (après le gel) | Demande confirmation |

À blanc : la commande fait tout dans une transaction, affiche les comptes et les contrôles, puis **annule**. Avec `--appliquer`, elle valide la transaction si aucun contrôle bloquant n'échoue, sinon elle annule tout.

La console n'affiche que des comptes. Les listes nominatives (anomalies, à arbitrer) vont dans des classeurs du stockage privé (§6.4).

### 10.3 Ordre des étapes de `acma:reprendre`

| N° | Étape | Lit | Écrit | Sous-lot |
|---:|---|---|---|---|
| 0 | Préalables : transit chargé, manifeste vérifié, archive non gelée, codes de niveau connus | `_chargement` | — | 15b |
| 1 | `referentiels` : années, programmes en unités | `anneeacademic`, `programe`, `ue`, `grpue`, `niveau` | `acma_annees`, `acma_unites` | 15b |
| 2 | `dossiers` : normalisation, exclusions, fusions | `etudiant`, `inscription_academic` | `acma_dossiers` | 15b |
| 3 | `rapprochement` : cas R1 à R6, liens automatiques | `acma_dossiers`, `candidatures`, `auditeurs` | `acma_dossiers` (lien), `acma_rapprochements` | 15b |
| 4 | `inscriptions` : inscriptions datées jusqu'en 2025-2026 | `inscription_academic` | `acma_inscriptions` | 15b |
| 5 | `resultats` : notes, reconstitution depuis l'historique, recalculs, décisions d'archive | `notes`, `notes_hist`, `programe` | `acma_resultats_ue`, `acma_notes_non_rattachees`, `acma_inscriptions` (recalculs) | 15b |
| 6 | `versions_notes` (Q5) | `notes_hist` | `acma_versions_notes` | 15b |
| 7 | `baremes`, `paiements` : drapeaux | `configurationdu`, `du` | `acma_baremes`, `acma_paiements` | 15c |
| 8 | `soutenances`, `documents` | `pvsoutenance`, `certificat`, `attestationr`, `misedispos`, `nonperceptionb`, `prisecours` | `acma_soutenances`, `acma_documents` | 15c |
| 9 | `controles` (§10.5), puis la ligne `acma_reprises` et le journal | tout | `acma_reprises` | 15b, 15c |

- Une étape peut se lancer seule (`--etapes=paiements`) si les précédentes sont en place.
- Durée estimée : quelques minutes pour environ 380 000 lignes. Lecture par lots de 1 000 et `upsert` par clé naturelle, dans une seule transaction.

### 10.4 Idempotence et export plus récent

- Chaque ligne d'archive porte la clé naturelle d'ACMA et `empreinte_source` (SHA-256 de la ligne source normalisée).
- **Même export, seconde exécution** : zéro écriture. Le test le vérifie (comptes « inchangées » = total).
- **Export plus récent** :
  - **ligne nouvelle** : insérée ;
  - **empreinte différente** : mise à jour, comptée et listée (« modifiées depuis la reprise du … ») ;
  - **ligne disparue de la source** : `retire_de_la_source_at` posé, jamais supprimée, comptée et listée ;
  - **liens et arbitrages** : conservés ; un lien automatique dont l'identité ACMA a changé repasse « à arbitrer », avec la raison.
- **Après le gel** : refus, avec le message « Archive gelée le … ».

### 10.5 Contrôles d'équivalence

Les contrôles comparent le transit (source) et l'archive, une fois les exclusions journalisées retirées. **Bloquant** : un écart non nul annule `--appliquer`. Valeurs attendues pour l'export du 15/11 :

| N° | Contrôle | Attendu (15/11) | Nature |
|---|---|---|---|
| C01 | Dossiers = matricules normalisés distincts des fiches retenues, moins exclusions et fusions | environ 2 441 (2 443 − `zz` − 1 fusion) | Bloquant |
| C02 | Inscriptions par année et niveau = triplets distincts de la source (année jusqu'en 2025-2026) | 4 673 au total, détail par année et niveau | Bloquant |
| C03 | Admis par année et niveau | 2 184 au total (tableau du §3.3) | Bloquant |
| C04 | Notes par année : lignes de `notes` reprises ; plus les reconstituées ; plus les non rattachées ; conflits listés | 49 746 + 2 468 + 1 510 couples | Bloquant (C04a, notes) ; informatif (C04b, C04c) |
| C05 | Crédits acquis recalculés contre `Totalcredit` et contre la décision enregistrée, par cohorte | Écarts connus : cohortes du §3.3, 2024-2025 | Informatif |
| C06 | Paiements par année : nombre de lignes, colonne scolarité, total des 5 colonnes | 7 308 ; 2 943 217 772 ; 3 446 267 773 (détail du §3.5) | Bloquant |
| C07 | Soutenances et documents ACMA | 801 ; 197 + 2 + 5 + 31 + 102 | Bloquant |
| C08 | Rapprochement : liés, à arbitrer, absents d'ACMA ; matricules `25…` de la plateforme | Environ 295 liés ; 8 absents selon le constat du 15/11 | Informatif |
| C09 | **Rien d'autre n'a changé** : comptes de `auditeurs`, `candidatures`, `users`, `inscriptions_annuelles`, `versements`, `imputations_versements`, `recus_versements`, `documents_delivres`, `acquis_ue`, `notes_ecue` identiques avant et après | 0 écart | Bloquant |
| C10 | **Aucun envoi** : tables `jobs`, `failed_jobs`, `notifications`, `envois_messages` identiques ; aucun appel HTTP sortant | 0 écart | Bloquant |
| C11 | Années : seules les années jusqu'en 2025-2026 ; aucune donnée 2026-2027 dans l'archive | 0 | Bloquant |
| C12 | Anomalies par catégorie (dates, négatifs, doublons, collisions, conflits) | Comptes du §3 | Informatif |

Le rapport (`controles-AAAAMMJJ.md`, sans donnée nominative) est joint à la PR de chaque sous-lot et à la décision de gel.

### 10.6 Répétition et bascule

1. **Répétition sur une copie locale de la production** :
   - restauration de la sauvegarde de la nuit sur le poste de l'USI, puis `acma:charger`, `acma:reprendre --appliquer` et `acma:controler` ;
   - on mesure la durée et on relit les contrôles et les listes à arbitrer avec la scolarité ;
   - on la refait jusqu'à ce que tous les contrôles bloquants soient verts.
2. **Jour J0, arrêt d'ACMA** :
   - la scolarité cesse toute saisie ; les comptes d'écriture d'ACMA sont fermés ;
   - l'export final est fait ce jour-là.
3. **Production** :
   - `acma:charger` sur l'export final ;
   - `acma:reprendre` à blanc, puis `--appliquer` ;
   - `acma:controler` ;
   - remise du rapport des écarts (§9) ;
   - `acma:geler`.
4. **Après le gel** :
   - écrans de consultation et documents (15d, 15e) ;
   - arriérés (15f) et acquis (15g), selon leurs prérequis ;
   - retrait de l'export « Importation ACMA (Excel) » des campagnes (`ExportsRetenusActions::acma`) ;
   - `acma:purger-transit` ;
   - conservation de l'export chiffré (Q14) ;
   - désinstallation d'ACMA quand la scolarité a validé l'archive.

## 11. Découpage en sous-lots

Chaque sous-lot a sa branche, ses tests, sa PR et se déploie seul.

**Ordre** :

```
15a → 15b → 15c → (J0, gel) → 15d ─┬─> 15e
                                   ├─> 15f (après D2 et D4)
                                   └─> 15g (après D3)
                         15h : répétition avant J0, bascule à J0, retrait d'ACMA à la fin
```

### 15a — Transit et inventaire

**Prérequis** : ADR-0014 accepté. **Livre** : le chemin MySQL vers PostgreSQL, rejouable, et l'inventaire du §3.

1. Connexion `acma_source` (poste USI) et commande `acma:extraire` : CSV, manifeste, exclusions figées, chiffrement.
2. Commande `acma:charger` : schéma `acma_transit` tout en texte, `COPY`, vérification du manifeste, `_chargement`, purge des fichiers en clair.
3. Commande `acma:inventaire` : comptes du §3, sans donnée nominative.
4. Jeu d'essai fictif : une petite base ACMA de test, construite par une fabrique, avec les pièges du §3 (dates, négatifs, `zz`, doublon, grille vide).
5. Tests Pest du §14.1.
6. Procédure d'exploitation `docs/ops/reprise-acma.md` : conteneur jetable, chiffrement, transfert, purge.

### 15b — Archive académique

**Prérequis** : 15a. **Livre** : dossiers, rapprochement, inscriptions, résultats et notes, contrôles C01 à C05 et C08 à C11.

1. Migration : `acma_reprises`, `acma_annees`, `acma_unites`, `acma_dossiers`, `acma_inscriptions`, `acma_resultats_ue`, `acma_notes_non_rattachees`, `acma_versions_notes`, `acma_rapprochements` ; contraintes, index, déclencheurs de lecture seule et d'ajout seul.
2. Classes pures : `NormalisationAcma` (matricule, dates, sexe, jetons, intitulés bilingues, semestre) et `ComparaisonIdentite`.
3. Commande `acma:reprendre`, cadre commun : à blanc, transaction, mode reprise, `upsert` par lots, empreintes, comptes.
4. Étapes `referentiels`, `dossiers` et `rapprochement`, avec le classeur des cas à arbitrer.
5. Étapes `inscriptions`, `resultats` (reconstitution, recalculs, décision d'archive) et `versions_notes`.
6. Contrôles académiques et rapport `controles-AAAAMMJJ.md`.
7. Tests Pest du §14.2.

### 15c — Archive financière et documentaire, écarts 2026-2027, gel

**Prérequis** : 15b. **Livre** : paiements, barèmes, soutenances, documents d'ACMA, rapport des écarts, gel.

1. Migration : `acma_baremes`, `acma_paiements`, `acma_soutenances`, `acma_documents`, avec leurs déclencheurs.
2. Étapes `baremes` et `paiements` (drapeaux, contreparties des négatifs), puis `soutenances` et `documents` (lecture des dates en texte, mentions).
3. Contrôles C06, C07 et C12.
4. Commande `acma:ecarts-2026` et son classeur.
5. Commandes `acma:geler` et `acma:purger-transit`.
6. Tests Pest du §14.3.

### 15d — Consultation, rapprochement et compléments

**Prérequis** : 15c en production, archive gelée. **Livre** : les écrans d'administration.

1. Migration des permissions (§7.10) et module « Archives ACMA » dans `ModulePermissionMap`, matrice `roles:matrice` mise à jour.
2. Ressource Filament « Archives ACMA » :
   - liste avec recherche par matricule, nom, année et niveau, et filtres « lié », « à arbitrer », « décision non enregistrée » ;
   - fiche en onglets : identité, années et résultats, notes, paiements, soutenances, documents.
3. Onglet « Historique ACMA » de la fiche auditeur, quand un dossier est lié.
4. Table `acma_complements` (ajout seul) et action « Compléter » : date de naissance, décision transcrite d'un PV, choix en cas de conflit.
5. Action « Arbitrer le rapprochement » (lier, ne pas lier, écarter), classeurs téléchargeables.
6. Journal `archives`, contrôle informatif `SEC_SEPARATION` (§7.10), tests du §14.4.

### 15e — Documents historiques

**Prérequis** : 15d. **Livre** : relevés et attestations vérifiables.

1. Migration de `documents_delivres` (§7.8), avec `down()` refusé si un document historique existe.
2. Gabarits PDF (relevé, attestation) : en-tête bilingue, mention de la source ACMA, QR code.
3. Service `DocumentsHistoriques` : préalables, numérotation `RH-` et `AH-`, réutilisation du scellement de `DocumentsOfficiels`.
4. Actions sur la fiche d'archive et sur l'onglet de l'auditeur lié ; liste des documents délivrés.
5. Vérification publique : libellés des nouveaux types, sans autre changement.
6. Tests Pest du §14.5.

### 15f — Arriérés

**Prérequis** : 15c gelé, 15d ; **D2** (compte visible) et **D4** (annulation sur plusieurs années) en production ; Q8 à Q11 tranchées. **Livre** : l'entrée au compte, ligne par ligne.

1. Migration : `acma_arrieres` et son déclencheur d'états ; barèmes 2024-2025 et 2025-2026 dans `baremes_scolarite` (Q8) ; type de demande `porter_arriere_acma` (`CHECK` des types et des `parametres`).
2. Commande `acma:arrieres` : lignes candidates, empreintes, avertissements.
3. Écran « Arriérés ACMA » (Scolarité) : liste filtrable, fiche de ligne, actions « Demander l'entrée au compte » et « Pas d'arriéré ». Aucune action groupée.
4. Compte : versement réservé par le mode `reprise_acma` (`Versement`, `EtatCompte`) ; exclusion des reçus ; déclencheur `reprise_acma` de R3.
5. Service `ArrieresAcma::porter` : exécution de la demande sous verrou, garde-fous du §8.4, aperçu par le planificateur.
6. Contrôle interne `ACMA_ARRIERES`.
7. Tests Pest du §14.6, dont les cas X1 à X7.

### 15g — Acquis des redoublants

**Prérequis** : 15b gelé ; maquette M1 2026-2027 validée ; **D3** (réinscription) ; Q13 tranchée. **Livre** : le pont vers le lot 6.

1. Migration `acma_correspondances` (à états, avec validation).
2. Proposition automatique des correspondances par intitulé normalisé, pour les programmes M1 2024-2025 et 2025-2026.
3. Écran de validation des correspondances (`lmd.maquette_valider`).
4. Calcul des acquis proposés par redoublant réinscrit, puis validation ligne par ligne vers `acquis_ue` (origine `correspondance`).
5. Vérification que la délibération du lot 6 capitalise l'acquis une seule fois.
6. Tests Pest du §14.7.

### 15h — Bascule et retrait d'ACMA (exploitation)

**Prérequis** : 15a à 15c ; Q14 tranchée. **Livre** : l'arrêt d'ACMA.

1. Répétitions sur une copie locale de la production (§10.6), jusqu'à des contrôles verts ; relecture des listes avec la scolarité.
2. Choix et annonce de J0. Création dans la plateforme des auditeurs actifs absents (Q7), avant ou après J0, par la réinscription depuis l'archive ([réinscription des anciens](module-4-lot15-reinscription-anciens.md), §7.7).
3. J0 : arrêt de la saisie, fermeture des comptes d'écriture d'ACMA, export final.
4. Production : charger, reprendre à blanc puis appliquer, contrôler, remettre le rapport des écarts, geler.
5. Retrait de l'export « Importation ACMA (Excel) » des campagnes (petite PR de code).
6. Purge du transit ; conservation de l'export chiffré et du manifeste (Q14) ; changement des secrets exposés (§13).
7. Validation de l'archive par la scolarité, puis désinstallation d'ACMA et de son serveur.

## 12. Cas à arbitrer par le chef de projet

**Tranchés le 15/11/2026.** Le chef de projet retient la recommandation de chaque question. Pour Q4, la délibération finale du M2 2025-2026 (P12) **a eu lieu et son PV est signé** : ses décisions seront transcrites par complément (option a), comme en Q3. Pour Q7, les 3 auditeurs de la P12 actifs en 2026-2027 sont créés sur la plateforme avant J0. Le tableau ci-dessous garde les options proposées.

**Modifiés le 16/11/2026** ([réinscription des anciens](module-4-lot15-reinscription-anciens.md)) : **Q7** — les auditeurs actifs absents de la plateforme (3 de la P12 et 1 de la P13 au 16/11) sont créés par la réinscription depuis l'archive, **matricule ACMA conservé**, et non plus par « Ajouter un retenu », qui attribuerait un nouveau matricule ; **Q16** — option **b)** retenue et élargie : téléphones, e-mails, adresse, région et toutes les colonnes de `etudiant` sont repris (décision 3 du 16/11), sans aucun envoi vers ces coordonnées.

| N° | Question | Options | Recommandation |
|---|---|---|---|
| **Q1** | Les 95 fiches sans aucune inscription datée (aucune note, aucun paiement) sont-elles reprises ? | a) toutes ; b) aucune, la liste est remise ; c) seulement celles qui ont un document (1 PV, 1 certificat) | **c)** Les autres n'ont aucun contenu académique : les reprendre ajouterait des données personnelles sans usage (minimisation). La liste reste dans l'export chiffré. |
| **Q2** | La fiche « zz » (1 inscription et 1 paiement en 2025-2026) | a) exclue et listée ; b) reprise comme les autres | **a)** Le préfixe marque une fiche neutralisée dans ACMA, et son corps n'est pas un matricule valide. La scolarité confirme sur la liste. |
| **Q3** | Cohortes sans décision enregistrée (M1 2022-2023, M2 2025-2026, FFCP 2024-2025, M2 2017-2018, 2013 à 2016) | a) afficher la décision recalculée ; b) « non enregistrée », transcription d'après le PV signé par complément, attestations bloquées d'ici là ; c) recalcul validé en masse | **b)** Recalculer serait refaire une délibération (décision 1). Le PV signé fait foi. Le recalcul s'affiche à côté, comme indicatif, pour aider la transcription. |
| **Q4** | Délibération finale du M2 2025-2026 (P12) : aucun admis enregistré dans ACMA, et 169 soutenances datées de 2026. A-t-elle eu lieu ? | a) déjà tenue sur papier : transcription par complément (Q3) ; b) pas encore tenue : à faire dans ACMA avant J0 ; c) pas encore tenue : hors système, puis transcription | **À confirmer d'abord.** Si elle n'a pas eu lieu : **c)**, pour ne pas retarder J0. Le module LMD ne porte pas les maquettes 2025-2026. |
| **Q5** | Reprendre les 309 580 versions de notes (`notes_hist`) ? | a) oui, en archive consultable ; b) seulement les 2 468 notes reconstituées, le reste dans l'export chiffré | **a)** C'est la seule trace des notes écrasées et des modifications en cas de litige, et ACMA disparaît. Volume modeste (environ 40 Mo). |
| **Q6** | 1 510 notes dont le programme a été supprimé dans ACMA (année inconnue) | a) archivées « non rattachées », visibles, hors relevés ; b) ignorées | **a)** Elles peuvent répondre à une contestation. Elles n'entrent pas dans les crédits ni dans les documents. |
| **Q7** | Auditeurs actifs en 2026-2027 dans ACMA mais absents de la plateforme (3 de la P12 au 15/11) | a) créés dans la plateforme avant J0 (« Ajouter un retenu », inscription 2026-2027), au cas par cas ; b) laissés hors plateforme | **a)** La plateforme fait foi pour 2026-2027 (décision 2), et ces auditeurs paient et suivent des cours. **Modifiée le 16/11/2026** : créés par la réinscription depuis l'archive, matricule ACMA conservé ([réinscription des anciens](module-4-lot15-reinscription-anciens.md), §7.7). |
| **Q8** | Barèmes des années passées pour le compte (2024-2025, 2025-2026) : quelle répartition du total ? | a) celle de 2026-2027 (M1 185 000 / 500 000 / 500 000 ; M2 135 000 / 500 000 / 500 000) ; b) celle d'ACMA (50 000 + frais annexes / 1 000 000) | **a)** Totaux identiques. Les paliers d'une année passée ne commandent rien, et la répartition reste homogène pour les relevés de compte. |
| **Q9** | Visa du contrôle interne sur l'entrée d'un arriéré ? | a) non, l'agent demande et le responsable approuve ; b) oui | **a)** La décision 3 confie la validation à la scolarité, et les quatre yeux sont garantis. `ACMA_ARRIERES` et le journal permettent un contrôle a posteriori. |
| **Q10** | Trop-perçus des années passées (11 P13 en M1 2025-2026) | a) listés seulement ; b) portés en avance après validation | **a)** Porter une avance, c'est faire entrer de l'argent non vérifié au compte, et il pourrait payer 2026-2027. Un trop-perçu prouvé s'enregistre comme un versement ordinaire, pièce à l'appui. |
| **Q11** | Écarts de paiement des anciens auditeurs (sans compte) : comment les afficher ? | a) « écart selon ACMA (non vérifié) », par année ; b) masqués ; c) affichés comme arriérés | **a)** Information utile pour une demande de document, mais 1,5 milliard d'écarts douteux ne doit pas passer pour une créance. |
| **Q12** | Documents historiques : lesquels, et sous quelle forme ? | a) relevé et attestation de réussite, notes sur 20 sans grade ; b) ajouter une attestation d'inscription passée ; c) avec grades et mentions | **a)** Ce sont les demandes courantes, et la grille d'ACMA est fausse. b) peut venir ensuite sans changer le modèle. Signataire : celui des documents du lot 7. |
| **Q13** | Acquis des redoublants P13 : à quelle maille ? | a) une UE d'accueil est acquise si la moyenne des UE ACMA correspondantes, pondérée par les crédits, atteint 12 et que toutes ses ECUE sont couvertes ; b) acquis par ECUE (nouvelle règle, change les lots 5 et 6) ; c) aucune capitalisation | **a)** Cohérent avec la règle du lot 4 (note d'UE = moyenne des ECUE pondérée par les crédits) et sans nouvelle règle LMD. Validé ligne par ligne. |
| **Q14** | Conservation de l'export ACMA et du transit | a) transit purgé après le gel ; export et manifeste chiffrés, gardés hors ligne par l'USI sans limite de durée (registre de scolarité) ; b) export détruit après validation de l'archive | **a)** L'archive est une sélection. L'export brut, avec le journal d'ACMA, peut seul trancher un litige futur. Hors ligne et chiffré, il n'expose rien. |
| **Q15** | L'espace des P13 doit-il afficher leur M1 2025-2026 archivé (« Mes résultats ») ? | a) non dans le lot 15 ; b) oui, en lecture seule | **a)** La décision 4 limite l'archive à l'administration. On rouvrira la question après validation de l'archive par la scolarité. |
| **Q16** | Téléphones des anciens (2 056 fiches ; ACMA n'a ni e-mail ni pièce exploitable) | a) non repris ; b) repris, sans usage d'envoi | **a)** Aucun envoi n'est prévu (décision 4), et les numéros sont anciens. Minimisation : ils restent dans l'export chiffré. **Décision du 16/11/2026 : b), élargie à toutes les coordonnées** ([réinscription des anciens](module-4-lot15-reinscription-anciens.md), §4). |

## 13. Sécurité et données personnelles

- **Rien de réel dans le dépôt** : ni l'export, ni le transit, ni les classeurs. Ils vivent :
  - sur le poste de l'USI (conteneur jetable, dossier privé) ;
  - en transit chiffré ;
  - sur le serveur, dans le schéma `acma_transit` puis dans les tables de l'archive.

  Les specs, les commits, les PR et les sorties de console ne portent que des comptes. Les tests utilisent des données fictives.
- **Secrets d'ACMA** :
  - les mots de passe en clair (`utilisateur`, 4 comptes), l'identifiant et le mot de passe FTP et la clé de plateforme (`etablissement`) ne sont jamais extraits ;
  - **à faire au J0** :
    - prévenir les 4 titulaires de changer ce mot de passe s'ils l'utilisent ailleurs ;
    - faire changer le mot de passe du compte FTP par son gestionnaire, puisqu'il figure dans chaque export d'ACMA depuis des années.
- **Minimisation** : seule l'identité utile aux documents est reprise (§6.2). Les téléphones et le journal d'ACMA ne sont pas repris (Q14, Q16). **Levée le 16/11/2026 pour les données des auditeurs** (coordonnées comprises, lecture réservée à `archives.consulter`, jamais dans le journal ni les classeurs) ; restent exclus les secrets et les données du personnel d'ACMA ([réinscription des anciens](module-4-lot15-reinscription-anciens.md), §4).
- **Accès** :
  - l'archive n'est lisible qu'avec `archives.consulter` ;
  - aucune route d'API ni page publique, hormis la vérification des documents, qui n'affiche que le minimum du lot 7 ;
  - les classeurs se téléchargent par un lien signé de 30 minutes, avec une permission, et le téléchargement est journalisé.
- **Aucun envoi aux anciens** : garanti par construction (aucun service d'envoi appelé) et vérifié par les tests et par C10.
- **Intégrité** :
  - archive en lecture seule puis gelée (déclencheurs) ;
  - compléments et arbitrages en ajout seul ;
  - journal scellé (14d-1) pour les gestes ;
  - empreinte du manifeste dans `acma_reprises`.
- **Argent** : l'entrée d'un arriéré passe par le seul circuit du §8, sous les garde-fous de l'ADR-0013. `CPT_COHERENCE` le vérifie.
- **Sauvegardes** :
  - le transit entre dans les sauvegardes quotidiennes entre le chargement et la purge, et cette fenêtre doit rester courte ;
  - les tables de l'archive y entrent durablement, comme toute donnée de scolarité.
- **Base légale et durée** : la loi n° 2024/017 sur les données personnelles s'applique. L'archive de scolarité est conservée sans limite de durée (registre), comme les relevés papier : à confirmer avec la direction (Q14).

## 14. Plan de tests (Pest)

Données fictives seulement. Une fabrique `TransitAcma` construit un petit schéma `acma_transit` dans la base de test, à l'intérieur de la transaction de `RefreshDatabase` (`CREATE SCHEMA` est transactionnel sous PostgreSQL).

### 14.1 Transit (15a)

- L'extraction n'écrit aucune colonne ni table exclue. Elle prend en entrée une source factice derrière une interface `SourceAcma`, pour ne pas dépendre de MySQL en CI.
- Manifeste : un SHA-256 ou un nombre de lignes altéré fait refuser le chargement, et le schéma précédent reste intact.
- Toutes les colonnes chargées sont en `text`. `0000-00-00`, `2021-06-00` et les montants en flottant passent sans erreur.
- Un second chargement remplace le transit, et les fichiers en clair sont supprimés même après un échec.
- `acma:inventaire` ne sort que des nombres. Un test cherche, dans sa sortie, les noms et matricules fictifs du jeu d'essai, et doit n'en trouver aucun.

### 14.2 Archive académique (15b)

- Tables de cas pour `NormalisationAcma` :
  - matricules : casse, espaces, tiret, `zz`, 7, 8 et 9 caractères, zéros de tête conservés ;
  - dates : invalide, aberrante, « né vers » ;
  - sexe : `H` lu `M` ;
  - intitulés bilingues ;
  - semestre recodé.
- `ComparaisonIdentite` : cas R1 à R6, seuil de 0,66, accents, ordre des jetons.
- Reprise du jeu d'essai :
  - comptes par étape ;
  - fusion du doublon ; exclusions ; 2026-2027 absente (C11) ;
  - note écrasée reconstituée depuis l'historique ;
  - conflit visible et exclu des crédits ;
  - décision `non_enregistree` pour une cohorte de la liste ;
  - moyenne enregistrée prioritaire, recalcul indicatif.
- **Idempotence** : une seconde exécution n'écrit rien. Un export modifié produit une ligne mise à jour et une ligne marquée retirée, sans aucune suppression.
- **À blanc** : rien n'est écrit, et le rapport est produit.
- **Lecture seule** :
  - une écriture hors du mode reprise est refusée par la base, `TRUNCATE` aussi ;
  - après le gel, l'écriture est refusée même en mode reprise ;
  - les compléments et les arbitrages refusent `UPDATE` et `DELETE`.
- **C09 et C10** : modèle vivant inchangé, aucun envoi (`Notification::fake`, `Queue::fake`, `Http::fake`, `assertNothingSent`).
- **Rapprochement** : un lien automatique pour R1 et R2, aucun lien pour R3, R4 et R6, et le classeur des cas est produit.

### 14.3 Archive financière, écarts, gel (15c)

- Drapeaux des paiements : négatif apparié ou isolé, total nul, date future ou ancienne, hors année, doublon exact, niveau sans inscription.
- Sommes par année identiques à la source (C06), au franc près. Une décimale bloque l'étape.
- Lecture des dates de soutenance (variantes de mois), normalisation des mentions, plusieurs PV par dossier.
- Écarts 2026-2027 : chaque catégorie du §9 sur un jeu fictif (même référence, même total à 7 jours, somme d'un reçu, absent, montant différent, auditeur hors plateforme, grille de notes vide).
- `acma:geler` refusé si un contrôle bloquant échoue ; `acma:purger-transit` refusé avant le gel.

### 14.4 Consultation (15d)

- Permissions :
  - sans `archives.consulter`, la page est refusée (403) et absente du menu ;
  - l'agent consulte mais ne complète ni n'arbitre ;
  - le responsable fait tout.
- Smoke Filament : liste, fiche, onglet de l'auditeur lié (absent sans lien).
- Compléments : motif obligatoire ; affichage « transcrit du PV n° … » ; le dernier complément l'emporte et l'historique reste visible.
- Arbitrage : lier, ne pas lier, écarter, avec motif ; journal `archives`.

### 14.5 Documents historiques (15e)

- `CHECK` sur le propriétaire : un document historique sans `acma_inscription_id` est refusé, un document du lot 7 sans inscription annuelle aussi.
- Préalables : relevé refusé sans date de naissance ou avec un conflit non arbitré ; attestation refusée si la décision n'est pas `admis`.
- Numérotation `RH-` et `AH-`, empreinte, même fichier au second téléchargement.
- Vérification publique : aucune note ni aucun matricule ; « révoqué » après révocation.
- `down()` refusé si un document historique existe.

### 14.6 Arriérés (15f)

- Calcul :
  - dû du niveau le plus élevé quand l'auditeur a deux niveaux la même année ;
  - négatifs compris dans le versé ;
  - trop-perçu listé, non candidat ;
  - avertissements ;
  - aucune ligne pour un dossier non lié.
- Circuit :
  - l'agent demande, le responsable approuve, et la ligne est portée ;
  - même personne : refus ;
  - titulaire de `scolarite.arrieres_valider` sans demande : aucune action ;
  - aucune `BulkAction` sur l'écran ;
  - demande rejetée : rien au compte.
- Exécution, cas X1 à X7 du §8.6 :
  - inscription aux montants du barème ;
  - versement de reprise réservé, imputé sur son année seulement ;
  - R3 sur l'avance ;
  - refus sans inscription plus récente, ou si l'année existe déjà ;
  - empreinte changée : échec d'exécution.
- Compte :
  - planificateur, test de propriété : un versement réservé par son mode ne touche jamais une autre inscription ;
  - `CPT_COHERENCE` vert après chaque cas ;
  - `RecusVersements` : aucun reçu ni attente pour `reprise_acma` ;
  - aucun SMS ni notification.
- Contrôle `ACMA_ARRIERES` : ligne portée incohérente détectée ; inscription passée créée hors circuit détectée.

### 14.7 Acquis des redoublants (15g)

- Proposition des correspondances par intitulé ; validation obligatoire avant tout calcul.
- Note d'UE d'accueil pondérée par les crédits ; UE non acquise si une ECUE n'est pas couverte.
- `acquis_ue` d'origine `correspondance` écrit après validation, une seule fois.
- La délibération du lot 6 capitalise l'UE une seule fois.

## 15. Risques majeurs

| Risque | Gravité | Parade |
|---|---|---|
| **Paiements d'ACMA incomplets** : 1,51 milliard d'écarts, dont 205 auditeurs sans aucune opération en 2018-2019, et 82 P13 sans opération en M1 2025-2026. Une entrée en masse créerait des dettes fausses. | Haute | Validation ligne par ligne, quatre yeux, pièces, aucun geste groupé (§8) ; écarts des anciens affichés comme « non vérifiés » (Q11) |
| **Décisions absentes** pour plusieurs cohortes, dont le M2 2025-2026 en cours (P12) | Haute | Pas de recalcul comme décision ; transcription des PV signés (Q3) ; question préalable sur la délibération P12 (Q4) |
| **Rapprochement erroné** : les notes d'une personne sur la fiche d'une autre | Haute | Lien automatique seulement sur matricule, identité et date concordants ; le reste s'arbitre (§6.3) |
| **Effet de l'arriéré sur l'accès** : R1 envoie les versements suivants sur l'arriéré d'abord, ce qui peut retarder les droits de l'année en cours | Moyenne | Aperçu chiffré avant approbation ; prérequis D2 (visibilité) et D4 (annulation sur plusieurs années) ; refus sans inscription plus récente |
| **Double saisie** tant qu'ACMA tourne (145 paiements, grilles de notes ouvertes en novembre) | Moyenne | J0 rapproché (15h) ; rapport des écarts ; export final à J0 |
| **Notes perdues** par l'unicité d'ACMA : 2 468 reconstituées, 1 510 sans année | Moyenne | Reconstitution depuis l'historique ; notes non rattachées visibles (Q6) ; export brut conservé (Q14) |
| **Identités incomplètes** (449 dates de naissance inexploitables) : documents bloqués | Moyenne | Complément d'après le dossier papier, à la demande |
| **Données personnelles** dans le transit et les sauvegardes | Moyenne | Fenêtre courte, purge, chiffrement en transit, aucune donnée dans le dépôt (§13) |
| **Secrets d'ACMA exposés** (FTP, mots de passe) dans tous les exports existants | Moyenne | Non extraits ; changement au J0 (§13) |

## 16. Hors périmètre

- Accès des anciens auditeurs à un espace en ligne (décision 4), sauf l'ancien réinscrit dans l'année en cours ([réinscription des anciens](module-4-lot15-reinscription-anciens.md)).
- Recalcul ou nouvelle délibération des années passées, PV d'archive dans le LMD (décision 1).
- Reprise des versements 2026-2027 d'ACMA (décision 2).
- Enseignants, attributions, salles et emplois du temps d'ACMA : vides ou déjà repris par les lots 12 et 13.
- Numérisation des archives papier (D29, `28_indexation_archives.py`) : une autre partie du lot 15 de la feuille de route (« archivage numérique »), à spécifier à part.

## 17. Points tranchés en réalisation

Écarts à la lettre de la spec, tranchés au plus prudent et dans le sens de l'ADR-0014.

### 15a — Transit et inventaire

1. **Colonnes d'identité non extraites.** En plus des exclusions du §10.1, `acma:extraire` n'extrait pas les coordonnées et l'origine des auditeurs (`etudiant` : `telephone`, `mobile`, `adresse`, `email`, `EmailB`, `numcni`, `region`, `RegionBis`, `departement`). Ni l'archive (§6.2, Q16) ni le rapport des écarts ne s'en servent ; elles restent dans l'export chiffré. Minimisation (§13). **Remplacé le 16/11/2026** : elles entrent dans la liste blanche (format `/3`), le transit et l'archive (sous-lot 15i, [réinscription des anciens](module-4-lot15-reinscription-anciens.md), §4).
2. **Rang des lignes.** Chaque table du transit reçoit une colonne technique `_ligne` (rang de la ligne dans le CSV, clé primaire). Elle permet la lecture par lots sans tri des 309 580 versions de notes. Aucune valeur d'ACMA n'est transformée, sauf le caractère nul, que PostgreSQL refuse dans un texte.
3. **Chiffrement.** L'archive est un zip des CSV et du manifeste, chiffré en AES-256-GCM par morceaux d'un mébioctet (numéro et drapeau « dernier » authentifiés), clé dérivée de la phrase de passe par PBKDF2-SHA256 à 600 000 itérations. La phrase se donne par la variable `ACMA_PHRASE` ou à l'invite, jamais en option. Procédure : `docs/ops/reprise-acma.md`.
4. **Comptes de l'inventaire.** Trois libellés précisent le §3 sans le contredire : « année de la note différente du programme » ne compte que les années renseignées (64 ; les 3 850 du §3.4 comprenaient 3 786 années vides, déjà comptées parmi les 29 428) ; les négatifs sont appariés un pour un (147 au lieu de 148 : deux négatifs ne partagent pas une contrepartie) ; les « né vers » ne sont pas comptés parmi les dates vides (135 au lieu de 136).

### 15b — Archive académique

1. **Clés naturelles.** Une clé composée est rangée dans une colonne `cle_source` unique, à côté de la contrainte métier (par exemple `acma_inscriptions` : `matricule|année|niveau ACMA`, et unicité de `(dossier, année, niveau)`). Les résultats sont clés par leur note source (`n:<idnote>`, ou `h:<idprog>:<matricule>` pour une note reconstituée) et non par `(inscription, unité)` : c'est ce qui garde côte à côte les deux notes d'un conflit (§7.5).
2. **Notes sans inscription.** 146 versions de l'historique portent sur un programme d'une année et d'un niveau où l'auditeur n'a pas d'inscription datée. Plutôt que de les perdre, elles sont gardées dans `acma_notes_non_rattachees`, avec le motif `sans_inscription` (à côté de `programme_disparu`, Q6) : hors crédits et hors relevés, comme les autres. C04a et C04b les comptent comme reprises.
3. **Notes hors bornes.** Une note hors de 0 à 20 n'est pas ramenée dans l'intervalle : la colonne bornée reste vide, la valeur brute est gardée, la ligne est signalée (`hors_bornes`) et la note n'entre pas dans le calcul. Une moyenne recalculée hors de 0 à 20 (pondérations hors norme) reste vide.
4. **Décision d'archive.** `decision_source` devient `decision_motif` (`cohorte_sans_decision`, `sans_note`, `admis_absent`) : l'archive étant gelée, la source « complément » se lira à l'affichage, depuis `acma_complements` (15d). Conformément à la liste fermée (Q3), les 3 admis enregistrés du M1 2022-2023 ont une décision d'archive `non_enregistree` ; la valeur d'ACMA reste dans `admis_acma`, que C03 compte.
5. **Fiches fusionnées.** L'identité du dossier est celle de la fiche dont le matricule est déjà normalisé (sinon la première dans l'ordre) ; des identités divergentes sont signalées sur le dossier.
6. **Q1.** Un « document » est une ligne de l'une des six tables de documents d'ACMA (PV de soutenance, certificat, attestation de réussite, mise à disposition, non-perception de bourse, lettre de stage).
7. **Reprises.** `acma_reprises` est elle aussi protégée par déclencheur : jamais supprimée, écrite seulement en mode reprise, figée une fois gelée. Ses statuts : `a_blanc`, `refusee`, `appliquee`, `gelee`. La trace d'un essai ou d'un refus est écrite après l'annulation de la transaction. La migration refuse d'être défaite une fois l'archive gelée.
8. **Rapprochements.** Une proposition automatique n'est ajoutée que si elle change (cas ou auditeur), pour que la reprise rejouée n'écrive rien. R5 et les actifs de 2026-2027 hors plateforme ne vont qu'au classeur.
9. **Contrôles hors reprise.** `acma:controler` marque C09 et C10 « sans objet » : ils ne valent que pendant une exécution. Les rapports et le classeur sont écrits dans le stockage privé avec la visibilité `private`.
10. **`acma_complements`** reste au sous-lot 15d, comme le prévoit le §11 ; les tests de 15b vérifient l'ajout seul sur `acma_rapprochements`.

### 15c — Archive financière et documentaire, écarts 2026-2027, gel

1. **Contrepartie d'un négatif.** `acma_paiements` garde l'identifiant ACMA de la contrepartie (`contrepartie_id_acma`) plutôt qu'une clé vers la ligne d'archive : il ne change pas d'une reprise à l'autre. L'appariement se fait sur la colonne scolarité, un pour un, dans l'ordre des identifiants (147 paires sur l'export du 15/11). `negatif` vaut « une colonne au moins est négative » ; une contrainte `CHECK` le garantit, comme `total` = somme des cinq colonnes.
2. **Barèmes.** `heterogene` signale un dû total différent selon la catégorie (aucun cas dans l'export du 15/11). Des composantes différentes à total égal (3 années-niveaux : tenue de sport comptée sans être ventilée, §3.6) sont seulement comptées ; la combinaison la plus fréquente est gardée.
3. **Documents d'ACMA.** Le `Datedujour` des certificats porte une année académique, pas une date : la date reste vide, l'année va dans `details.annee_academique`. Les copies du nom et de la date de naissance que portent ces tables ne sont pas reprises.
4. **Rapport des écarts.** Le rapprochement se fait auditeur par auditeur (un numéro de reçu ACMA n'identifie pas une opération, §3.5). La promotion est celle de la plateforme ; pour un auditeur absent de la plateforme, l'année d'entrée en M1 d'après ACMA (première inscription M1, ou l'année qui précède une première inscription M2). Le classeur va dans le stockage privé, ou dans le fichier donné par `--sortie=`. La production du rapport est journalisée (`archives`, `ecarts_2026_produits`, comptes seulement), événement ajouté à la liste du §7.11.
5. **Gel.** `acma:geler` exige la dernière reprise appliquée avec des contrôles conformes, et rejoue les contrôles au moment du gel : un transit modifié depuis fait refuser le gel. Le gel précède donc la purge du transit, comme au §10.6. `acma:purger-transit` est refusé avant le gel et sans transit.

### 15d — Consultation, rapprochement et compléments

1. **Permissions livrées.** La migration additive crée `archives.consulter`, `archives.completer` et `archives.rapprocher`, aux rôles du §7.10. `archives.documents_delivrer` (15e), `scolarite.arrieres_preparer`, `scolarite.arrieres_valider` et leur couple informatif de `SEC_SEPARATION` (15f) arrivent avec les sous-lots qui s'en servent : aucun droit sans l'écran qui l'exerce.
2. **Module.** Le module `archives` est ajouté en fin de carte (`ModulePermissionMap`), ce qui garde l'ordre des colonnes de `roles:matrice`. L'écran est rangé dans le menu « Scolarité » (« Archives ACMA »), sans recherche globale. `docs/ops/roles.md` est une copie de la production (comptes compris) : elle se régénère après le déploiement.
3. **Compléments.** Champs `date_naissance`, `decision`, `moyenne` (transcrite avec la décision : la moyenne du PV, ou `sans_moyenne` choisi explicitement) et `note_retenue`. La référence de la pièce est obligatoire ; le fichier joint, facultatif au §7.2, n'est pas livré dans 15d. Une décision ne se transcrit que pour une inscription `non_enregistree` : la décision d'archive ne change pas, l'écran montre celle en vigueur et sa source (« décision transcrite du PV n° … le … par … »).
4. **Arbitrage.** `acma_rapprochements.cas` devient facultatif : un arbitrage sans proposition automatique (écarter une fiche de test) n'a pas de cas. L'arbitrage écrit sa décision, puis le lien ; une reprise rejouée le garde.
5. **Classeurs.** Les classeurs de la reprise (cas à arbitrer : `archives.rapprocher` ; écarts 2026-2027 : `scolarite.export`) se téléchargent depuis l'écran, dans la session authentifiée, permission revérifiée et téléchargement journalisé (`classeur_telecharge`), plutôt que par un lien signé : même protection, sans adresse transmissible.
6. **Fiche de l'auditeur.** La fiche n'a pas d'onglets : l'« Historique ACMA » y est une section, visible avec `archives.consulter` et seulement si un dossier d'archive est lié.

### Correctifs avant la mise en ligne (relectures base de données et sécurité, 15/11/2026)

Calendrier changé : mise en ligne de 15a à 15d, puis reprise **provisoire** en production sur l'export du 15/11 (non gelée, consultable tout de suite), puis, au J0, rejeu sur l'export final et gel. Un rejeu sur une archive vivante devient le cas normal. Procédure : `docs/ops/reprise-acma.md` §0.

1. **Liste blanche d'extraction.** `ExtractionAcma::COLONNES` fixe, table par table, les seules colonnes extraites (format du manifeste `pssfp-acma-transit/2`) ; le chargement exige exactement cette liste. `etablissement`, `categorie`, `filiere`, `niveau` et `phasepayement` ne sont plus extraites (aucune lecture) ; les identifiants du personnel d'ACMA (`du.username`, `du.stan`, `notes_hist.usercon`, `notes_hist.IDutilisateur`) non plus, et les colonnes `acma_paiements.saisi_par_acma` et `acma_versions_notes.auteur_acma` sont supprimées. Cela précise le §10.1, le §7.2 (identifiant ACMA de l'auteur d'une version) et le §7.5 (la version se consulte avec sa date, sans auteur), dans le sens de la minimisation (§13).
2. **Erreurs sans données.** Toutes les commandes `acma:*` attrapent toute erreur et n'affichent, ni ne journalisent, qu'un message de la reprise ou le SQLSTATE, la table et la contrainte : jamais une valeur (une erreur PostgreSQL cite la ligne fautive dans son détail).
3. **Lignes retirées de la source.** Une ligne marquée `retire_de_la_source_at` n'est plus jamais comptée comme vivante : fiche, notes, paiements par année, totaux, Historique ACMA de l'auditeur, contrôles. Un dossier retiré reste trouvable par un filtre.
4. **Exclusion mutuelle.** `acma:charger`, `acma:reprendre`, `acma:geler` et `acma:purger-transit` prennent un même verrou consultatif de transaction ; l'état (gel, transit) est revérifié sous le verrou. Le déclencheur de `acma_reprises` refuse aussi toute mise à jour une fois l'archive gelée, hors la transition de gel elle-même.
5. **Lien d'un dossier hors reprise.** Hors mode reprise, le déclencheur n'accepte un changement de `acma_dossiers.auditeur_id` que s'il reproduit le dernier arbitrage décidé du dossier dans `acma_rapprochements`. L'arbitrage écrit sa décision, le lien et le journal dans une seule transaction.
6. **C09 et C10** comptent les écritures réelles de la transaction (`pg_stat_xact_user_tables`) au lieu de comparer des comptes de lignes.
7. **Compléments.** Une seule note retenue par conflit (refus d'une seconde, sous verrou de l'inscription). La transcription d'une décision exige la moyenne du PV, ou le choix explicite « sans moyenne » (`moyenne` = `sans_moyenne`).
8. **Divers.** Migrations avec `lock_timeout` de 5 s ; `down()` de 15b refusé dès qu'un arbitrage décidé existe ; policies pour les notes et les paiements d'archive ; le choix d'un auditeur à l'arbitrage exige l'accès au module Scolarité ; ouverture d'une fiche d'archive journalisée (`fiche_consultee`) ; suppression d'un compte référencé par la reprise refusée proprement ; seul le classeur le plus récent de chaque type est gardé ; une version de note en double (`idnotes|changedon`) est gardée une fois et comptée dans C12 ; fichiers temporaires du chiffrement en 0600, plafond de 5 000 000 d'itérations PBKDF2 lues dans l'en-tête, purge de `storage/app/private/acma/tmp` au démarrage du chargement.

### Suites retenues (non livrées dans 15d)

1. **Compléments à quatre yeux, pièce jointe obligatoire.** Avant que 15e ne consomme les compléments (décision transcrite, date de naissance) pour délivrer un document, un complément devra être validé par une seconde personne et porter la pièce numérisée (aujourd'hui : référence obligatoire, fichier non livré).
2. **Rôle de base dédié à la reprise.** Les déclencheurs de lecture seule sont contournables par le propriétaire des tables (`session_replication_role`, désactivation d'un déclencheur, `app.reprise_acma`). Un rôle PostgreSQL dédié, seul autorisé à écrire l'archive, l'application n'ayant que la lecture et l'écriture des compléments, est une suite à prévoir avec l'exploitation.
3. **Compléments sur notes et rejeu.** Une « note retenue » est ancrée sur une ligne de résultat (`resultat_id`), dont la clé source peut changer au rejeu (une note reconstituée de l'historique qui devient une note d'ACMA reçoit une nouvelle ligne ; l'ancienne est retirée). Règle d'exploitation : **pas de note retenue avant le gel** ; sinon, réancrer à la main après le rejeu. Les compléments de date de naissance (dossier) et de décision (inscription) sont ancrés sur des clés stables (matricule normalisé ; matricule, année, niveau).
