# Module 4 — Scolarité : espace « Finances » du service financier et correction des versements

**Date** : 2026-11-16. **Statut** : validée par le Chef USI le 16/11/2026 (toutes les recommandations du §12 retenues).
**Demandeur** : Chef USI, pour le service financier.
**ADR** : [ADR-0015](../adr/0015-correction-des-versements-par-contre-passation.md) (accepté le 16/11/2026), qui précise l'[ADR-0013](../adr/0013-compte-de-l-auditeur.md) sur un point : comment corriger un versement.
**Specs liées** : [lot 4, reçus](module-4-scolarite-lot4-recus.md), [encaissement et confirmation](module-4-scolarite-encaissement-confirmation.md), [compte de l'auditeur, D1](module-4-scolarite-comptes-d1.md), [lot 14c, demandes d'autorisation](module-4-lmd-lot14c-demandes-autorisation.md), [lot 14a, contrôle interne](module-4-lmd-lot14a-controle-interne.md), [lot 13c-2, contrôle de gestion](module-4-lmd-lot13c2-controle-gestion.md).

Aucune donnée réelle dans ce document : les montants cités sont des exemples de format.

## 1. Besoin

Demande du chef de projet, pour le service financier :

> « En tant que service financier, j'aimerais avoir un vrai menu de gestion des paiements des auditeurs, un tableau de bord avec le total des versements, total dû, les effectifs, un vrai tableau d'analyse pour les financiers, la liste des paiements. Les finances viennent souvent avec des requêtes du genre : ils ont fait une erreur pour un paiement et il doit être annulé ou corrigé pour que le reçu soit réémis. Dans ce cas, j'allais supprimer l'enregistrement pour leur permettre de l'enregistrer à nouveau. Ici, il faut que cela soit toujours possible, mais de la meilleure des manières, avec des traces. »

Deux chantiers, donc :

1. **Un espace « Finances »** : menu propre, tableau de bord, analyse filtrable et exportable, liste des versements, liste des reçus.
2. **La correction d'un versement erroné sans jamais rien supprimer** : annuler ou corriger, réémettre le reçu, garder toutes les traces.

## 2. Constat dans le code (16/11/2026)

### 2.1 Ce qui existe

| Sujet | État en production | Source |
|---|---|---|
| Encaissement | Le service financier seul enregistre un versement et émet son reçu en un geste (`RegistreVersements::encaisser`), sous le verrou de l'auditeur. L'auditeur reçoit un SMS et un e-mail. | `RegistreVersements`, `RecusVersements`, `AvisRecus` |
| Confirmation | La scolarité confirme sur le reçu bancaire original : scan et attestation obligatoires, jamais son propre versement, une seule fois. Table `confirmations_versements` en ajout seul. Seul l'argent confirmé rend « à jour ». Relance à J+7. | `ConfirmationsVersements`, contrôle `VER_NON_CONFIRMES` |
| Compte (D1) | Imputations écrites par `ImputationsCompte` seul (R2 à R5). Le déclencheur `versements_immuables` n'autorise que l'annulation et le bordereau, une fois chacun. **Toute suppression est refusée par la base**, et la troncature aussi. | migration `2026_12_01_100000` |
| Annulation | `RegistreVersements::annuler` : permission `scolarite.versement_annuler` (responsable de scolarité, `admin`, `super_admin`), motif obligatoire. Elle annule les imputations (R4), puis le versement, puis impute l'avance (R3), et passe l'écriture d'annulation `RCPT-…-A` sur le reçu en vigueur. Refus si le versement est imputé sur plusieurs années (R4.1, en attente de D4) ou s'il est la part d'un règlement d'organisme. | `RegistreVersements::annulerVersement` |
| Reçus | Numéro `RCPT-AAAA-NNNNNN` attribué sous verrou du compteur, dans la transaction de l'émission : aucun trou possible. Reçu et écriture d'annulation sont immuables (déclencheur). Le service financier peut déjà annuler un reçu puis en réémettre un pour le même versement (`scolarite.recu_emettre`). | `RecusVersements`, `compteurs_recus` |
| Espace de l'auditeur | Ne liste que les versements valides et le reçu en vigueur de chacun (`ScolariteResource::versements`). Un reçu annulé reste téléchargeable par son lien, avec la mention ANNULÉ. | `ScolariteResource`, `ScolariteController::recu` |
| Demandes d'autorisation (14c) | Circuit à double regard : demandeur, responsable, visa éventuel du contrôle interne ; `parametres` JSONB figés ; empreinte de l'objet recomparée à l'exécution ; exécution sous l'identité du responsable. Précédent métier : `accorder_moratoire` (A3). | `Demandes`, `TypesDemande` |
| Écrans financiers | « Reçus des versements » et « Versements à confirmer » dans le groupe Scolarité ; « Listes et états » (PDF) et export Excel de la situation, réservés à `scolarite.export`, que **le service financier n'a pas** ; « Contrôle de gestion » (paie des enseignants) dans le groupe « Enseignants et paie ». | `RecuVersementResource`, `VersementAConfirmerResource`, `ListesEtats`, `ControleGestionPage` |
| Séparation des tâches | `SEC_SEPARATION` signale le cumul « Enregistrer et confirmer un versement ». | `SecSeparation::COUPLES` |

### 2.2 Ce qui manque

- **Aucun écran ne montre l'argent dans son ensemble.** Le service financier voit les auditeurs un par un et les reçus. Il n'a ni total, ni taux, ni ventilation, ni export.
- **Aucun lien entre un versement annulé et celui qui le remplace.** Aujourd'hui, corriger, c'est demander au responsable d'annuler, puis ressaisir. Entre les deux gestes, l'argent disparaît du compte. Si le versement était confirmé, l'auditeur perd la confirmation, et parfois l'accès à eCampus. Les deux reçus ne se citent pas. L'auditeur ne comprend pas ce qui s'est passé.
- **Le service financier ne peut pas initier une annulation** : il n'a pas `scolarite.versement_annuler`, et il ne doit pas l'avoir (séparation des tâches). Il dépend d'un échange informel avec la scolarité.

La « suppression » envisagée par le chef de projet est d'ailleurs impossible : la base la refuse. Il faut donc donner au service financier un **geste propre et tracé**.

**En attendant cette livraison** : le responsable annule le versement avec un motif explicite (par exemple « Erreur de saisie du montant, ressaisi sous la référence … »), puis le service financier ressaisit.

## 3. Principes

1. **Rien ne s'efface, rien ne se modifie.** Un versement, un reçu, une imputation, une confirmation sont des pièces. Une erreur se corrige par une **pièce d'annulation** suivie, s'il y a lieu, d'une **nouvelle pièce**, liées entre elles. C'est la contre-passation des usages comptables (droit comptable OHADA, SYSCOHADA révisé : tout enregistrement s'appuie sur une pièce justificative et a un caractère définitif ; une écriture erronée ne se rature ni ne s'efface, elle s'annule par une écriture inverse).
2. **Les montants lisent tout l'argent enregistré, les états lisent l'argent confirmé.** Ainsi, le versé, le reste et les taux comptent tout l'argent imputé, avec « dont X à confirmer ». Les effectifs « à jour », « en retard » et « soldé » et l'accès à eCampus ne comptent que l'argent confirmé. C'est la règle de `SituationFinanciere` depuis le 15/11/2026 : l'espace Finances la reprend sans la redéfinir.
3. **Un seul calcul.** Le tableau de bord et l'analyse agrègent en SQL les expressions qui existent déjà (`InscriptionAnnuelle::VERSE_COMPTE`, `VERSE_CONFIRME`, `DU_*`). Un test vérifie que leurs totaux égalent la somme des `SituationFinanciere` calculées une à une.
4. **Deux regards sur toute correction d'argent**, sauf une rectification descriptive immédiate, encadrée (§7.4, question Q2).
5. **Une correction à montant et auditeur inchangés ne change rien pour l'auditeur** : ni son compte, ni sa confirmation, ni son accès.

## 4. Menu « Finances »

### 4.1 Groupe de navigation

Un nouveau groupe `GroupeNavigation::FINANCES` (« Finances ») prend place juste après « Scolarité » dans `ORDRE`. On y trouve :

| Écran | Nouveau ou déplacé | Contenu |
|---|---|---|
| Tableau de bord | nouveau (page) | Indicateurs du §5 |
| Analyse | nouveau (page) | Tableau croisé filtrable, export Excel (§6.1) |
| Versements | nouveau (liste) | Tous les versements, statuts, filtres, export, action « Enregistrer un versement » (§6.2) |
| Reçus | déplacé depuis Scolarité | `RecuVersementResource`, enrichi (§6.3) |
| Corrections | nouveau (liste) | Pièces de correction et demandes en cours (§7.8) |

Ce qui reste dans le groupe Scolarité : « Versements à confirmer », qui est la tâche de la scolarité, « Listes et états », la fiche auditeur et les prises en charge.

### 4.2 Accès

L'accès au groupe passe par une nouvelle permission sensible, `finances.consulter` (« Consulter l'espace Finances : tableau de bord, analyse, listes des versements, des reçus et des corrections »). Elle est accordée à `service_financier`, `scolarite_responsable`, `admin` et `super_admin` (question Q7).

Les exports nominatifs de l'espace exigent `scolarite.export`, accordée en plus au `service_financier` (§9). Sans elle, l'écran s'affiche mais les boutons d'export n'apparaissent pas.

## 5. Tableau de bord : indicateurs

### 5.1 Périmètre et notations

**Filtres** du tableau de bord, en tête de page :

- année académique (par défaut, l'année de l'inscription courante la plus récente) ;
- promotion (campagne du dossier) ;
- niveau (M1, M2) ;
- parcours (`inscriptions_annuelles.parcours_id`, à défaut la spécialité du dossier, à défaut « Non renseigné ») ;
- statut de l'auditeur.

Par défaut, tous les statuts sont retenus. Les dossiers retirés sont exclus, sauf si l'on coche « Inclure les dossiers retirés », comme dans « Listes et états ».

**P** désigne l'ensemble des inscriptions annuelles qui répondent aux filtres. Pour une inscription *i* de P :

| Notation | Définition | Source |
|---|---|---|
| `brut(i)` | `montant_inscription + montant_tranche1 + montant_tranche2` | `inscriptions_annuelles` (copie du barème) |
| `remise(i)` | `min(remise_montant, brut(i))` | idem |
| `du(i)` | `brut(i) − remise(i)` (définition D1) | idem |
| `du_ins(i)`, `du_t1(i)`, `du_t2(i)` | dû de chaque palier après remise (la remise s'impute de la 2e tranche vers l'inscription) | expressions `DU_*` d'`InscriptionAnnuelle` |
| `imp(i)` | somme des imputations actives (tout l'argent) | `VERSE_COMPTE` |
| `impc(i)` | somme des imputations actives des versements confirmés | `VERSE_CONFIRME` |
| `reste(i)` | `du(i) − imp(i)` (jamais négatif, R7) | calcul |
| `exig(i)` | `du_ins(i)`, plus `du_t1(i)` si `tranche1_echeance` est passée, plus `du_t2(i)` si `tranche2_echeance` est passée (jour de Yaoundé) | barème |

### 5.2 Indicateurs d'argent (tout l'argent, avec la part à confirmer)

| # | Indicateur | Formule | Remarques |
|---|---|---|---|
| A1 | Dû brut | Σ `brut(i)` | Ce que donne le barème avant toute remise |
| A2 | Remises accordées | Σ `remise(i)` | |
| A3 | **Total dû** | Σ `du(i)` = A1 − A2 | |
| A4 | **Total versé** | Σ `imp(i)` | L'imputé, comme dans la situation de chaque auditeur (D1) |
| A5 | dont confirmé | Σ `impc(i)` | |
| A6 | dont à confirmer | A4 − A5 | Affiché « dont X à confirmer », comme partout |
| A7 | **Reste à recouvrer** | Σ `reste(i)` = A3 − A4 | |
| A8 | **Taux de recouvrement** | A4 ÷ A3 | En %, une décimale ; « — » si A3 = 0 |
| A9 | Taux de recouvrement confirmé | A5 ÷ A3 | |
| A10 | Exigible à ce jour | Σ `exig(i)` | |
| A11 | Impayé échu | Σ max(0, `exig(i)` − `imp(i)`) | Ce qui aurait dû être payé et ne l'est pas |
| A12 | Taux de recouvrement de l'exigible | Σ min(`imp(i)`, `exig(i)`) ÷ A10 | Mesure le retard, sans pénaliser l'avance prise sur une tranche non échue |
| A13 | Avance au compte | Σ des disponibles (`dispo(v)`, D1) des versements valides des auditeurs de P | Toutes années confondues, libellé « avance au compte des auditeurs du périmètre ». Elle n'entre ni dans A4 ni dans A7. |

**Cohérence avec la fiche de l'auditeur.** Pour un seul auditeur, A3, A4, A6 et A7 sont exactement le dû, le versé, le « dont à confirmer » et le reste de sa fiche.

### 5.3 Effectifs (argent confirmé)

| # | Indicateur | Définition |
|---|---|---|
| E1 | **Inscrits** | nombre d'inscriptions de P (une par auditeur et par année) |
| E2 | Inscription non soldée | catégorie `LibelleSituation::NON_SOLDEE` |
| E3 | **À jour** | `A_JOUR` : droits soldés, aucune tranche échue impayée |
| E4 | **En retard** | `EN_RETARD` : droits soldés, une tranche échue impayée |
| E5 | **Soldés** | `SOLDEE` : tout le dû est couvert |
| E6 | À jour ou soldés | E3 + E5 |
| E7 | En retard faute de confirmation | En retard ou non soldés sur l'argent confirmé, mais pas sur tout l'argent : ils attendent leur passage à la scolarité |
| E8 | Avec un versement à confirmer | `imp(i) > impc(i)` |
| E9 | Accès eCampus ouvert | scope `accesOuvert` (droits soldés sur l'argent confirmé, ou déblocage en cours) |

Les catégories E2 à E5 sont exactement celles de `LibelleSituation` (fiche, liste des auditeurs, « Listes et états »), et leur somme vaut E1. Elles sont calculées par les scopes SQL existants (`inscriptionSoldee`, `enRetard`, `entierementSoldee`), sans nouvelle règle.

### 5.4 Ventilations

Les indicateurs A3 à A8, E1 et E3 à E5 sont ventilés dans des tableaux compacts :

- **par promotion**, **par niveau** et **par parcours** : regroupement de P ;
- **par statut de l'auditeur** (actif, suspendu, abandon, exclu, diplômé). Le dû d'un auditeur en abandon reste une créance : le service financier doit la voir ;
- **par payeur**. Le payeur est porté par chaque versement :
  - pour l'argent (A4, A5), la ventilation suit `versements.payeur` (auditeur, employeur, autre), par jointure des imputations sur leur versement ;
  - pour le dû et les effectifs, l'inscription est classée « Prise en charge » si l'auditeur est rattaché à un engagement de prise en charge de l'année (lot A2) ou s'il a au moins un versement valide de payeur `employeur` sur l'inscription ; sinon « Autofinancé ».
- **par année académique**, quand le filtre d'année est levé : une ligne par année.

### 5.5 Évolution mensuelle

Un tableau et un graphique sur les 12 derniers mois, ou sur les mois de l'année académique choisie. Mois au sens de Yaoundé. Pour chaque mois *m* :

| Colonne | Définition |
|---|---|
| Encaissé | Σ `montant` des versements **valides** dont `date_versement` tombe en *m* et dont l'inscription désignée est dans P (vue nette : un versement annulé plus tard sort de son mois) |
| Enregistré | Σ `montant` des versements dont `created_at` tombe en *m*, annulés compris (vue journal) |
| Annulé | Σ `montant` des versements dont `annule_at` tombe en *m*, remplacés compris |
| Confirmé | Σ `montant` des versements dont la confirmation (`confirme_at`) tombe en *m* |
| Corrections | nombre de pièces de correction du mois (§7) |
| Cumul encaissé | cumul de la colonne Encaissé depuis le début de l'année académique, comparé au total dû (A3) |

Le graphique montre l'encaissé mensuel en barres et le cumul face au dû en courbe. La date de référence est discutée à la question Q9.

### 5.6 Ancienneté des impayés

Pour chaque inscription de P et chaque palier échu :

- la part impayée est calculée comme dans `SituationFinanciere` : tout l'argent imputé remplit l'inscription, puis la 1re tranche, puis la 2e ;
- son retard est le nombre de jours depuis l'échéance du palier.

Échéances :

- 1re et 2e tranche : dates du barème (`tranche1_echeance`, `tranche2_echeance`) ;
- droits d'inscription : le barème ne porte qu'un texte libre (`inscription_echeance`). Ils sont réputés exigibles **à la création de l'inscription annuelle** (question Q10).

Classes de retard : non échu, 1 à 30 jours, 31 à 60, 61 à 90, 91 à 180, plus de 180. Pour chaque classe, on donne le montant impayé et le nombre d'auditeurs. Un auditeur compte dans la classe de son palier impayé le plus ancien.

### 5.7 Tuiles du haut

Huit tuiles :

1. Total dû ;
2. Total versé, avec « dont X à confirmer » ;
3. Reste à recouvrer ;
4. Taux de recouvrement ;
5. Impayé échu ;
6. Inscrits ;
7. À jour ou soldés ;
8. En retard.

Chaque tuile ouvre la liste correspondante, déjà filtrée : liste des auditeurs pour les effectifs, liste des versements pour l'argent.

### 5.8 Performance

Les volumes sont faibles (environ 600 auditeurs et 2 000 versements par an). Tout se calcule à la lecture, en quelques requêtes agrégées, sans table de cumul ni vue matérialisée : aucune donnée d'argent en double (ADR-0010, point 3). Le service `IndicateursFinanciers` est en lecture seule, rassemble les requêtes et s'appuie sur des scopes nommés. Le SQL brut se limite aux expressions déjà autorisées par les ADR-0010 et 0013.

## 6. Analyse et listes

### 6.1 Analyse

Une page « Analyse » donne un tableau croisé.

- **Filtres** : ceux du §5.1, plus la période (dates du versement), le payeur et la situation (catégories de `LibelleSituation`).
- **Lignes** : un regroupement au choix parmi promotion, niveau, parcours, année académique, payeur, statut de l'auditeur, situation et mois. Un second regroupement est facultatif (par exemple, promotion puis niveau).
- **Colonnes** : inscrits, dû brut, remises, dû, versé, dont à confirmer, reste, taux de recouvrement, exigible, impayé échu, à jour, en retard, soldés.
- **Ligne de total** : elle refait le calcul sur tout le périmètre, et ne somme donc pas les taux.
- **Détail** : un clic sur une ligne ouvre la liste des auditeurs ou des versements avec les mêmes filtres.
- **Export Excel** (`scolarite.export`), en PhpSpreadsheet comme `SituationFinanciereExcel` :
  - feuille « Synthèse » : critères en tête, puis le tableau affiché ;
  - feuille « Auditeurs » : une ligne par inscription, avec matricule, nom, promotion, niveau, parcours, statut, payeur, dû, versé, à confirmer, reste, exigible, impayé échu, situation et ancienneté ;
  - feuille « Versements » : les versements du périmètre.

  Les montants sont des nombres, pas du texte : les financiers peuvent refaire leurs calculs. Chaque export est écrit au journal `scolarite` avec ses critères, jamais avec une personne (comme `ListesAuditeurs`).

### 6.2 Liste des versements

Tous les versements, ceux de la plateforme comme ceux de la reprise. Une ligne par versement.

- **Colonnes** :
  - date du versement et date d'enregistrement ;
  - matricule et nom de l'auditeur ;
  - année, niveau et parcours de l'inscription désignée ;
  - montant, mode, référence et payeur ;
  - règlement d'organisme, le cas échéant ;
  - auteur de la saisie ;
  - reçu en vigueur ;
  - confirmation (date et agent) ;
  - **statut**.
- **Statuts**, exclusifs et déduits des tables, jamais stockés :
  - **Enregistré** : valide, non confirmé (« à confirmer ») ;
  - **Confirmé** : valide et confirmé ;
  - **Annulé** : annulé, sans pièce de correction de remplacement ;
  - **Remplacé** : annulé par une pièce de correction qui désigne un versement remplaçant (lien vers lui).

  Deux marques s'y ajoutent : « Remplace … » sur un versement remplaçant (lien vers l'annulé) et « Correction demandée » tant qu'une demande est en cours (§7).
- **Filtres** :
  - statut, période (date du versement ou date d'enregistrement), mode, payeur, origine (saisie, règlement d'organisme, reprise) ;
  - auteur de la saisie ;
  - année, promotion, niveau et parcours ;
  - montant minimal et maximal ;
  - avec ou sans reçu en vigueur, avec ou sans bordereau ;
  - « Plus de 7 jours sans confirmation ».

  Par défaut, la liste montre les versements valides (Enregistré et Confirmé).
- **Recherche** : référence, matricule, nom, numéro de reçu.
- **Pied de tableau** : nombre de lignes et somme des montants des lignes filtrées. Les annulés n'entrent dans la somme que si le filtre les montre, et la somme l'indique alors.
- **Actions de ligne** :
  - ouvrir la fiche de l'auditeur ;
  - télécharger le reçu ;
  - voir le bordereau (URL signée de 30 minutes) ;
  - « Corriger ce versement » (§7).
- **Action d'en-tête** : « Enregistrer un versement ». Le service financier choisit l'auditeur par son matricule ou son nom, puis remplit le formulaire actuel de la fiche (`RegistreVersements::encaisser`, un seul geste avec le reçu). Il n'a plus besoin de passer par la fiche.
- **Export Excel** (`scolarite.export`) des lignes filtrées.

### 6.3 Liste des reçus

`RecuVersementResource` passe dans le groupe Finances et s'ouvre à `finances.consulter`. Les actions restent réservées à `scolarite.recu_emettre`.

- Statut : **En vigueur**, **Annulé**, **Annulé et remplacé** (lien vers le reçu qui le remplace, §7.6).
- Colonnes ajoutées :
  - écriture d'annulation (`-A`), avec son motif et son auteur ;
  - reçu remplacé ou remplaçant ;
  - numéro de la pièce de correction.
- Filtres ajoutés : statut, période d'émission, émetteur, mode, montant.
- Export Excel : le **registre des reçus** de l'année civile, trié par numéro, avec les annulations. Il sert au rapprochement avec la comptabilité.
- Bandeau de contrôle : « Numérotation RCPT-AAAA continue : du n° 1 au n° N, aucun numéro manquant ». C'est le résultat du contrôle `RCPT_CONTINUITE` (§10).

## 7. Corriger ou annuler un versement

> **Mise à jour du 25/11/2026** : toute modification ou annulation est désormais validée par un administrateur (fin de la rectification par le service financier seul, Q2, et de l'annulation directe par le responsable, Q11). Voir `module-4-scolarite-validation-admin.md`.

### 7.1 Les cas à couvrir

| Cas | Exemple | Ce qu'il faut faire |
|---|---|---|
| C1. Erreur descriptive | référence, date, mode ou payeur mal saisis | Remplacer le versement par un versement identique, sauf le champ corrigé ; réémettre le reçu |
| C2. Erreur de montant | un zéro de trop ou de moins | Remplacer par un versement au bon montant ; réémettre le reçu |
| C3. Mauvais auditeur | versement saisi sur la fiche d'un homonyme | Annuler chez l'un, enregistrer chez l'autre, avec un reçu au bon nom |
| C4. Versement à annuler sans remplacement | doublon, versement fictif, virement ou chèque rejeté par la banque | Annuler, sans nouveau versement |
| C5. Reçu seul erroné | nom de l'auditeur corrigé dans son dossier depuis l'émission | Annuler le reçu et en réémettre un pour le **même** versement (geste existant du service financier) : il n'y a pas d'argent à corriger |
| C6. Part d'un règlement d'organisme | erreur sur la répartition ou sur le règlement | Le règlement ne s'annule qu'en entier (lot A2) |

Situations aggravantes, à traiter dans chaque cas : le versement est **déjà confirmé**, l'auditeur a **accès à eCampus**, le versement est **imputé sur plusieurs années**, le dossier est **retiré**.

### 7.2 Approches comparées

**Approche A — Annulation par le responsable, puis ressaisie libre (statu quo outillé).** Le service financier demande l'annulation par message ; le responsable annule ; le service financier ressaisit.

- Avantages : rien à développer. Les deux regards existent déjà.
- Risques :
  - aucun lien entre les deux versements ni entre les deux reçus : le contrôle ne sait pas reconstituer ce qui s'est passé ;
  - l'argent disparaît du compte entre les deux gestes ;
  - un versement confirmé perd sa confirmation : l'auditeur doit revenir à la scolarité, et son accès peut tomber ;
  - l'auditeur reçoit un nouveau reçu sans explication ;
  - la demande se perd dans les échanges informels.

**Approche B — Contre-passation liée, atomique, à double regard.** Le service financier saisit une **demande de correction** sur le versement : nature (remplacement ou annulation), valeurs corrigées, catégorie, motif et pièce s'il y a lieu. Le responsable ou un administrateur l'approuve par le circuit des demandes du lot 14c. L'exécution fait, en **une transaction** et sous le verrou de l'auditeur :

1. l'annulation du versement erroné ;
2. l'écriture d'annulation de son reçu ;
3. le nouveau versement, lié à l'ancien ;
4. ses imputations ;
5. son reçu, qui cite celui qu'il remplace ;
6. le report de la confirmation, s'il y a lieu ;
7. la **pièce de correction** numérotée.

- Avantages :
  - aucun état intermédiaire : l'auditeur ne perd jamais son argent ;
  - lien complet entre versements, reçus et pièce, avec motif, avant/après et deux auteurs ;
  - la séparation des tâches est garantie par le circuit existant (le demandeur ne décide jamais) ;
  - réutilise `RegistreVersements`, `ImputationsCompte`, `RecusVersements` et `Demandes`.
- Risques :
  - délai du second regard (24 h prévues, relances du lot 14b-2) ;
  - une table de plus, et une extension du circuit des demandes, qui doit exécuter avec deux identités (§7.5) ;
  - l'auditeur attend à la scolarité si une correction est en cours sur un versement à confirmer.

**Approche C — Rectification immédiate par le service financier seul, dans une fenêtre.** Tant que la scolarité n'a pas confirmé, le service financier corrige lui-même, avec la même mécanique que B (pièce, liens, reçu), mais sans second regard.

- Avantages : rapide, ce qui couvre la faute de frappe découverte dans l'heure. Pas d'effet sur l'accès, puisque l'argent non confirmé n'ouvre rien.
- Risques :
  - un seul regard sur de l'argent. Un agent pourrait baisser le montant d'un versement en espèces après avoir remis le reçu initial ;
  - deux chemins à maintenir.

**Écartée — Modification en place avec historique** (UPDATE de la ligne, ancienne valeur en table d'historique). Elle contredit l'immuabilité garantie par la base (D1, lot 4) et l'usage comptable. Le reçu figé contredirait le versement modifié. Il faudrait affaiblir trois déclencheurs.

### 7.3 Recommandation

**B pour tous les cas d'argent, complétée par une version restreinte de C** (question Q2) :

- **B, la demande de correction à double regard**, couvre C1 à C4, que le versement soit confirmé ou non.
- **C, la rectification immédiate**, n'est ouverte au service financier seul qu'à quatre conditions : erreur **descriptive** (référence, date, mode ou payeur), montant et auditeur **inchangés**, versement **non confirmé**, enregistré **depuis moins de 48 heures**. Elle produit la même pièce de correction que B, avec la marque « rectification immédiate ». Une baisse de montant, un changement d'auditeur et une annulation sans remplacement passent toujours par B.
- **C5**, le reçu seul, reste le geste existant. On y ajoute seulement le lien du nouveau reçu vers le reçu annulé (§7.6).
- **C6**, la part d'un règlement, reste hors de B dans cette livraison : le règlement s'annule en entier puis se ressaisit (question Q6).
- **L'annulation directe par le responsable** (`RegistreVersements::annuler`) est gardée pour C4 quand c'est la scolarité qui constate l'erreur, avec une catégorie de motif obligatoire (question Q11). Elle produit aussi une pièce de correction de nature « annulation », pour qu'aucune annulation n'échappe à la liste des corrections.

### 7.4 Règles de la correction

**R-C1. Pièce de correction.** Toute correction produit une ligne de `corrections_versements` (§8.1), numérotée `COR-AAAA-NNNNN`, continue sur l'année civile, sous verrou d'un compteur, comme les reçus (question Q12). La pièce est immuable.

**R-C2. Natures.**

- `remplacement` : un versement annulé, un versement remplaçant.
- `annulation` : un versement annulé, aucun remplaçant.

**R-C3. Catégories de motif** (liste fermée), plus un motif libre d'au moins 10 caractères :

- erreur de montant ;
- erreur d'auditeur ;
- erreur de référence ;
- erreur de date ;
- erreur de mode ;
- erreur de payeur ;
- doublon ;
- paiement rejeté par la banque ;
- autre.

**R-C4. Ce qui peut changer dans un remplacement** : montant, date du versement, mode, référence, payeur, auditeur (et donc son inscription désignée) et scan du bordereau. L'inscription désignée d'un même auditeur ne change pas par ce geste : c'est l'affectation de D4. La valeur avant et la valeur après de chaque champ modifié sont figées dans la pièce (`champs`, JSONB). Un remplacement qui ne change rien est refusé.

**R-C5. Refus**, avec un message pour l'agent :

- versement déjà annulé ;
- versement part d'un règlement d'organisme (C6) ;
- versement imputé sur plusieurs années : même refus que R4.1, levé par D4 ;
- remplacement vers un dossier retiré (l'annulation seule reste possible) ;
- doublon de référence avec un **autre** versement valide (l'index unique tranche) ;
- demande déjà en cours sur ce versement (index unique du lot 14c).

**R-C6. Ordre d'écriture**, dans une transaction :

1. Verrous des auditeurs concernés par `id` croissant. Il y en a deux en C3 : c'est l'ordre prévu par R8 pour D4.
2. R4 sur le versement erroné : refus R4.1, annulation de ses imputations, puis du versement (motif « Correction COR-… : <catégorie> »), puis écriture d'annulation `-A` de son reçu en vigueur. **R3 n'est pas appliquée à ce moment** : l'avance d'autres versements ne doit pas venir combler un reste que le remplaçant va couvrir.
3. Le versement remplaçant : `saisi_par` = le demandeur (service financier), même vérification de doublon, puis R2.
4. R3, une seule fois, pour chaque auditeur concerné.
5. Le reçu du remplaçant, avec `remplace_recu_id` (§7.6) et `emis_par` = le demandeur.
6. La pièce de correction, puis le report de la confirmation (R-C8).
7. Le journal `scolarite`.

Le déclencheur de cohérence de D1 vérifie chaque instruction. Si la correction a le même auditeur et le même montant, **les imputations finales portent sur les mêmes inscriptions, pour les mêmes montants**, et la situation est inchangée. Un test le vérifie.

Après la validation de la transaction :

- l'avis à l'auditeur (R-C10) ;
- la régularisation de l'accès anticipé (A1) ;
- la notification des accès, si la correction solde des droits.

**R-C7. Effet sur l'accès : prévision obligatoire.** La demande et l'écran de décision affichent, avant toute écriture, la prévision du planificateur (sans base ni horloge, D1) :

- situation avant et après : versé, à confirmer, reste ;
- avance avant et après ;
- **accès eCampus avant et après, sur l'argent confirmé**.

Une correction qui **fermerait** un accès ouvert affiche un avertissement rouge. Le décideur doit cocher « Je sais que cette correction ferme l'accès eCampus de l'auditeur ». Par construction (R-C8), cela n'arrive jamais à montant et auditeur inchangés.

**R-C8. Confirmation.** Une confirmation n'est jamais modifiée. Elle se **reporte** par une nouvelle ligne de `confirmations_versements`, liée à la pièce (§8.3) :

- versement erroné **non confirmé** : le remplaçant est « à confirmer », comme tout versement. La relance J+7 part de la date du remplaçant.
- versement erroné **confirmé**, **même montant et même auditeur** : la confirmation est **reportée d'office** sur le remplaçant. Elle garde l'agent et la date de la confirmation d'origine ; le scan reste celui de l'origine, lu par le lien. L'auditeur ne revient pas à la scolarité et son accès ne bouge pas.
- versement erroné **confirmé**, **montant ou auditeur changé** : le décideur, qui détient `scolarite.versement_confirmer` (le responsable, `admin` et `super_admin` l'ont tous), atteste sur le scan de la confirmation d'origine que le bordereau justifie le nouveau montant ou le bon auditeur. La confirmation reportée porte alors son nom et la date du jour. Sans cette attestation, la demande ne peut qu'être rejetée, et l'auditeur repasse à la scolarité.
- Le contrôle croisé tient toujours : le remplaçant est saisi par le service financier, et la confirmation reportée porte un agent de la scolarité ou le décideur, jamais le demandeur.

Voir la question Q3.

**R-C9. Pendant une demande en cours**, sur le versement visé :

- la confirmation par la scolarité est refusée (« Une correction est demandée (DM-…) : attendez la décision ») ;
- la relance J+7 de l'auditeur est suspendue ;
- le responsable qui voudrait annuler directement est renvoyé vers la décision de la demande ;
- l'empreinte de la demande couvre la ligne du versement, l'existence de sa confirmation et le reçu en vigueur. Tout changement entre la soumission et l'exécution fait échouer la demande (mécanisme du lot 14c).

**R-C10. Avis à l'auditeur** (question Q5) :

- **Remplacement** : l'avis du nouveau reçu (lot 4 §5) mentionne le reçu remplacé. SMS en ASCII, un segment, l'adresse en fin de message sans ponctuation après. Exemple de forme : `PSSFP : votre recu RCPT-AAAA-NNNNNN de <montant> FCFA remplace le recu RCPT-AAAA-NNNNNN, annule. Il est dans votre espace <adresse>`. Avec un montant de 9 chiffres, le message fait moins de 160 caractères. L'e-mail reprend les deux numéros et la nature de la correction, sans le motif interne.
- **Annulation sans remplacement** : un SMS et un e-mail. Exemple de forme : `PSSFP : votre recu RCPT-AAAA-NNNNNN de <montant> FCFA a ete annule par le service financier. Details dans votre espace <adresse>`. Pour un versement sans reçu, on cite la date et le montant.
- Les avis partent après la validation de la transaction, passent par le circuit des messages aux auditeurs (motif `correction`) et sont réaffichés dans l'espace. Un échec d'envoi ne défait jamais la correction.
- Aucun avis à la soumission d'une demande ni à son rejet : l'auditeur ne voit que les faits.

**R-C11. Visibilité dans l'espace de l'auditeur** (question Q4) :

- « Mes versements » ne liste que les versements valides, comme aujourd'hui : l'argent annulé ne compte pas.
- Un versement remplaçant porte la mention « Remplace le reçu RCPT-… (annulé le JJ/MM/AAAA) ». Le reçu annulé reste téléchargeable depuis cette mention, avec sa marque ANNULÉ et la référence du reçu qui le remplace.
- Une annulation sans remplacement n'apparaît pas dans la liste. L'auditeur en a été averti (R-C10), et son relevé de compte (D2) la montrera.
- Dans l'administration, toutes les lignes restent visibles avec leur statut (§6.2).

**R-C12. Numérotation sans trou.** Le reçu annulé garde son numéro, et son écriture d'annulation prend le numéro `-A`. Le reçu du remplaçant prend le numéro suivant, dans la même transaction. Aucun numéro n'est consommé hors transaction : une correction refusée ou annulée par la base n'en laisse aucun. Les pièces de correction suivent la même règle.

### 7.5 Circuit de la demande (lot 14c étendu)

- **Nouveau type** `corriger_versement`.
  - Libellé : « Correction ou annulation d'un versement ».
  - Famille : annulation.
  - Objet : le `Versement`.
  - Permission des décideurs : `scolarite.versement_annuler`.
  - Visa du contrôle interne : non (question Q1).
  - Délai : 24 heures.
  - Soumis depuis l'écran de son objet (`DEPUIS_LEUR_ECRAN`) : l'action « Corriger ce versement » de la liste des versements et de la fiche.
- **Paramètres** (`parametres`, figés) : nature, catégorie, valeurs corrigées, chemin du nouveau scan éventuel. Une contrainte `CHECK` exige la nature et la catégorie pour ce type, comme pour le moratoire.
- **Visibilité** : le type est ouvert à qui détient `scolarite.versement_corriger` (`VOIR`).
- **Exécution à deux identités.** `TypesDemande::executer` reçoit aussi la demande, donc le demandeur. L'annulation est faite au nom du décideur (`annule_par`), qui a `versement_annuler`. Le remplaçant et son reçu sont faits au nom du demandeur, qui doit **encore** détenir `versement_corriger` et `recu_emettre` au moment de l'exécution, faute de quoi la demande passe en échec d'exécution. Chacun agit dans son propre droit : aucun droit n'est prêté.
- **Fiche de la demande** : versement d'origine et reçu, tableau avant/après, prévision (R-C7), scan d'origine (URL signée), case d'attestation (R-C8) et case « fermeture d'accès » (R-C7) quand elles s'appliquent.
- **Action directe.** Un compte qui détient à la fois `versement_corriger`, `versement_annuler` et `recu_emettre` (en pratique `super_admin`) corrige directement, sans demande. Le circuit refuse en effet une demande à qui « détient déjà ce droit ». `SEC_SEPARATION` signale ce cumul (§10).

### 7.6 Reçus

- `recus_versements.remplace_recu_id` : nouvelle colonne nullable, écrite à l'insertion. Elle désigne le reçu annulé que ce reçu remplace : celui du versement corrigé (B, C), ou le dernier reçu annulé du **même** versement (C5, réémission simple).
- Le **nouveau reçu** porte « Remplace le reçu RCPT-… annulé le JJ/MM/AAAA (correction COR-…) ». C'est une mention figée, lue dans les colonnes de la ligne.
- L'**écriture d'annulation** (PDF `-A`) et le PDF du reçu annulé portent « ANNULÉ — remplacé par RCPT-… », lu par le lien inverse. Le lot 4 l'admet déjà : un reçu annulé change d'aspect (marque ANNULÉ) sans que sa ligne change.
- L'objet du nouveau reçu se lit dans ses imputations, comme tout reçu (D1 §9.2). Effet connu : le remplaçant a un `id` plus grand que les versements enregistrés entre-temps, si bien que le découpage par palier de son objet peut différer de celui du reçu remplacé (par exemple « 1re tranche » au lieu de « droits d'inscription »), à total égal. C'est acceptable (l'objet est informatif). Un correctif possible est noté au §11, risque 4.

### 7.7 Annulation directe par le responsable

Le geste existant reste, avec trois ajouts :

- une catégorie de motif obligatoire (R-C3) ;
- une pièce de correction de nature `annulation`, sans demande (`demande_id` nul, `executee_par` = le responsable) ;
- l'avis R-C10 (Q5 retenue le 16/11/2026).

### 7.8 Écran « Corrections »

C'est la liste des pièces de correction.

- Colonnes :
  - numéro, date, nature, catégorie ;
  - matricule de l'auditeur (et du second auditeur en C3) ;
  - versement annulé et versement remplaçant (référence, montant), écart de montant ;
  - champs modifiés ;
  - reçu annulé (`-A`) et nouveau reçu ;
  - demandeur, décideur, numéro de demande ;
  - rectification immédiate (oui ou non), confirmation reportée (d'office, attestée ou non) ;
  - motif.
- Un onglet « En cours » liste les demandes `corriger_versement` en attente, avec leur échéance.
- Filtres : période, nature, catégorie, demandeur, décideur.
- Export Excel (`scolarite.export`).

## 8. Données

Toutes les migrations sont additives. Aucune ligne existante n'est réécrite.

### 8.1 `corrections_versements` (nouvelle, en ajout seul)

| Colonne | Type | Règle |
|---|---|---|
| `id` | bigserial | interne |
| `uuid` | uuid, unique | routes et API |
| `numero` | varchar(20), unique | `COR-AAAA-NNNNN` |
| `annee_numero`, `sequence` | smallint, integer | unique ensemble |
| `nature` | varchar(20) | `remplacement` ou `annulation` |
| `categorie` | varchar(30) | liste fermée R-C3 |
| `versement_annule_id` | FK `versements`, **unique** | un versement n'est corrigé qu'une fois |
| `versement_remplacant_id` | FK `versements`, unique, nullable | nul si et seulement si nature = `annulation` |
| `annulation_recu_id` | FK `annulations_recus`, nullable | écriture `-A` passée par la correction |
| `recu_remplacant_id` | FK `recus_versements`, nullable | reçu du remplaçant |
| `champs` | jsonb | `{champ: {avant, apres}}`, objet non vide pour un remplacement |
| `motif` | varchar(2000) | au moins 10 caractères |
| `demande_id` | FK `demandes_autorisation`, nullable | nul pour une rectification immédiate, une action directe ou une annulation directe |
| `demandee_par` | FK `users` | auteur de la demande ou du geste |
| `executee_par` | FK `users` | décideur, ou l'auteur lui-même pour un geste direct |
| `rectification_immediate` | boolean | |
| `confirmation_reportee` | varchar(10), nullable | `office`, `attestee` ou nul |
| `created_at` | timestamptz | |

Contraintes (SQL brut, comme pour les autres tables d'argent, ADR-0013 point 7) :

- `CHECK` sur les listes fermées, sur la cohérence entre nature et remplaçant, sur `versement_remplacant_id <> versement_annule_id` et sur la longueur du motif.
- Déclencheur `recus_immuables()` réutilisé : UPDATE et DELETE refusés.
- Troncature refusée (`compte_sans_troncature`).
- Le compteur `compteurs_corrections(annee, dernier)` est verrouillé comme celui des reçus.

**Pourquoi une table plutôt qu'une colonne `remplace_versement_id` sur `versements`.** Une colonne posée à l'insertion du remplaçant serait permise par `versements_immuables`, mais elle laisserait de côté :

- l'annulation sans remplacement ;
- le motif catégorisé ;
- l'avant/après ;
- les deux auteurs, la demande et le report de la confirmation.

La table ne touche pas la table d'argent ; elle suit le précédent de `confirmations_versements` ; et elle est elle-même la pièce de correction que le service financier peut citer.

### 8.2 `recus_versements` (modifiée)

- Colonne `remplace_recu_id` : FK nullable vers `recus_versements`, écrite à l'insertion seulement. Le déclencheur d'immuabilité n'est pas touché.

### 8.3 `confirmations_versements` (modifiée)

- Colonne `correction_id` : FK nullable vers `corrections_versements`.
- La contrainte `CHECK` devient : `reprise OR (correction_id IS NOT NULL AND confirme_par IS NOT NULL) OR (confirme_par IS NOT NULL AND justificatif_path IS NOT NULL AND attestation)`.
  - Une confirmation reportée n'a pas de scan propre : elle lit celui de la confirmation d'origine par la pièce. `Versement::pieceDejaJointe` reste donc inchangé : une pièce ne sert toujours qu'une fois.
- Le déclencheur `confirmations_versements_immuables` n'est pas touché (insertion seulement).
- `InscriptionAnnuelle::VERSE_CONFIRME` ne change pas : une confirmation reportée est une confirmation.

### 8.4 `demandes_autorisation` (modifiée)

- La liste fermée des types s'étend à `corriger_versement` (même technique que l'ajout d'`accorder_moratoire`).
- Une contrainte sur `parametres` exige `nature` et `categorie` pour ce type.

### 8.5 Journal (`scolarite`, chaîne scellée du lot 14d-1)

| Événement | Sujet | Propriétés |
|---|---|---|
| `correction_demandee` | Versement | demande, nature, catégorie, champs (avant/après) |
| `versement_corrige` | Versement annulé | pièce, nature, remplaçant, écart, confirmation reportée |
| `versement_enregistre` (existant) | Remplaçant | plus `correction` (numéro de la pièce) |
| `versement_annule` (existant) | Versement annulé | plus `correction` |
| `confirmation_reportee` | Remplaçant | pièce, origine (`office` ou `attestee`), agent |
| `finances_export` | — | écran, critères, nombre de lignes (jamais de personne) |

## 9. Permissions

| Permission | `scolarite_agent` | `scolarite_responsable` | `service_financier` | `admin` | `super_admin` |
|---|---|---|---|---|---|
| `finances.consulter` (**nouvelle**) | non (Q7) | **oui** | **oui** | **oui** | oui |
| `scolarite.export` | oui | oui | non → **oui** | oui | oui |
| `scolarite.versement_corriger` (**nouvelle**, sensible) : demander une correction, rectifier dans la fenêtre | non | non | **oui** | non | oui |
| `scolarite.versement_annuler` : décider une correction, annuler directement | non | oui | non | oui | oui |
| `scolarite.versement_confirmer` : attester lors du report (R-C8) | oui | oui | non | oui | oui |
| `scolarite.versement_saisir`, `scolarite.recu_emettre` | non | non | oui | non | oui |

- **Migrations additives** :
  - `grant_finances_consulter` : crée `finances.consulter` et l'accorde aux quatre rôles, puis accorde `scolarite.export` au service financier ;
  - `grant_scolarite_versement_corriger`.

  Chacune a son `down()`. Jamais `RolePermissionSeeder` en production. `ModulePermissionMap` (actions sensibles du module Scolarité, avec leur libellé) et `RolePermissionSeeder` suivent, pour les installations neuves. Les descriptions des rôles du service financier et du responsable se mettent à jour si elles n'ont pas été modifiées depuis l'écran Rôles (précédent de `revoke_scolarite_versement_saisir`). On régénère ensuite `docs/ops/roles.md` en production (`php artisan roles:matrice`).
- **Séparation des tâches** :
  - le service financier enregistre, émet et **demande** ; il ne décide jamais une correction et ne confirme jamais ;
  - le responsable et l'administrateur **décident** et annulent ; ils n'enregistrent pas ;
  - la scolarité **confirme**.

  Le circuit 14c interdit au demandeur de décider. Un nouveau couple entre dans `SEC_SEPARATION` : « Demander et décider une correction de versement » (`scolarite.versement_corriger`, `scolarite.versement_annuler`).

## 10. Contrôle interne

| Code | Niveau | Délai | Responsable | Cas |
|---|---|---|---|---|
| `VER_CORRECTIONS` (nouveau) | majeur | 7 j | `scolarite_responsable` | (1) chaque correction qui **baisse** le montant d'un versement confirmé, ou qui annule sans remplacement un versement confirmé ; (2) chaque agent qui dépasse sur 30 jours glissants le seuil de corrections demandées ou rectifiées (réglage `finances.seuil_corrections`, 5 par défaut). Désignation par numéro de pièce et matricule, sans nom. |
| `RCPT_CONTINUITE` (nouveau) | bloquant | 1 j | `admin` | Pour chaque année civile, un numéro manquant entre 1 et `compteurs_recus.dernier`, ou un compteur différent du plus grand numéro émis. Même contrôle pour `COR-`. |
| `SEC_SEPARATION` (existant) | — | — | — | Nouveau couple (§9) |
| `CPT_COHERENCE` (existant) | — | — | — | Inchangé : il vérifie aussi le compte après une correction |
| `VER_NON_CONFIRMES` (existant) | — | — | — | Un versement visé par une demande de correction en cours n'est pas compté (R-C9) |

## 11. Risques

| # | Risque | Parade |
|---|---|---|
| 1 | Les indicateurs divergent de la fiche de l'auditeur | Un seul calcul (§3, point 3). Test d'égalité entre agrégats SQL et somme des `SituationFinanciere` sur un jeu varié : remises, avance, à confirmer, échéances passées et futures. |
| 2 | Une correction coupe l'accès d'un auditeur | Report d'office à montant et auditeur égaux (R-C8), prévision et case de fermeture (R-C7), test d'équivalence de l'état d'accès avant et après sur les cas C1. |
| 3 | Fraude par correction à la baisse (espèces) | Toujours deux regards (B), avis à l'auditeur avec les deux numéros de reçu, cas `VER_CORRECTIONS` à chaque baisse sur un versement confirmé, seuil par agent. |
| 4 | L'objet du nouveau reçu diffère du reçu remplacé (§7.6) | Accepté ; libellé « à total égal » dans l'aide. Correctif possible plus tard : ordonner `imputeAvant` sur la chaîne de remplacement. |
| 5 | Demande en attente pendant que l'auditeur est au guichet | Délai de 24 h, relance graduée du lot 14b-2, pastille « Corrections en cours » dans l'encart « À traiter » des décideurs. Le guichet voit le motif du refus de confirmer. |
| 6 | Verrou de deux auditeurs (C3) | Ordre par `id` croissant (R8), test de concurrence sur deux corrections croisées |
| 7 | Exports nominatifs qui circulent | `scolarite.export`, journal des exports, aucune donnée d'identité dans le journal, fichiers non conservés sur le serveur |
| 8 | Versement imputé sur plusieurs années | Refus R-C5, comme R4.1. À lever avec D4, qui doit précéder D3. |
| 9 | Un décideur atteste sans vérifier | L'attestation est nominative, journalisée et soumise au contrôle `VER_CORRECTIONS` ; le scan d'origine est affiché à côté de la case |

## 12. Questions tranchées le 16/11/2026

Le Chef USI a retenu, le 16/11/2026, toutes les recommandations. La dernière colonne donne la réponse retenue ; les options écartées restent pour mémoire. **En gras**, l'option retenue.

| # | Question | Options | Retenu le 16/11/2026 |
|---|---|---|---|
| Q1 | Qui donne le second regard sur une correction ? | (a) le responsable de scolarité seul ; **(b) tout titulaire de `versement_annuler` : responsable, `admin`, `super_admin`** ; (c) (b) plus le visa du contrôle interne, comme les autres annulations du lot 14c | Retenu : **(b)**. Deux personnes suffisent : le contrôle `VER_CORRECTIONS` relit après coup les cas sensibles. Un troisième regard ralentirait un geste fréquent pour deux comptes de service financier. |
| Q2 | Le service financier peut-il rectifier seul ? | (a) jamais ; **(b) seulement une erreur descriptive (référence, date, mode, payeur), à montant et auditeur égaux, sur un versement non confirmé, dans les 48 h** ; (c) tout champ tant que le versement n'est pas confirmé | Retenu : **(b)**. Les fautes de frappe se corrigent sans attendre, et l'argent reste sous deux regards. Fenêtre de 48 h réglable. |
| Q3 | Que devient la confirmation d'un versement confirmé qu'on corrige ? | (a) toujours reconfirmer au guichet ; **(b) report d'office à montant et auditeur égaux, attestation du décideur sur le scan sinon** ; (c) report d'office dans tous les cas | Retenu : **(b)**. On ne fait jamais revenir l'auditeur pour une faute de saisie qui n'est pas la sienne, et un changement de montant ou d'auditeur est attesté par quelqu'un de la scolarité ou de l'administration. |
| Q4 | Que voit l'auditeur ? | (a) seulement le nouveau versement, sans mention ; **(b) le nouveau versement avec « Remplace le reçu … (annulé) », le reçu annulé téléchargeable ; une annulation seule n'est pas listée** ; (c) toutes les lignes annulées, barrées | Retenu : **(b)**. Il garde un reçu papier ou PDF qu'il doit pouvoir rapprocher, sans être noyé sous les lignes annulées. |
| Q5 | Faut-il prévenir l'auditeur d'une correction ? | (a) non : seul l'avis normal du nouveau reçu part (règle du lot 4 : pas d'avis à l'annulation) ; **(b) l'avis du nouveau reçu cite le reçu remplacé, et une annulation seule a son propre avis** ; (c) avis à chaque étape (demande, décision, exécution) | Retenu : **(b)**. L'avis à l'annulation est aussi une parade contre la fraude (risque 3). Cela revient sur la règle du lot 4 §5.1 B.6 pour l'annulation de versement seulement, pas pour l'annulation d'un reçu seul (C5). |
| Q6 | Comment corriger la part d'un règlement d'organisme ? | Retenu : **(a) pas dans cette livraison : annuler le règlement entier et le ressaisir (geste existant du responsable)** ; (b) corriger une part isolée ; (c) une correction de règlement entier, sur le même modèle (pièce, liens) | **(a)** maintenant, puis (c) dans un sous-lot ultérieur si les cas se présentent. Les parts se confirment et s'annulent avec leur règlement : une part isolée casserait cette règle. |
| Q7 | Qui voit le menu Finances ? | (a) le service financier, `admin` et `super_admin` ; **(b) les mêmes, plus le responsable de scolarité** ; (c) (b) plus les agents de scolarité, en lecture | Retenu : **(b)**. Le responsable décide les corrections et accorde les remises : il a besoin des chiffres. Les agents ont déjà « Listes et états ». |
| Q8 | Sur quel argent bâtir les montants du tableau de bord ? | Retenu : **(a) tout l'argent enregistré, avec « dont à confirmer »** ; (b) l'argent confirmé seulement ; (c) un interrupteur entre les deux | **(a)**. C'est la règle de la fiche, de D1 et des listes (§3, point 2). Le taux confirmé (A9) et l'effectif E7 donnent la vue confirmée sans créer un second jeu de chiffres. |
| Q9 | Quelle date pour l'évolution mensuelle ? | Retenu : **(a) la date du versement (date de valeur)** ; (b) la date d'enregistrement ; (c) les deux | **(a)** pour la courbe. Le tableau donne aussi l'« Enregistré » et l'« Annulé » du mois (vue journal), ce qui couvre (b) sans second graphique. |
| Q10 | Quand les droits d'inscription sont-ils exigibles (ancienneté) ? | Retenu : **(a) à la création de l'inscription annuelle** ; (b) à la date de rentrée de la promotion ; (c) à une nouvelle date d'échéance du barème | **(a)**. Pas de nouvelle donnée à saisir, et c'est cohérent avec la règle « droits payés avant l'accès ». (c) pourra suivre si la scolarité fixe une date. |
| Q11 | Que devient l'annulation directe par le responsable ? | (a) inchangée ; **(b) gardée, avec une catégorie de motif et une pièce de correction de nature « annulation »** ; (c) supprimée au profit du seul circuit de demande | Retenu : **(b)**. La scolarité constate aussi des erreurs (doublon au guichet), et toute annulation figure dans la liste des corrections. |
| Q12 | Faut-il numéroter les pièces de correction ? | Retenu : **(a) oui, `COR-AAAA-NNNNN`, continu sur l'année civile** ; (b) non : le numéro de demande `DM-` et l'écriture `-A` du reçu suffisent | **(a)**. Une rectification immédiate ou une annulation directe n'a pas de demande, et le service financier cite une pièce dans ses échanges avec la comptabilité. |

## 13. Découpage en sous-lots

Chaque sous-lot se livre seul, avec ses migrations et ses tests, et compte huit étapes au plus. **Ordre proposé** : F1, F4a, F4b, F2, F3, F5. Les corrections (F4a) sont le besoin le plus pressant ; F1 est petit et donne tout de suite la liste des versements où chercher le versement à corriger.

### F1 — Menu Finances, versements et reçus

1. `GroupeNavigation::FINANCES` ; migration `grant_finances_consulter` (dont `scolarite.export` pour le service financier) ; mise à jour de `ModulePermissionMap` et du seeder.
2. Statut déduit d'un versement (`Versement::statut()`, et son équivalent en scopes nommés : `enregistres`, `confirmes`, `annules`) ; marque « Remplacé » préparée pour F4a.
3. Liste « Versements » : colonnes, filtres, recherche, pied de tableau.
4. Action d'en-tête « Enregistrer un versement » (choix de l'auditeur, puis `encaisser`).
5. `RecuVersementResource` déplacée et ouverte à `finances.consulter` ; statut, filtres, registre Excel.
6. Export Excel des versements ; journal `finances_export`.
7. Tests Pest : accès par rôle, statuts, filtres, sommes, export, journal.
8. Mise à jour de `docs/ops/roles.md` (en production, après déploiement).

### F4a — Correction à double regard

1. Migrations : `corrections_versements` et son compteur, `recus_versements.remplace_recu_id`, `confirmations_versements.correction_id` avec sa contrainte, type `corriger_versement` et contrainte des paramètres ; migration de permission `grant_scolarite_versement_corriger`.
2. Planificateur : prévision d'une correction (`PlanificateurImputations::correction`), pure, sans base ni horloge.
3. Service `CorrectionsVersements` : `preparer` (contrôles R-C5 et prévision R-C7), `executer` (R-C6, R-C8, R-C12) et `annulerDirectement` (§7.7). Il passe par `RegistreVersements` et `ImputationsCompte` (variante d'annulation sans R3 immédiat).
4. `TypesDemande` et `Demandes` : nouveau type, empreinte (R-C9), exécution à deux identités (§7.5).
5. Écrans : action « Corriger ce versement » (fiche et liste), fiche de la demande enrichie (avant/après, prévision, scan, cases), catégorie de motif dans l'annulation directe.
6. Blocages R-C9 : confirmation refusée, relance suspendue, `VER_NON_CONFIRMES` adapté.
7. Reçus : mentions « Remplace » et « remplacé par » dans les deux PDF, `remplace_recu_id` posé aussi en C5.
8. Tests Pest (§14).

### F4b — Auditeur, avis et contrôle

1. API de l'espace : mention de remplacement et lien vers le reçu annulé (R-C11).
2. Espace de l'auditeur (`candidature`) : affichage dans « Mes versements », textes en `t()`.
3. Avis R-C10 (motif `correction` du journal des envois), textes du §7.4.
4. Écran « Corrections » (§7.8) et pastille des demandes en cours dans « À traiter ».
5. Contrôles `VER_CORRECTIONS` et `RCPT_CONTINUITE`, nouveau couple `SEC_SEPARATION`, réglage `finances.seuil_corrections`.
6. Tests Pest (API, avis, contrôles) et Playwright (espace : mention et téléchargement).

### F2 — Tableau de bord

1. Service `IndicateursFinanciers` (lecture seule) : périmètre P, indicateurs A1 à A13 et E1 à E9.
2. Ventilations du §5.4.
3. Évolution mensuelle (§5.5).
4. Ancienneté des impayés (§5.6).
5. Page « Tableau de bord » : filtres, tuiles, tableaux, graphique (widgets Filament).
6. Liens des tuiles vers les listes filtrées.
7. Tests Pest : égalité avec `SituationFinanciere` sur un jeu varié, classes d'ancienneté, mois de Yaoundé, accès par rôle.

### F3 — Analyse

1. Critères validés une fois (`CriteresAnalyse`, sur le modèle de `CriteresListe`).
2. Agrégation par un ou deux regroupements, ligne de total recalculée.
3. Page « Analyse » et liens de détail.
4. Export Excel en trois feuilles, montants en nombres ; journal des critères.
5. Tests Pest : regroupements, total, export (contenu des cellules), refus sans `scolarite.export`.

### F5 — Rectification immédiate (Q2 retenue le 16/11/2026)

1. Réglage de la fenêtre (`finances.fenetre_rectification_heures`, 48 par défaut).
2. Action « Rectifier » (service financier), dans les conditions de Q2.
3. Pièce avec `rectification_immediate = true`, mêmes avis et mêmes contrôles.
4. Tests Pest : bornes de la fenêtre, refus d'un changement de montant ou d'auditeur, refus après confirmation.

## 14. Tests attendus (F4a, F4b)

- **Remplacement C1** (référence) sur un versement non confirmé :
  - l'ancien versement est annulé, le remplaçant est lié, la pièce est numérotée ;
  - le reçu `-A` est passé et le nouveau reçu porte `remplace_recu_id` ;
  - les imputations finales ont les mêmes inscriptions et les mêmes montants ;
  - `scolarite:verifier-comptes` rend 0.
- **C1 sur un versement confirmé** : confirmation reportée d'office (même agent, même date) ; l'état d'accès est identique avant et après.
- **C2 à la hausse et à la baisse sur un versement confirmé** :
  - le décideur doit attester ;
  - sans attestation, l'approbation est refusée ;
  - à la baisse, l'avertissement de fermeture d'accès s'affiche et `VER_CORRECTIONS` relève un cas.
- **C3, changement d'auditeur** :
  - deux auditeurs verrouillés par `id` croissant ;
  - le reçu est au nom du bon auditeur ;
  - les deux comptes restent cohérents ;
  - test de concurrence sur deux corrections croisées.
- **C4, annulation seule** : pièce `annulation`, avis à l'auditeur, rien dans « Mes versements ».
- **Refus** : versement annulé, part de règlement, versement sur plusieurs années, remplacement vers un dossier retiré, doublon de référence, demande déjà en cours, remplacement sans changement.
- **Séparation des tâches** :
  - le demandeur ne décide pas ;
  - un agent sans `versement_corriger` ne voit pas l'action ;
  - si le demandeur perd `recu_emettre` avant l'exécution, la demande passe en échec ;
  - `SEC_SEPARATION` signale le cumul.
- **Empreinte** : une demande échoue si le versement a été confirmé, ou si son reçu a été annulé, entre la soumission et l'exécution.
- **Base de données** :
  - UPDATE et DELETE refusés sur `corrections_versements` ;
  - la contrainte de `confirmations_versements` refuse une confirmation sans scan ni pièce de correction ;
  - le compteur `COR` reste sans trou après une transaction annulée.
- **Avis** :
  - SMS en ASCII, d'un segment, finissant sur l'adresse ;
  - aucun envoi si la transaction est annulée ;
  - un échec d'envoi ne défait pas la correction.
- **Journal** : chaque événement du §8.5, avec ses propriétés et sans donnée d'identité dans les exports.

## 15. Déploiement (par sous-lot)

1. Fusionner, puis `php artisan migrate` (migrations additives, chacune avec son `down()`).
2. `php artisan optimize`, puis `php artisan queue:restart` (les avis passent par la file).
3. Régénérer `docs/ops/roles.md` (`php artisan roles:matrice`) et vérifier l'écran Rôles.
4. F4a : vérifier que `scolarite:verifier-comptes` rend 0 et que « Versements à confirmer » est inchangé.
5. F4b : vérifier le contrôle `RCPT_CONTINUITE` sur l'année en cours (aucun cas attendu).

## 16. Hors périmètre

- Affectation d'un versement à une autre année du même auditeur, réimputation manuelle, annulation sur plusieurs années : sous-lot D4.
- Remboursement d'une avance : sous-lot D5.
- Budget et prévisions de recettes : reportés, comme au lot 13c-2.
- Paiement en ligne : Phase II.
- Rapprochement automatique avec les relevés bancaires CREMINCAM : à étudier sur le modèle du lot 13c-1 (rapprochement des virements de paie).
