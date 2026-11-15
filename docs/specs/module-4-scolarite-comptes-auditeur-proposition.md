# Module 4 — Scolarité, lot D : comptes auditeur (proposition de conception)

**Date** : 2026-11-15
**Statut** : **validée le 15/11/2026**. Le chef de projet a tranché les treize questions du §7, toutes selon l'option recommandée (§8). Suite : [ADR-0013](../adr/0013-compte-de-l-auditeur.md) et [spec du sous-lot D1](module-4-scolarite-comptes-d1.md).
**Demandeur** : Chef USI (chef de projet)
**Références** : [Scolarité, lot 1](module-4-scolarite-lot1-2026-11.md) (§1 hors périmètre : « report d'un trop-perçu », « passage automatique en M2 ») · [lot 4, reçus](module-4-scolarite-lot4-recus.md) (§8 : « compte unique de l'auditeur sur tout le parcours ») · [feuille de route LMD](module-4-lmd-feuille-de-route.md) (§4, écarts D7 et D8) · lot A, accès anticipé ([`module-4-scolarite-acces-anticipe.md`](module-4-scolarite-acces-anticipe.md), A1 fusionné par la PR #125 le 15/11/2026) · [ADR-0010](../adr/0010-vie-academique-auditeurs-phase-1.md) · dossier « PSSFP Système », décisions D5 à D9.

## 1. Le besoin

> « Un étudiant vient verser tout l'argent de ses deux années en une fois. Dans l'ancien logiciel, il fallait enregistrer le paiement de l'année en cours (les 185 000), puis le reste pour la même année mais en spécialité. L'année suivante, soit c'est oublié, soit ce n'est pas bien reversé. D'où l'idée de comptes : chaque auditeur a un compte, débité selon sa progression et sa redevabilité. »

Reformulé, le besoin a trois volets :

1. **Garder l'argent versé d'avance** sans le dénaturer en « trop-perçu », et l'**imputer de lui-même** sur l'année suivante quand elle s'ouvre, sans geste humain à ne pas oublier.
2. **Ne jamais perdre une dette** d'une année passée : le reste à payer se lit sur tout le parcours (décision D7 : « un auditeur de M2 doit la totalité, M1 et M2 »), et pas seulement sur l'année courante.
3. **Tracer chaque mouvement** (dû, versement, imputation, annulation) dans un relevé de compte, en gardant les acquis du lot 4 : reçus numérotés et immuables, séparation des tâches, journal scellé.

### 1.1 Ce que fait la plateforme aujourd'hui (vérifié dans le code)

| Élément | Comportement actuel | Conséquence pour le besoin |
|---|---|---|
| `versements.inscription_annuelle_id` (NOT NULL) | Un versement appartient à **une** inscription annuelle. | Impossible de répartir un versement entre deux années. |
| Index unique `(mode, reference)` sur les versements non annulés | Un bordereau = un seul versement. | On ne peut même pas contourner en saisissant deux versements avec la même référence bancaire. |
| `SituationFinanciere::calculer` | Remplit inscription → 1re tranche → 2e tranche ; l'excédent est un `tropPercu`, « affiché, reporté nulle part ». | L'avance reste collée à l'année où elle a été saisie. |
| Formulaire « Enregistrer un versement » (`AuditeurActions`) | Visible seulement si l'auditeur a une inscription courante ; enregistre **toujours** sur elle. | Pas de versement possible pour un auditeur sans inscription (P13 non admis en M2, barème manquant). |
| `Auditeur::inscriptionCourante` | Inscription de l'année académique la plus récente (`ofMany(['annee_academique' => 'max'])`). | Liste, relances, export, espace et accès FOAD ne regardent que cette année : une dette de M1 disparaît des écrans dès l'ouverture du M2. |
| `OuvertureAuditeur::creerInscription` | Crée toujours une **M1**, pour l'année de la campagne. `ImportAuditeurs` crée la M2 2026-2027 des P13 admis. | **Aucun outil de passage M1 → M2 ni de redoublement** : l'inscription 2027-2028 des P14 ne peut pas être créée aujourd'hui. |
| `RecusVersements::imputation` | Objet du reçu recalculé à l'émission depuis les versements antérieurs de la même inscription ; l'excédent est libellé « trop-perçu ». | Un versement voulu pour le M2 porte la mention « trop-perçu » sur un reçu immuable. |
| `RecusVersements::emettre`, `RegistreVersements` | Verrou sur la ligne de l'inscription. | Le verrou devra porter sur l'auditeur dès qu'un versement touche plusieurs années. |
| `ScolariteResource` (espace) | Versements de l'inscription courante seulement. | Un auditeur de M2 ne voit plus ses versements de M1. |
| `MessagesAuditeurs` (`{reste}`), `SituationFinanciereExcel` | Inscription courante seulement. | Les arriérés ne sont ni relancés ni exportés. |
| `DocumentsOfficiels::enRegle` (LMD) | Retard de l'inscription concernée (levée de restriction possible). | Un arriéré d'une année passée n'empêche pas la délivrance des documents de l'année suivante. |

L'ancien logiciel (ACMA) ne faisait pas mieux : reste à payer stocké par ligne et jamais recalculé (61 trop-perçus négatifs), 324 versements négatifs sans type, et 371 auditeurs-années en trop-perçu pour 255 millions de FCFA (dossier « PSSFP Système », écarts constatés avant D7).

## 2. Scénarios chiffrés

Barème 2026-2027 (lot 1, §2) : M1 = 185 000 + 500 000 + 500 000 = **1 185 000** ; M2 = 135 000 + 500 000 + 500 000 = **1 135 000**. Montants en FCFA. Auditeurs fictifs.

### S1. Les deux années payées d'avance

L'auditeur A, en M1 2026-2027, verse **2 320 000** par un seul virement le 12/11/2026.

| | Aujourd'hui | Avec un compte |
|---|---|---|
| M1 2026-2027 | Soldée. Trop-perçu de 1 135 000 affiché. | Soldée. **Avance de 1 135 000** au compte. |
| Reçu émis | « … 2e tranche (500 000 FCFA), **trop-perçu** (1 135 000 FCFA) » | « … 2e tranche (500 000 FCFA), **avance sur la scolarité à venir** (1 135 000 FCFA) » |
| Rentrée 2027-2028 | Aucun outil pour créer la M2. Si on la crée à la main : versé 0, « Inscription non soldée », accès FOAD bloqué, relance « il reste 1 135 000 ». Pour corriger, il faudrait annuler le versement et ressaisir deux versements sur le même bordereau, ce que l'index unique refuse. | À l'ouverture de la M2, l'avance s'impute **d'elle-même** : M2 soldée, accès FOAD ouvert, SMS « accès disponibles ». |
| Barème M2 2027-2028 relevé à 1 160 000 (inscription 160 000) | — | L'avance couvre 1 135 000 : il reste 25 000 sur la 2e tranche. |

Relevé de compte attendu :

| Date | Opération | Dû | Versé | Avance |
|---|---|---|---|---|
| 06/11/2026 | Inscription M1 2026-2027 | 1 185 000 | | 0 |
| 12/11/2026 | Versement (virement) | | 2 320 000 | 1 135 000 |
| 08/11/2027 | Inscription M2 2027-2028 | 1 135 000 | | 1 135 000 |
| 08/11/2027 | Imputation de l'avance sur 2027-2028 | | | 0 |

### S2. Paiement partiel, puis passage en M2 avec un arriéré

L'auditeur B verse 185 000 (10/11/2026) puis 500 000 (15/01/2027) : la 2e tranche du M1 (500 000) reste due. Admis en M2, son inscription 2027-2028 est ouverte le 08/11/2027.

- **Aujourd'hui :** la liste, les relances, l'export et l'espace n'affichent plus que la M2 (reste 1 135 000). Les 500 000 du M1 ne se voient plus que dans l'historique de la fiche : c'est l'oubli que décrit le chef de projet.
- **Avec un compte :** arriéré 2026-2027 de 500 000, plus 1 135 000 pour 2027-2028, soit un **reste sur le parcours de 1 635 000**. Il verse 300 000 le 10/11/2027 :
  - si l'arriéré passe d'abord (option recommandée, Q1) : les 300 000 vont sur la 2e tranche du M1 (arriéré ramené à 200 000), les droits du M2 restent impayés et l'accès FOAD 2027-2028 reste bloqué ;
  - si l'année en cours passe d'abord, ou si le responsable accorde une affectation (Q2) : 135 000 soldent les droits du M2 (accès ouvert) et 165 000 vont sur sa 1re tranche ; l'arriéré reste de 500 000.
- **Variante, trop-perçu en M1 :** s'il avait versé 1 285 000 en M1, les 100 000 d'avance s'imputeraient sur les droits du M2 à son ouverture (il resterait 35 000 sur ces droits).

### S3. Redoublement

L'auditeur C a versé 1 685 000 en M1 2026-2027 : l'année entière, plus 500 000 d'avance pour le M2. Le jury M1 le déclare ajourné (AJ) : il redouble le M1 en 2027-2028, sa deuxième et dernière inscription autorisée dans ce niveau (D5).

- **Aujourd'hui :** aucun outil pour créer une deuxième inscription M1. Les 500 000 restent en trop-perçu sur 2026-2027.
- **Avec un compte :** à l'ouverture du M1 2027-2028, l'avance s'impute d'elle-même. Le résultat dépend du montant dû par un redoublant (Q5) :
  - **barème complet** (1 185 000) : les droits (185 000) sont soldés, 315 000 vont sur la 1re tranche, il reste 685 000 à payer ;
  - **droits d'inscription seulement** (remise de 1 000 000) : les droits sont soldés et 315 000 restent en avance, pour le M2 suivant ou pour un remboursement (Q6).
- **Cas voisin, autorisé à soutenir (AS) en M2 :** il se réinscrit en M2 avec une pénalité de 500 000 (D5). Ce frais hors barème est prévu au sous-lot D5.

### S4. Prise en charge par l'employeur

L'auditeur D (M1 2026-2027) règle lui-même les droits de 185 000 le 10/11/2026 pour obtenir ses accès. Son ministère s'engage à payer l'année entière (engagement du lot A2), puis verse 1 185 000 pour lui le 15/03/2027, dans un règlement réparti.

- **Aujourd'hui et selon le lot A2 tel qu'écrit :** versé 1 370 000, trop-perçu de 185 000, et rien ne se passe ensuite.
- **Avec un compte :** 1 000 000 sont imputés sur le M1 (1re et 2e tranches) et 185 000 restent en avance. Comme cette avance vient d'un versement dont le payeur est `employeur`, son origine se lit sans ambiguïté.
- **À qui revient l'excédent ?** Remboursement à l'auditeur, remboursement au ministère, ou imputation sur le M2 si l'engagement le couvre : c'est la question Q7. En attendant, le contrôle interne signale l'avance.
- **Créance sur l'administration (D8) :** tant que l'organisme n'a pas payé, son engagement n'est **jamais** compté comme versé. Le compte l'affiche à titre d'information, comme le prévoit le lot A.

### S5. Annulation d'un versement déjà imputé sur l'année suivante

Suite de S1. Le versement de 2 320 000 a reçu le reçu RCPT-2026-000045. Le 08/11/2027, l'avance a été imputée sur la M2, l'accès s'est ouvert et le SMS est parti. Le 20/11/2027, la banque signale que le virement a été rejeté, et le responsable annule le versement.

- **Avec un compte :**
  - les deux imputations du versement sont annulées dans la même transaction : 1 185 000 sur le M1 et 1 135 000 sur la M2 ;
  - le M1 redevient un arriéré de 1 185 000, et la M2 n'a plus rien de versé ;
  - l'accès FOAD 2027-2028 redevient « bloqué » dans l'espace (le code reste valable sur eCampus, lot 1 §5) ;
  - le reçu reçoit automatiquement son écriture d'annulation RCPT-2026-000045-A (mécanisme du lot 4, inchangé).
- **Avant de valider**, le formulaire nomme chaque année touchée et l'effet sur l'accès, comme `annulationRebloque` le fait aujourd'hui pour une seule année. Faut-il une double décision quand une autre année que la courante est touchée ? C'est la question Q8.
- **Sans compte :** ce cas ne peut même pas se produire proprement, puisque l'argent n'a jamais pu passer sur la M2.

## 3. Trois approches

### A. Grand livre en partie double par auditeur

Chaque auditeur reçoit un compte (`comptes_auditeurs`) et un journal d'écritures (`ecritures_compte`) :

- **au débit :** la constatation du dû à chaque inscription (une écriture par palier), les pénalités et les autres frais ;
- **au crédit :** chaque versement et chaque remise ;
- **erreurs :** corrigées par contre-passation, jamais par une suppression ;
- **lettrage :** une table `lettrages` rapproche crédits et débits.

Le solde se calcule à partir des écritures. C'est la lecture littérale de D7 (« un compte généré dans le module caisse, toute opération portée au crédit »).

### B. Avoir reportable d'une inscription à la suivante

Rien ne change dans le rattachement des versements. Une table `reports_avoirs` (inscription source, inscription cible, montant, auteur, motif) transfère un trop-perçu vers l'inscription suivante, à la main ou automatiquement à sa création. Le cumul d'une inscription devient : versements + reports reçus − reports émis.

### C. Compte de l'auditeur à imputation automatique

Les versements se rattachent à l'**auditeur**, et non plus à une inscription. Chaque versement est imputé sur les inscriptions qui ont encore un reste à payer, y compris celles qui ne sont pas encore ouvertes : son reliquat forme une **avance** qui s'impute dès que la prochaine inscription s'ouvre. Il y a deux variantes :

- **C1, imputation calculée à la volée :** rien de nouveau n'est stocké. Le versé d'une inscription se déduit du total versé par l'auditeur, réparti dans l'ordre des années (premier entré, premier imputé).
- **C2, imputations enregistrées :** chaque imputation (versement → inscription, montant) est une ligne stockée, jamais supprimée, posée par des règles automatiques. La situation de chaque inscription reste **calculée**, à partir de ses imputations au lieu de ses versements.

### 3.1 Comparaison

| Critère | A. Grand livre | B. Avoir reportable | C1. Imputation calculée | C2. Imputations enregistrées |
|---|---|---|---|---|
| **Répond au besoin** (avance, dettes sur le parcours, relevé) | Oui, entièrement | Partiellement : rien pour les arriérés ; si le report est manuel, l'oubli reste possible | Oui | Oui |
| **Reçus immuables, objet** | Objet déduit du lettrage ; reçu inchangé | Inchangé ; « trop-perçu » demeure ; le report demande sa propre pièce | Objet figé à l'émission, mais l'imputation calculée peut **changer ensuite** (une annulation ancienne déplace l'argent) : reçu et situation peuvent diverger | Objet lu dans les imputations enregistrées : exact, et stable dans le temps |
| **`SituationFinanciere`, scopes SQL** | Réécriture complète ; les montants copiés sur l'inscription font doublon avec les écritures de dû | Petit changement (± reports) | Calcul réécrit ; les scopes doivent cumuler toutes les inscriptions de l'auditeur | **Changement minimal** : `verse` = somme des imputations actives ; seule la source de la sous-requête `VERSE` change |
| **Accès FOAD** | Règle réécrite sur les écritures | Inchangé, sauf si le report est oublié | Peut **se fermer sans geste** (annulation ou remise sur une année ancienne) | Inchangé ; ne bouge que sur un événement de l'auditeur, avec avertissement |
| **Espace, exports, relances** | Réécrits | Inchangés ; les arriérés restent invisibles | Enrichis (avance, arriérés) | Enrichis (avance, arriérés, relevé de compte) |
| **Reprise des données** | Lourde : écritures de dû, remises en écritures, lettrage | Aucune | Triviale (`auditeur_id` sur les versements) | Simple : une inscription par auditeur aujourd'hui, donc une imputation de reprise par versement, et l'excédent devient avance |
| **Performances** (≈ 600 auditeurs, ≈ 2 000 versements par an) | 5 à 6 écritures par inscription : sans souci | Sans souci | Sous-requêtes cumulées plus coûteuses mais acceptables | Une table de plus, indexée : sans souci |
| **Contrôle interne** | Le meilleur (balance, lettrage) | Faible | Faible : les mouvements ne sont pas tracés, ils sont recalculés | Bon : chaque imputation est datée, a un auteur et une origine ; cohérence vérifiable par des contrôles |
| **Écart avec l'ADR-0010** (« situation non stockée ») | Fort : nouvelle source de vérité | Nul | Nul | Faible : les imputations sont des **faits** (où va l'argent), pas des totaux en cache ; la situation reste calculée |
| **Risque sur du code d'argent en production depuis le 05/11** | Élevé | Faible | Moyen (effets rétroactifs) | Moyen, maîtrisé par un service unique et des tests de propriété |
| **Effort** | 4 à 5 sous-lots avant de retrouver l'existant | 1 sous-lot | 2 sous-lots | 2 sous-lots pour le cœur, puis 2 à 3 d'extension |
| **Extensions** (autres frais, remboursements, créances) | Natives | Non | Difficiles (la formule se complique à chaque cas) | Possibles : nouvelles cibles d'imputation, débits de l'avance |

### 3.2 Pourquoi pas A, B ou C1

- **A** est la cible comptable idéale. Mais elle réécrit d'un coup du code d'argent en production depuis dix jours, et elle double les montants déjà copiés sur l'inscription : remise, lettre d'admission et LMD lisent ces colonnes. Le gain par rapport à C2, le lettrage, est repris par C2 à la granularité de l'inscription.
- **B** soigne le symptôme du S1, mais pas les S2 et S3. Pour éviter l'oubli, il dépend d'un report bien déclenché, et une annulation après report rend le cumul de la source négatif.
- **C1** est élégante, mais **instable dans le temps**. Exemple : une remise accordée en 2028 sur le M1 de 2026 déplace de l'argent vers la M2 2027-2028 et peut fermer un accès sans que personne ne l'ait voulu. De plus, la règle d'ordre est figée dans une formule : une affectation demandée par le payeur ne s'y exprime pas.

## 4. Recommandation : C2, compte de l'auditeur à imputations enregistrées

### 4.1 Principe

- **Le compte, c'est l'auditeur :** ses versements, leurs imputations et son avance. Il n'y a pas de table `comptes` : le matricule identifie le compte, et la ligne `auditeurs` porte le verrou.
- **Le dû reste porté par les inscriptions annuelles :** montants copiés du barème, remise, paliers inscription → 1re tranche → 2e tranche. Rien ne change pour la lettre d'admission, le LMD ou les barèmes.
- **Une imputation** affecte une partie d'un versement à une inscription. Elle n'est jamais supprimée : on l'annule (`annule_*`), comme un versement.
- **L'avance** (crédit du compte) = versements valides − imputations actives. Elle est calculée, jamais stockée.
- **La situation d'une inscription** se calcule comme aujourd'hui, avec `verse` = somme de ses imputations actives.
- **Le reste sur le parcours** = somme des restes de toutes les inscriptions. Les **arriérés** sont les restes des inscriptions antérieures à la courante.

### 4.2 Modèle de données (esquisse)

**`versements`** (modifiée) :

| Colonne | Changement |
|---|---|
| `auditeur_id` | **Nouvelle**, FK `auditeurs` RESTRICT, indexée, NOT NULL après reprise (renseignée depuis l'inscription). |
| `inscription_annuelle_id` | Devient **facultative**. Sens : « année désignée par le payeur » (affectation, Q2). Les versements existants la gardent, puisqu'ils étaient bien destinés à cette année. |
| Le reste | Inchangé : montant > 0, index unique `(mode, reference)` sur les lignes non annulées, `annule_*`. |

**`imputations_versements`** (nouvelle) :

| Colonne | Type | Règle |
|---|---|---|
| `id`, `uuid` | | |
| `versement_id` | FK `versements`, RESTRICT | |
| `inscription_annuelle_id` | FK `inscriptions_annuelles`, RESTRICT | Imputation à la granularité de l'inscription ; les paliers restent calculés |
| `montant` | integer | CHECK `> 0` (aucun montant négatif, D7) |
| `origine` | varchar(12) | CHECK : `saisie`, `avance`, `reprise`, `manuelle` |
| `cree_par` | FK `users`, nullable | Nul pour une imputation automatique |
| `motif` | varchar(500), nullable | CHECK : obligatoire (10 caractères au moins) si `origine = 'manuelle'` |
| `annule_at`, `annule_par`, `annule_motif` | nullable | « Désimputation » : la ligne reste |
| `created_at`, `updated_at` | timestamptz | |

Contraintes et index :

- index partiels `(inscription_annuelle_id)` et `(versement_id)` `WHERE annule_at IS NULL` ;
- déclencheur : seules les colonnes `annule_*` peuvent changer, une seule fois, et aucun `DELETE` n'est possible (même principe que les reçus) ;
- déclencheur différé (`CONSTRAINT TRIGGER … DEFERRABLE INITIALLY DEFERRED`) : la somme des imputations actives d'un versement ne dépasse jamais son montant, et aucune imputation active ne porte sur un versement annulé ;
- somme des imputations actives d'une inscription ≤ son dû après remise : garantie par le service, sous verrou, et par un contrôle interne bloquant. Un déclencheur sur `inscriptions_annuelles` est à trancher dans la spec D1, car la remise met à jour cette table.

Ces usages de SQL brut (CHECK, index partiels, déclencheurs) relèvent du même précédent que les ADR-0010 et 0011. Ils seront consignés dans un **ADR-0013 « Compte de l'auditeur »**, qui précise aussi l'ADR-0010 point 3 : la situation reste calculée, et les imputations sont des faits enregistrés, comme les versements.

**`recus_versements`** :

- **Données :** aucune colonne obligatoire n'est ajoutée. L'objet, déjà figé, détaille désormais les années et l'avance.
- **Versement entièrement en avance** (Q12) : `inscription_annuelle_id`, `annee_academique` et `niveau` deviennent nullables. C'est un changement de schéma (DDL) qui ne modifie aucune ligne : les déclencheurs d'immuabilité ne sont pas concernés.
- **Contrôle d'appartenance :** le téléchargement par l'auditeur (`ScolariteController::recu`) passe par `versement.auditeur_id` et non plus par l'inscription.

### 4.3 Règles d'imputation

| N° | Règle |
|---|---|
| **R1, ordre des dus** | Inscriptions dont le reste est positif, de l'année académique la plus ancienne à la plus récente : **les arriérés d'abord** (option recommandée de Q1, conforme au principe classique : faute d'indication du payeur, la dette échue la plus ancienne est acquittée en premier). Dans une inscription, l'ordre des paliers ne change pas : inscription, 1re tranche, 2e tranche. |
| **R2, à la saisie** | a) Si une année est désignée (affectation accordée, Q2), son reste est couvert en premier. b) Le solde suit R1. c) Le reliquat forme l'avance, sans aucune ligne d'imputation. |
| **R3, avance** | À chaque événement qui ouvre ou augmente un reste, l'avance s'impute selon R1 en consommant d'abord les versements les plus anciens (date, puis ordre d'enregistrement). Ces événements sont : la création d'une inscription, la baisse d'une remise, l'annulation d'une imputation. Origine : `avance`. |
| **R4, annulation d'un versement** | Ses imputations actives sont annulées dans la même transaction, avec le motif du versement. On ne prélève **jamais** sur les imputations d'autres versements. R3 s'applique ensuite : une avance disponible comble les restes rouverts, du plus ancien au plus récent. |
| **R5, hausse de remise ou baisse du dû** | Si les imputations actives dépassent le nouveau dû, l'excédent est désimputé en partant des imputations les plus récentes ; il redevient avance, puis R3 s'applique. |
| **R6, réimputation manuelle** | Le responsable (permission `scolarite.imputation`) annule tout ou partie d'une imputation et la reporte sur une autre inscription ou en avance. Motif obligatoire, journal, contrôle interne. Pour une part, on annule puis on recrée l'imputation du reste. |
| **R7, garde-fous** | Aucun montant négatif, aucune suppression. Imputations d'un versement ≤ son montant ; imputations d'une inscription ≤ son dû. |
| **R8, verrou unique** | Toute écriture d'argent verrouille d'abord la ligne `auditeurs`, puis les inscriptions concernées : versement, annulation, remise, imputation, émission de reçu, création d'inscription. Ce verrou remplace celui de l'inscription dans `RegistreVersements` et `RecusVersements::emettre`, qui garantit l'invariant « un seul reçu en vigueur par versement ». |
| **R9, déterminisme** | La même suite d'événements produit les mêmes imputations. Des tests de propriété le vérifient : séquences aléatoires de versements, annulations, remises et ouvertures. |

Un seul service écrit les imputations, `ImputationsCompte`, appelé par `RegistreVersements`, `OuvertureAuditeur`, `ImportAuditeurs` et le futur outil de réinscription. Un objet de lecture, `CompteAuditeur`, rassemble les inscriptions avec leur situation, l'avance, les arriérés, le reste sur le parcours et le relevé chronologique. C'est la seule source pour la fiche, l'espace, l'export et les relances.

### 4.4 L'année suivante, pas encore ouverte

- **Jamais d'inscription créée par anticipation.** `inscriptionCourante` est l'inscription de l'année la plus récente : une M2 2027-2028 créée en juillet 2027 deviendrait aussitôt « courante » et fermerait l'accès FOAD de l'année en cours. Elle ferait aussi basculer les pages Parcours et Emploi du temps de l'espace. L'argent attend donc en **avance**, visible partout.
- **Barème futur inconnu :** l'avance s'impute à l'ouverture de l'inscription, au barème de cette année-là (lot 1 : « le barème peut changer d'une année à l'autre »). Si le barème monte, la différence est due ; s'il baisse, le surplus reste en avance. Si le chef de projet veut garantir le prix à qui paie d'avance (Q3), une remise sur la nouvelle inscription suffit, motivée « prix garanti au versement du … ». Le modèle ne change pas.
- **Accès dès l'ouverture :** l'imputation automatique solde les droits de la nouvelle inscription, ce qui déclenche une fois le SMS « accès disponibles » de cette inscription (`acces_notifie_at`, lot 1 §5). L'auditeur qui a tout payé reçoit ses accès sans repasser à la caisse.
- **Affichage :** dans l'espace, « Avance disponible : 1 135 000 FCFA. Elle sera imputée automatiquement sur votre prochaine inscription. » Sur le reçu : « avance sur la scolarité à venir ».

### 4.5 Effets sur l'existant

| Domaine | Effet |
|---|---|
| `SituationFinanciere` | Calcul inchangé. `verse` reçoit la somme des imputations actives ; `tropPercu` d'une inscription vaut toujours 0 et cède la place à l'avance du compte. |
| Scopes d'`InscriptionAnnuelle` | La sous-requête `VERSE` lit `imputations_versements` au lieu de `versements`. Les autres expressions ne changent pas. Nouveaux filtres de liste : « avec arriérés », « avec avance ». |
| Préchargements | `withSum('versementsValides', 'montant')` devient `withSum('imputationsActives', 'montant')` : `AuditeurResource`, `CandidatureResource`, `MessagesAuditeurs`. |
| `Auditeur` | `versements()` devient un `HasMany` sur `auditeur_id` (fin du `HasManyThrough`) ; `historique()` y ajoute les imputations. |
| Reçus | L'objet est lu dans les imputations du versement à l'émission : « Frais de scolarité 2026-2027 (M1) : droits d'inscription (185 000 FCFA), … ; avance sur la scolarité à venir (1 135 000 FCFA) ». Total versé et reste de l'année de référence, comme aujourd'hui. Les reçus déjà émis gardent « trop-perçu » : ils sont figés. |
| Accès FOAD | Règle inchangée (droits de l'inscription courante soldés, ou déblocage du lot A), désormais sur les imputations. La régularisation automatique des déblocages du lot A doit aussi se déclencher après une imputation automatique ; la tâche quotidienne la rattrape de toute façon. |
| Espace de l'auditeur | Nouvelle rubrique « Mon compte » : avance, arriérés par année, reste sur le parcours, relevé chronologique, relevé de compte en PDF. Les versements de **toutes** les années, chacun avec sa répartition par année. API `GET /me/scolarite` : bloc `compte` ajouté, champs existants conservés. |
| Fiche et liste | Onglet « Compte » (relevé, avance, arriérés) ; colonnes masquables « Arriérés » et « Avance ». Le formulaire de versement s'ouvre même sans inscription (Q12) et annonce la répartition avant validation : « 1 185 000 sur 2026-2027, 1 135 000 en avance ». |
| Export Excel | Colonnes « Arriérés », « Avance », « Reste sur le parcours », à coordonner avec les listes PDF du lot C. |
| Relances | Nouvelles variables `{arrieres}`, `{reste_parcours}`, `{avance}`. `{reste}` garde son sens actuel (année courante), pour ne pas changer les modèles enregistrés. |
| Documents LMD (`enRegle`) | Selon Q4 : les arriérés comptent pour « en règle ». La levée de restriction ne change pas. |
| Lot A2 (règlements d'organismes) | Chaque versement créé par le règlement réparti désigne l'inscription de l'engagement (R2 a). Un excédent devient une avance « origine employeur ». |
| Contrôle interne | `CPT_COHERENCE` (bloquant) : sommes, imputation sur versement annulé. `CPT_AVANCE_DORMANTE` (majeur) : avance positive et statut abandon, exclu ou diplômé, ou aucune inscription depuis 12 mois. `CPT_REIMPUTATION` : réimputations manuelles soumises au visa a posteriori du contrôleur interne (D9). |
| Journal | Événements `imputation_auto`, `avance_imputee`, `imputation_annulee`, `reimputation` (journal `scolarite`, scellé par le lot 14d-1). |

### 4.6 Reprise des données existantes

1. Renseigner `versements.auditeur_id` à partir de l'inscription, puis passer la colonne en NOT NULL.
2. Pour chaque inscription, imputer ses versements valides par ordre chronologique, origine `reprise`, jusqu'à son dû après remise. L'excédent ne reçoit aucune imputation : il devient avance.
3. En production, chaque auditeur a **au plus une inscription** (P14 en M1, P13 admis en M2) : la reprise ne déplace donc aucun argent entre années.
4. **Vérification d'équivalence**, dans la migration et dans un test : pour chaque inscription, paliers, reste et état d'accès identiques avant et après. L'ancien trop-perçu doit être égal à l'avance du compte.
5. Pas de SMS, pas de reçu : les reçus déjà émis restent tels quels.
6. Les **dettes anciennes d'ACMA** ne sont pas reprises (Q10).

### 4.7 Mesures transitoires (dès maintenant, sans attendre D1)

- **Consigne aux agents :** enregistrer le versement entier sur l'inscription courante, avec son vrai bordereau. L'excédent s'affiche en trop-perçu, et la reprise D1 le transformera en avance : rien n'est perdu.
- **Petit correctif possible** (Q13) : remplacer dès maintenant le libellé « trop-perçu » de `RecusVersements::imputation` par « avance sur la scolarité à venir », pour les reçus qui seront émis d'ici D1.

### 4.8 Découpage en sous-lots

Chaque sous-lot a sa spec, sa branche et ses tests, et se déploie seul.

| Sous-lot | Contenu | Prérequis | Échéance utile |
|---|---|---|---|
| **D1, socle du compte** | ADR-0013, migration (`auditeur_id`, `imputations_versements`, déclencheurs), service `ImputationsCompte` (R1 à R5, R7 à R9), verrou de l'auditeur, `SituationFinanciere` et scopes, objet des reçus, reprise et test d'équivalence. Changement visible : « trop-perçu » devient « avance ». | Décisions Q1, Q12 et Q13 (prises) ; lot A1 fusionné (fait, PR #125) | Avant la fin de 2026 : les P14 paient déjà |
| **D2, compte visible** | Onglet « Compte » de la fiche, colonnes et filtres de la liste, export, variables de relance, rubrique « Mon compte » de l'espace et relevé PDF, versement sans inscription. | D1 ; Q4 | Avant les relances de la 1re tranche 2026-2027 |
| **D3, ouverture de l'année suivante** | Réinscription en groupe à partir des résultats publiés : AD en M1 → M2 ; AJ → M1 avec plafond de 2 inscriptions par niveau (D5) ; au barème de la nouvelle année. Imputation automatique de l'avance (R3), SMS d'accès, refus avant la date d'ouverture de l'année. | D1 ; Q5 et Q11 ; délibérations M1 (lot 6) | Rentrée 2027-2028, voire plus tôt pour les P13 non admis en M2 (feuille de route, question 3) |
| **D4, affectation et réimputation** | Année désignée à la saisie (Q2), réimputation manuelle (R6), demande d'autorisation 14c pour une annulation qui touche une autre année (Q8), contrôles `CPT_*`, branchement du lot A2. | D2 ; Q2 et Q8 | Au plus tard avec le lot A2 |
| **D5, remboursements et autres frais** (après décision) | Remboursement d'une avance (demande, décision du chef de la structure, pièce numérotée), frais hors barème comme dus (pénalité AS de 500 000, frais de rattrapage, D6). | D4 ; Q5, Q6 et Q7 | À la demande |

**Hors périmètre :**
- un reçu par objet de frais (D7) ;
- la clôture comptable annuelle ;
- le rapprochement bancaire des versements de scolarité ;
- le paiement en ligne (module 9).

### 4.9 Risques et parades

| Risque | Parade |
|---|---|
| Erreur d'imputation sur de l'argent réel | Un seul service écrivain ; tests unitaires en table de cas (S1 à S5) et tests de propriété (R9) ; déclencheur différé et contrôle `CPT_COHERENCE` bloquant. |
| Interblocages entre verrous | Ordre fixe (auditeur, puis inscriptions) appliqué partout ; test de concurrence comme au lot 1. |
| Accès qui se ferme par effet de bord | R4 et R5 ne prélèvent jamais sur une autre année ; le formulaire avertit pour chaque année touchée ; le déblocage du lot A reste possible. |
| Inscription de l'année suivante créée trop tôt | D3 refuse avant la date d'ouverture. À terme, définir « l'année en cours » par le calendrier académique (lot 12a), et non plus par la plus grande année. |
| Conflit avec le lot A en cours | Fusionner A1 d'abord ; D1 adapte `accesOuvert` et la régularisation dans la même PR. |

## 5. Ce qui ne change pas

- Barèmes, montants copiés sur l'inscription, remise unique par inscription, échéances lues en direct.
- Calcul des paliers et règle d'accès FOAD.
- Reçus : numérotation `RCPT-AAAA-NNNNNN`, contenu figé, écriture d'annulation `-A`, séparation des tâches.
- Unicité des références bancaires, versement jamais supprimé, journal.

## 6. Critères d'acceptation (pour la future spec D1)

1. **S1 :** un versement de 2 320 000 sur un M1 à 1 185 000 donne une M1 soldée et une avance de 1 135 000. À l'ouverture d'une M2 à 1 135 000, la M2 est soldée sans geste, l'accès est ouvert et un seul SMS part.
2. **S2 :** avec un arriéré de 500 000, un versement de 300 000 va sur l'arriéré (option R1 recommandée). Le reste sur le parcours s'affiche partout : liste, fiche, espace, export.
3. **S5 :** l'annulation d'un versement imputé sur deux années annule ses deux imputations, pose l'écriture d'annulation du reçu et rebloque l'accès de la nouvelle année, après avertissement.
4. **Reprise :** les mêmes auditeurs gardent leur accès avant et après ; l'ancien trop-perçu est égal à l'avance.
5. **Garde-fous :** aucune séquence d'événements ne produit une imputation négative, une somme d'imputations supérieure au versement ou une imputation sur un versement annulé.

## 7. Questions au chef de projet

Pour chacune, l'option recommandée est en gras. Toutes ont été tranchées le 15/11/2026 : voir le §8.

| N° | Question | Options | Recommandation et raison |
|---|---|---|---|
| **Q1** | Un auditeur doit encore de l'argent d'une année passée et fait un nouveau versement. Où va l'argent par défaut ? | a) aux arriérés d'abord ; b) à l'année en cours d'abord | **a) Aux arriérés d'abord.** C'est la règle habituelle, et c'est ce qui empêche l'oubli des dettes. Conséquence : l'accès FOAD de la nouvelle année n'ouvre qu'une fois les arriérés et les nouveaux droits couverts. Une exception reste possible par Q2 ou par le déblocage du lot A. |
| **Q2** | Le payeur peut-il demander que son versement aille sur une année précise ? Qui l'accorde ? | a) non ; b) oui, l'agent sur mention du bordereau ; c) oui, le responsable sur demande écrite | **c) Oui, accordée par le responsable** sur demande écrite. Exception : un règlement d'employeur rattaché à un engagement va d'office sur l'année de l'engagement. |
| **Q3** | Celui qui paie l'année suivante d'avance paie-t-il le barème en vigueur au moment du versement (prix garanti) ou celui de l'année où il s'inscrit ? | a) prix garanti ; b) barème de l'année d'inscription | **b) Barème de l'année d'inscription.** La différence est due, ou reste en avance. Une remise motivée permet une exception. |
| **Q4** | Que changent les arriérés ? a) Peut-on être réinscrit en M2 avec un arriéré de M1 ? b) Un arriéré empêche-t-il d'être « en règle » pour les documents (certificat, attestation) ? | a) bloquant ou signalé ; b) oui ou non | **a) Signalé, non bloquant** : confirmation demandée à l'agent, la décision reste à la Scolarité (D9 retient « refus de réinscription en cas d'impayé » comme une décision humaine). **b) Oui** : les arriérés comptent ; la levée de restriction existe déjà. |
| **Q5** | Combien doit un redoublant (M1 ou M2 ajourné) ? Et un autorisé à soutenir qui revient en M2 ? | a) barème complet ; b) droits d'inscription seulement ; c) au cas par cas | **a) Barème complet par défaut**, remise possible par le responsable. AS : la pénalité de 500 000 seule (D5), à confirmer. |
| **Q6** | Une avance inutilisée (abandon, exclusion, non-admission, diplôme) peut-elle être remboursée ? Qui décide ? | a) jamais ; b) sur demande, décision du chef de la structure | **b) Sur demande écrite**, décision du chef de la structure, pièce numérotée (sous-lot D5). D'ici là, l'avance reste au compte et le contrôle interne la signale. |
| **Q7** | Un employeur paie plus que le reste dû de l'année. À qui revient l'excédent ? | a) à l'auditeur ; b) à l'organisme ; c) au cas par cas | **c) Au cas par cas.** L'excédent reste en avance « origine employeur », signalé ; remboursement à l'organisme ou imputation sur l'année suivante si l'engagement la couvre. |
| **Q8** | Annuler un versement déjà imputé sur une autre année que l'année courante : le responsable seul, ou une double décision ? | a) responsable seul ; b) demande d'autorisation (lot 14c) | **b) Demande d'autorisation** dès qu'une autre année que la courante est touchée. Le responsable seul suffit pour l'année courante, comme aujourd'hui. |
| **Q9** | Quand l'avance est imputée sur l'année suivante, faut-il remettre un nouveau document à l'auditeur ? | a) nouveau reçu ; b) avis d'imputation numéroté ; c) rien de plus que le relevé de compte | **c) Le relevé de compte.** Aucun argent n'est reçu, donc pas de reçu. L'imputation figure au relevé, et le SMS d'accès part si les droits sont soldés. |
| **Q10** | Faut-il reprendre dans les comptes les dettes anciennes : ACMA, M1 2025-2026 des P13 ? | a) oui, en masse ; b) non ; c) au cas par cas après régularisation | **c) Au cas par cas**, après régularisation par la Scolarité (D7 : « sortir les écarts pour régularisation avant la reprise financière »), lors de la reprise d'ACMA (lot 15). Pas d'import en masse : 786 auditeurs-années sans aucune opération enregistrée. |
| **Q11** | Qui ouvre l'année suivante (passage en M2, redoublement), et quand ? | a) l'agent, fiche par fiche ; b) le responsable, en groupe | **b) Le responsable, en groupe**, après publication des résultats, à partir de la date d'ouverture de l'année. Jamais avant : l'inscription deviendrait « courante » trop tôt (§4.4). |
| **Q12** | Peut-on enregistrer un versement pour un auditeur qui n'a pas encore d'inscription (P13 non admis en M2, barème manquant) ? | a) non ; b) oui, en avance | **b) Oui, en avance.** Il s'imputera à l'ouverture de son inscription. |
| **Q13** | Dès maintenant, avant D1 : remplacer « trop-perçu » par « avance sur la scolarité à venir » sur les reçus, et demander aux agents d'enregistrer le versement entier ? | a) oui ; b) attendre D1 | **a) Oui.** Le correctif tient en une ligne, et la reprise D1 transformera les trop-perçus en avances sans perte. |

## 8. Décisions du chef de projet (15/11/2026)

Les treize décisions retiennent l'option recommandée du §7. L'approche C2 (compte de l'auditeur à imputations enregistrées, §4) en découle et fait l'objet de l'[ADR-0013](../adr/0013-compte-de-l-auditeur.md).

| N° | Décision | Où elle s'applique |
|---|---|---|
| **1** (Q1) | Un nouveau versement solde d'abord les **arriérés**, de l'année la plus ancienne non réglée à la plus récente, puis l'**année en cours** ; le reste devient **avance**. | Règles R1 et R2, sous-lot D1 |
| **2** (Q2) | Un versement peut être **affecté à une année précise**, sur accord du responsable de scolarité. Un **règlement d'employeur** va d'office aux années couvertes par son engagement (lot A2). | D4 (affectation). D1 pose déjà la règle de l'employeur : son excédent n'est pas imputé d'office sur une autre année. |
| **3** (Q3) | Paiement d'avance : l'auditeur doit le **barème de l'année où il s'inscrit**. L'avance est déduite, la différence reste due. | R3 à l'ouverture de l'inscription (règle en D1, outil en D3) |
| **4** (Q4) | Les arriérés **ne bloquent pas** la réinscription, mais ils sont **signalés partout** et **comptent pour « être en règle »** : attestation de réussite et certificat de scolarité bloqués, sauf levée de restriction par le responsable. | D2 (affichage, `DocumentsOfficiels::enRegle`) ; D3 (signalement à la réinscription) |
| **5** (Q5) | **Redoublant** : barème complet de l'année, remise possible. **Autorisé à soutenir** : pénalité de 500 000 FCFA seule, sans droits d'inscription ni tranches, montant paramétrable dans le barème de chaque année (confirmé le 15/11/2026). | D3 (redoublant), D5 (pénalité) |
| **6** (Q6) | **Avance inutilisée** : remboursable sur demande, décision du chef de la structure, tracée dans le compte. | D5 |
| **7** (Q7) | **Excédent payé par un employeur** : au cas par cas, signalé au contrôle interne. Le responsable décide : remboursement à l'organisme ou avance des collaborateurs. | D1 (l'excédent reste réservé, sans imputation d'office) ; D4 (signalement et décision) |
| **8** (Q8) | **Annuler un versement imputé sur une autre année** : par demande d'autorisation (circuit du lot 14c, validée par une autre personne). Une annulation qui ne touche qu'une année reste au responsable seul. | D4. En D1, l'annulation d'un versement imputé sur plusieurs années est refusée : ce cas ne peut pas se produire avant D3. |
| **9** (Q9) | **Aucun document** à l'imputation automatique d'une avance : le relevé de compte suffit (fiche et espace de l'auditeur). | D1 (journal), D2 (relevé) |
| **10** (Q10) | **Dettes anciennes d'ACMA** : reprises au cas par cas, dossier par dossier, lors de l'intégration d'ACMA, jamais en masse. | Lot 15 (reprise d'ACMA) |
| **11** (Q11) | L'**année suivante** (passage en M2, redoublement) est ouverte par le **responsable, en groupe**, après les résultats, à la date qu'il fixe. Les avances s'imputent à ce moment. | D3 |
| **12** (Q12) | Un versement peut être enregistré **sans inscription de l'année suivante** : il devient avance, avec son reçu. | D1 (excédent sur l'année en cours) ; D2 (auditeur qui n'a encore aucune inscription) |
| **13** (Q13) | **Dès maintenant**, hors D1, par un petit changement séparé : « avance » remplace « trop-perçu » sur les reçus et dans l'espace, et les agents saisissent le **versement entier**. | Correctif immédiat ; D1 le reprend dans le nouveau calcul de l'objet du reçu |

### 8.1 Ce que la spec D1 précise par rapport à l'esquisse du §4

- **Déclencheur de cohérence** : il porte aussi sur `inscriptions_annuelles`, ce qui tranche la question laissée ouverte au §4.2. Il est reportable, mais vérifié à la fin de chaque instruction : le service ordonne ses écritures en conséquence.
- **Clés étrangères composites** : versement, inscription et imputation appartiennent au même auditeur.
- **`versements.inscription_annuelle_id`** reste NOT NULL en D1. Elle devient facultative en D2, avec le versement sans inscription.
- **Avance d'un versement d'employeur** : elle est « réservée », et ne s'impute d'office que sur l'année que ce versement désigne (décision 7).
- **Objet des reçus** : élargi à 500 caractères, et format actuel conservé quand une seule année est touchée.
- **Contrôle `CPT_COHERENCE`** : avancé en D1. `CPT_REIMPUTATION` et `CPT_AVANCE_DORMANTE` restent en D4.
