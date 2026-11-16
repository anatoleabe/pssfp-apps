# Module 4 — Lot 15, suite : réinscription des anciens auditeurs depuis l'archive ACMA

**Date** : 2026-11-16
**Statut** : validé ; questions du §14 **tranchées le 16/11/2026** (§2 bis) ; réalisation en sous-lots 15i à 15m
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (demande et décisions du 16/11/2026, §2)
**Spec mère** : [lot 15, reprise d'ACMA](module-4-lot15-reprise-acma.md) (décision 4, Q7, Q16 modifiées par celle-ci) · [ADR-0014](../adr/0014-reprise-acma-archive-lecture-seule.md) (avenant du 16/11)
**Specs liées** : [import des auditeurs antérieurs (P13)](module-4-import-auditeurs-anterieurs.md) · [compte de l'auditeur, D1](module-4-scolarite-comptes-d1.md) et [lot D](module-4-scolarite-comptes-auditeur-proposition.md) (D3) · [accès anticipé, A1 et A3](module-4-scolarite-acces-anticipe.md) · [encaissement et confirmation](module-4-scolarite-encaissement-confirmation.md) · [lot 2, promotions](module-4-scolarite-lot2-2026-11.md)

Ce document ne contient que des noms de colonnes, des codes de référentiel et des exemples fictifs (matricules `99…`, qui n'existent pas). Aucun nom, matricule, téléphone, e-mail ni montant réel.

## 1. Objectif

Permettre à un **ancien auditeur** (une personne qui a un dossier dans l'archive ACMA) de revenir en formation dans l'année en cours, en M1 ou en M2, et d'obtenir ses accès à la plateforme par le chemin normal :

1. **créer les promotions antérieures** qui manquent dans la plateforme ;
2. **créer l'auditeur depuis son dossier d'archive** : matricule ACMA conservé, identité et coordonnées reprises d'ACMA puis vérifiées, lien de l'archive posé et tracé comme un arbitrage ;
3. **l'inscrire dans l'année en cours** (niveau, parcours, barème, compte) ;
4. lui ouvrir ses accès **seulement** par le paiement confirmé ou par un déblocage (A1, ou moratoire A3), puis lui envoyer son lien d'accès comme à tout auditeur ;
5. **reprendre d'ACMA toutes les données des auditeurs**, coordonnées comprises (décision 3 du 16/11), pour que la création parte de l'archive.

Ce qui change, pour qui :

| Qui | Changement |
|---|---|
| Responsable de la scolarité | Action « Réinscrire cet ancien auditeur » sur la fiche d'archive ; action « Créer une promotion antérieure » dans Vie académique > Promotions. |
| Agents de scolarité | Section « Coordonnées (ACMA) » sur la fiche d'archive. Les réinscrits apparaissent dans la liste des auditeurs comme les autres (filtre « Réinscrit depuis ACMA »). |
| Service financier | Rien de nouveau : les réinscrits paient et sont confirmés comme les autres. |
| Ancien réinscrit | Un espace auditeur, un compte eCampus et, une fois l'accès ouvert, le lien vers sa lettre d'accès par SMS et e-mail. |
| Ancien non réinscrit | **Rien** : ni compte, ni espace, ni envoi (décision 4 du lot 15, maintenue pour eux). |
| Candidats | Rien : un nouvel auditeur n'entre que par la candidature, et seulement dans la promotion en cours. |

## 2. Décisions du chef de projet (16/11/2026)

1. **Demande initiale** (verbatim) : « il faut qu'il soit possible d'ajouter des promotions antérieures, qu'il soit possible d'inscrire un auditeur d'une ancienne promotion dans l'année en cours, M1 ou M2, et qu'il lui soit possible d'avoir les codes d'accès à la plateforme. ils doivent pouvoir être créés à partir de leurs données d'ACMA complet, donc une sorte d'importation. seuls les auditeurs de la P14 (promotion en cours) doivent être créés via candidature. »
2. **Un ancien peut revenir avec la P14.** La réinscription depuis l'archive peut rattacher l'ancien à n'importe quelle promotion, P14 comprise, en M1 ou en M2. La règle d'entrée devient :

   | Personne | Chemin unique | Promotion | Matricule |
   |---|---|---|---|
   | **Nouvel auditeur** (aucun dossier ACMA) | Candidature (portail, ou « Ajouter un retenu » pour un retenu hors portail) | **Promotion en cours seulement** (P14) | Attribué par la plateforme (`26EFP…`) |
   | **Ancien auditeur** (dossier ACMA) | Réinscription depuis l'archive | Au choix, P1 à P14 | **Matricule ACMA conservé** |

   Le garde-fou contre le doublon (ancien qui a aussi candidaté en P14) est au §8 ; le dossier qui fait foi est la question Q2.
3. **Tout importer d'ACMA pour les auditeurs**, coordonnées comprises : téléphones, e-mails, adresse, région et toute autre colonne utile des tables des auditeurs. La minimisation du sous-lot 15a (coordonnées non extraites) et la réponse à la Q16 du lot 15 sont levées pour ces données. Restent exclus les secrets et les données du personnel d'ACMA (§4).

## 2 bis. Questions tranchées le 16/11/2026

Le chef de projet retient la recommandation de Q1, Q3, Q4, Q6 et Q8 à Q14, et :

- **Q5** : réinscription **unitaire seulement** ;
- **Q7** : M2 sans M1 admis dans l'archive = **refus, sauf dérogation du responsable** (motif et pièce) ;
- **Q2** : **le dossier ACMA fait foi**, contre la recommandation. Un ancien qui a aussi candidaté en P14 garde son matricule ACMA ; la candidature P14 ne porte plus un second matricule. Mécanisme au §8.3 (conversion du dossier P14), livré en sous-lot **15m**, après 15k.

## 3. Ce que fait le code aujourd'hui (vérifié le 16/11/2026)

| Sujet | Constat | Conséquence |
|---|---|---|
| **Promotion** | Une promotion est une `CampagneCandidature` (`promotion_numero` NOT NULL, non unique, `prefix_numero` obligatoire). `PromotionResource` (Vie académique > Promotions) interdit la création (`canCreate` faux) et ne liste que les campagnes qui ont un retenu. `ImportAuditeurs::campagne` crée à la volée, close, la promotion `AA − 2012` déduite du matricule (`25…` donne P13). | Il faut un geste de création explicite ; une promotion sans retenu doit pouvoir apparaître dans la liste. |
| **Visibilité publique** | Le portail ne lit que la campagne ouverte dans sa fenêtre (`currentCampagne`) et la dernière qui a un communiqué de résultats (`derniereCampagneAvecResultats`). | Une campagne passée, close et sans communiqué n'apparaît nulle part sur le portail. À vérifier aussi pour les widgets de campagne (test). |
| **Année en cours** | Aucune notion dans la plateforme, hors `CorrespondancesAcma::ANNEE_EN_COURS` (`2026-2027`, propre à la reprise). | À créer (§6.1). |
| **Inscription** | `OuvertureAuditeur::creerInscription` crée l'inscription de **l'année de la campagne**, toujours en **M1**. | Inutilisable pour un ancien : rattaché à la P12, il serait inscrit en 2024-2025. Il faut un service d'inscription dans l'année en cours (§7.4). |
| **« Ajouter un retenu »** (PR #64) | `AjoutRetenu` accepte toute campagne ouverte ou close, crée un dossier hors PV (`submitted_at` nul, `ajoute_par`). Le matricule est ensuite **attribué** par `AttributionMatricules` (année + catégorie + rang). | Ne garde pas le matricule ACMA. Et il permet aujourd'hui d'ajouter un retenu à la P13 : à fermer (§8.1). |
| **Import P13** (`scolarite:importer-auditeurs`) | Garde le matricule du fichier, n'accepte que la forme `AA(EFP|EFSC)N…`, pose `acces_notifie_at` (aucun SMS) et laisse `moodle_password` vide. | Précédent utile (dossier hors PV, compte candidat, matricule repris), mais inadapté : formes anciennes d'ACMA refusées, et l'avis d'accès est neutralisé, alors qu'ici on le veut. |
| **Candidature** | NOT NULL : `numero_dossier` (généré par l'observateur), `campagne_id`, `phone_e164`, `phone_country`, `nom`, `prenom`, `date_naissance`, `statut`. Les autres champs du profil sont facultatifs depuis `2026_05_15_120500`. Unicité : `matricule` sur toute la table, (`campagne_id`, `phone_e164`). | Pour créer un ancien, il faut un téléphone E.164, un nom et un prénom séparés, et une date de naissance. Précision au §4 du lot 15, qui citait à tort le lieu, l'adresse et la spécialité comme obligatoires. |
| **Accès** | `AccesEcampus::accesOuvert` : inscription courante soldée sur l'**argent confirmé**, ou déblocage en cours (A1, ou moratoire A3 exécuté par `DeblocagesAcces`). `NotificationAcces::siDisponible` envoie le lien une fois par inscription, si `acces_notifie_at` est vide. | Aucun chemin nouveau : un ancien réinscrit passe par la même règle. |
| **Compte eCampus** | `ComptesEcampus` : identifiant = matricule en minuscules ; un compte existant n'est jamais modifié (`existant`) ; la création exige un **e-mail**, unique sur eCampus ; la cohorte est `PROMO{n}_TC_{année de la campagne}`. | E-mail obligatoire si aucun compte n'existe. Cohorte fausse pour un ancien rattaché à une vieille promotion (et pour un P13 redoublant) : §10. |
| **Lettre d'accès** | `LiensLettreAcces::possible` exige des codes remettables, un mot de passe connu, une date de naissance et les paramètres de la lettre **de la campagne** du dossier. | Une promotion antérieure n'a pas ces paramètres : §10. |
| **Mot de passe connu** | `AccesEcampus::motDePasseConnu` = `moodle_password` renseigné. | Si un compte eCampus existait déjà, le mot de passe généré n'est pas le bon : il faut l'effacer (§7.5). |
| **Archive** | `acma_dossiers.auditeur_id` est modifiable hors reprise et après le gel, seulement si la **dernière décision signée** de `acma_rapprochements` (ajout seul) correspond au nouveau lien (déclencheur `acma_dossiers_lecture_seule`, correctif 5 du §17 du lot 15). Le rejeu (`EtapeRapprochement`) conserve toujours une décision humaine. | La réinscription écrit d'abord sa décision `lie`, puis le lien : elle survit au rejeu et au gel sans toucher au déclencheur. |
| **Extraction** | `ExtractionAcma::COLONNES`, format `pssfp-acma-transit/2`. Colonnes non extraites de `etudiant` d'après le manifeste du transit local : `telephone`, `mobile`, `email`, `EmailB`, `adresse`, `region`, `RegionBis`, `departement`, `numcni`, `expression`, `Suspendu`, `idcampus` ; de `inscription_academic` : `parcours`, `sexe`, `idcampus`. | Liste à élargir (§4). |

**Ce qui n'existe pas encore et dont on dépend :** la réinscription en groupe (D3), le compte visible (D2), l'annulation sur plusieurs années (D4). La présente spec ne les remplace pas (§11).

## 4. Données d'ACMA : tout reprendre pour les auditeurs (sous-lot 15i)

### 4.1 Liste blanche élargie

`ExtractionAcma::COLONNES` passe au format `pssfp-acma-transit/3`. Le chargement exige exactement la nouvelle liste ; un fichier `/2` est refusé avec le message « Extraction ancienne : refaire `acma:extraire` ».

| Table | Colonnes ajoutées | Usage |
|---|---|---|
| `etudiant` | `telephone`, `mobile`, `email`, `EmailB`, `adresse`, `region`, `RegionBis`, `departement`, `numcni`, `expression`, `Suspendu`, `idcampus` | Coordonnées et origine de l'auditeur (fiche d'archive, préremplissage de la réinscription) |
| `inscription_academic` | `parcours` | Parcours tel qu'ACMA le note par inscription (aide au choix de l'option de M2) |

Restent exclus, et le test de l'extraction le vérifie :

- **secrets et personnel d'ACMA** : table `utilisateur`, table `etablissement` (identifiants FTP, clé de plateforme), identifiants du personnel (`du.username`, `du.stan`, `notes.IDUtilisateur`, `notes_hist.usercon`, `notes_hist.IDutilisateur`), groupware `gpw*`, journal `tachesutili` ;
- **copies de l'identité** dans les autres tables (`NomEtudiant`, `nometudiant`, `datenaissance` des tables de documents et de `du`), `inscription_academic.sexe` : l'identité se lit dans `etudiant` (Q11) ;
- **colonnes techniques ou fausses** déjà écartées (`du.Versement`, `du.RestePayer`, `notes.moy`, etc.) : elles ne portent rien sur l'auditeur.

`idcampus` et `expression` sont repris tels quels sous « Autres champs ACMA » ; leur sens n'est pas documenté (Q11).

### 4.2 Archive : colonnes ajoutées (migration additive)

`acma_dossiers` reçoit, toutes facultatives, en texte nettoyé des espaces (vide devient nul) et sans autre transformation :

`telephone_source`, `mobile_source`, `email_source`, `email2_source`, `adresse_source`, `region_source`, `region_bis_source`, `departement_source`, `numcni_source`, `expression_source`, `suspendu_source`, `idcampus_source`.

`acma_inscriptions` reçoit `parcours_source`.

- **Aucune normalisation stockée.** Une classe pure `CoordonneesAcma` propose, à l'affichage et à la réinscription : les téléphones en E.164 (même règle que `ImportAuditeurs::telephone` : 9 chiffres commençant par 6 ou 2 au Cameroun, sinon 11 à 13 chiffres avec indicatif) et les e-mails de forme valide, en minuscules. Si la règle s'améliore, l'archive ne change pas.
- **Lecture seule inchangée** : `ALTER TABLE … ADD COLUMN` ne déclenche pas les déclencheurs de ligne, et la fonction `acma_dossiers_lecture_seule` compare déjà la ligne entière hors colonnes du lien.
- `down()` refusé si l'archive est gelée (précédent des migrations 15b à 15d).
- L'étape `dossiers` lit les nouvelles colonnes (fiches fusionnées : celles de la fiche retenue pour l'identité, §17 du lot 15, 15b.5, et une divergence de coordonnées est signalée), l'étape `inscriptions` lit `parcours`. L'empreinte de la ligne source inclut les nouvelles colonnes.

### 4.3 Rejeu de la reprise provisoire

L'archive n'est pas gelée : le chemin est celui du §0 de `docs/ops/reprise-acma.md`.

1. Nouvelle extraction de l'export du 15/11 (conservé chiffré par l'USI), au format `/3`.
2. `acma:charger`, puis `acma:inventaire` : il ajoute des **comptes de remplissage** (fiches avec téléphone, avec mobile, avec e-mail de forme valide, avec adresse), jamais une valeur.
3. `acma:reprendre` à blanc, puis `--appliquer`. Attendu : environ 2 441 dossiers « modifiés » (l'empreinte change), **aucun lien automatique remis à arbitrer** (l'identité ne change pas), tous les arbitrages et compléments conservés, contrôles bloquants verts.
4. Au J0, l'export final suit le même chemin, au format `/3`.

### 4.4 Affichage et protection

- **Fiche d'archive** : section « Coordonnées (ACMA) », visible avec `archives.consulter` (la permission de l'écran) : téléphones bruts et proposition E.164, e-mails (avec la mention « forme invalide » le cas échéant), adresse, région, département, `numcni` (libellé « Région d'origine (champ `numcni` d'ACMA) », §3.7 du lot 15), « Suspendu dans ACMA », autres champs.
- **Jamais** dans le journal, les classeurs de la reprise, les sorties de console, les journaux applicatifs, ni la vérification publique des documents.
- **Aucun envoi** vers une coordonnée de l'archive : aucun code ne lit `acma_dossiers.*_source` pour envoyer quoi que ce soit. Le seul chemin vers un message passe par la candidature créée à la réinscription (§7), après vérification des coordonnées par l'agent. C10 et les tests `assertNothingSent` de la reprise restent valables.

## 5. Promotions antérieures (sous-lot 15j)

### 5.1 Lesquelles

Correspondance proposée (Q1) : **promotion n = année d'entrée `2012 + n`**, cohérente avec les promotions déjà en base (P13 = 2025-2026, P14 = 2026-2027) et avec `ImportAuditeurs::campagne`. ACMA commence en 2013-2014 (P1) ; 2016-2017 n'a aucune inscription.

On ne crée pas d'avance les treize promotions. Une promotion antérieure se crée **à la demande**, quand un ancien qui en vient est réinscrit (Q3).

### 5.2 Création

Action « Créer une promotion antérieure » dans Vie académique > Promotions (permission `scolarite.promotion_anterieure`, §12) :

| Champ | Règle |
|---|---|
| Numéro | Entier de 1 à `promotion courante − 1`, **absent** de la base (contrôle sous verrou ; pas d'index unique, car `promotion_numero` ne l'a jamais eu : vérifier l'absence de doublon en production avant d'en poser un, Q1) |
| Année académique | Proposée `2012 + n`, modifiable ; format `AAAA-AAAA` ; écrite dans le nom (« Année académique AAAA-AAAA »), que `anneeAcademique()` relit |
| Effets | `status` = `closed`, `opens_at` et `closes_at` dans l'année d'entrée (comme `ImportAuditeurs`), `slug` `pAA-AAAA`, `prefix_numero` `PNNAAA-`, `max_voeux` = 1, aucun communiqué, aucune consigne, numérotation des semestres par défaut |

- Journal `scolarite`, événement `promotion_anterieure_creee` (numéro, année, motif d'au moins 10 caractères).
- La liste des promotions montre aussi les promotions antérieures sans retenu (filtre « Antérieures »), pour qu'on puisse les régler.
- Aucune suppression (comme aujourd'hui).

### 5.3 Effets d'une promotion antérieure

| Où | Effet |
|---|---|
| Portail public | Aucun (§3, test). |
| Liste des auditeurs, exports, filtres par promotion | Les réinscrits rattachés y apparaissent. |
| Matricules | **Aucune attribution** dans une promotion antérieure (§8.1). |
| Lettres en lot (« Documents des auditeurs ») | Possibles une fois les paramètres saisis ; mais la lettre d'accès d'un réinscrit lit la **promotion d'accueil** (§10). |
| Semestres | Numérotation réglable comme pour toute promotion (#127) ; elle ne sert qu'à l'affichage. |
| Cohorte eCampus | Celle de la **promotion d'accueil**, jamais `PROMO{n}` d'une promotion antérieure (§10). |

## 6. Notions communes

### 6.1 Année en cours et promotion courante

- `AnneeAcademique::courante()` : réglage applicatif (`AppSetting` `scolarite.annee_en_cours`), défaut `2026-2027`, changé par le responsable au moment de la réinscription de D3. `CorrespondancesAcma::ANNEE_EN_COURS` le lit désormais (Q4).
- **Promotion courante** : la campagne dont l'année est l'année en cours et dont le numéro est le plus grand (P14 au 16/11). Une seule ; si aucune ne correspond, tout geste qui en dépend est refusé avec un message clair.
- **Promotion d'accueil** d'une inscription de l'année Y : en M1, la promotion qui entre en Y ; en M2, celle qui est entrée en Y − 1. En 2026-2027 : P14 pour le M1, P13 pour le M2. Elle sert à la cohorte eCampus et à la lettre d'accès (§10). Elle peut différer de la promotion de rattachement (celle du dossier).

### 6.2 Origine d'un dossier

Nouvelle table `reinscriptions_acma`, **en ajout seul** (déclencheur sur le précédent de `acma_complements`) :

| Colonne | Règle |
|---|---|
| `uuid` | Clé publique |
| `dossier_acma_id` | Clé étrangère RESTRICT vers `acma_dossiers`, **unique** : un dossier d'archive ne se réinscrit qu'une fois |
| `candidature_id`, `auditeur_id`, `inscription_annuelle_id` | Clés étrangères RESTRICT, uniques |
| `campagne_id` | Promotion de rattachement choisie |
| `rapprochement_id` | La décision `lie` écrite par le geste |
| `coordonnees_modifiees` | JSONB : liste des champs que l'agent a changés par rapport à la proposition ACMA (noms de champs, jamais de valeur) |
| `mode` | `creation` (§7), `conversion` ou `attribution` (§8.3) |
| `ancien_matricule` | Matricule `26…` remplacé (mode `conversion`), sinon nul |
| `motif`, `auteur_id`, `created_at` | Motif d'au moins 10 caractères |

Elle marque le dossier « réinscrit depuis ACMA » pour l'espace (§9), les listes et les garde-fous, sans toucher au schéma de `candidatures`.

## 7. Réinscription d'un ancien depuis l'archive (sous-lot 15k)

### 7.1 Le geste

Action « Réinscrire cet ancien auditeur » sur la fiche d'archive, **unitaire** (Q5), permission `scolarite.inscrire_ancien` (§12). Un seul formulaire, une seule transaction, un seul résultat : **création et inscription ensemble** (Q6). Un ancien n'existe donc jamais sur la plateforme sans inscription dans l'année en cours.

### 7.2 Préalables (refus motivé, rien d'écrit)

| Contrôle | Refus |
|---|---|
| Dossier `retire_de_la_source_at` ou `ecarte` | « Dossier retiré d'ACMA » ou « dossier écarté » |
| Dossier déjà lié (`lie_auto` ou `lie_arbitre`) | « Déjà lié à l'auditeur … : ouvrir sa fiche » (lien vers la fiche) |
| Dossier `a_arbitrer` (proposition R3, R4 ou R6 non tranchée) | « Arbitrer d'abord le rapprochement » : une proposition R6, en particulier, désigne un auditeur de la plateforme qui est peut-être la même personne |
| Ligne `reinscriptions_acma` existante | « Déjà réinscrit » |
| Matricule du dossier déjà porté par une candidature (`withTrashed`) | « Matricule déjà pris par le dossier … » |
| Doublon d'identité (§8.2) | Selon le cas |
| Pas de promotion courante, pas de barème (année en cours, niveau) | Message du réglage manquant |

### 7.3 Formulaire (prérempli depuis l'archive, tout modifiable sauf le matricule)

| Champ | Source et règle |
|---|---|
| Matricule | `acma_dossiers.matricule` (normalisé, §6.1 du lot 15), **non modifiable** |
| Nom, prénoms | ACMA n'a qu'un champ « NOM PRÉNOMS » : proposition = premier mot en nom, le reste en prénoms ; l'agent corrige d'après la pièce. Nom d'épouse facultatif. |
| Date de naissance | Celle en vigueur dans l'archive (dernier complément, sinon ACMA). Si elle est vide, à vérifier ou « né vers » : saisie obligatoire avec la référence de la pièce, et **un complément `date_naissance`** est écrit dans l'archive dans la même transaction (ajout seul, §7.5 du lot 15). |
| Genre, civilité | Genre depuis `sexe` (`M`, `F`) ; civilité facultative |
| Lieu de naissance, nationalité | Texte ACMA ; nationalité convertie en code ISO (même règle que `ImportAuditeurs::codeNationalite`), sinon à choisir |
| **Téléphone** (obligatoire) | Proposition E.164 depuis `mobile`, sinon `telephone` (§4.2) ; normalisé et contrôlé comme dans `AjoutRetenu` (`+[1-9]\d{6,14}`) ; compte du personnel refusé ; compte candidat d'une autre date de naissance refusé (règles de `AjoutRetenu::compteExistant`) |
| Second téléphone | L'autre numéro ACMA, s'il diffère |
| **E-mail** | Proposition depuis `email`, sinon `EmailB`, si la forme est valide. **Obligatoire si aucun compte eCampus n'existe** pour ce matricule : eCampus l'exige (§3). |
| Adresse, région, ville | Depuis l'archive, modifiables |
| Promotion de rattachement | Liste P1 à P14 ; **proposée : promotion d'origine** = année de la première inscription M1 dans l'archive, ou année précédant la première inscription M2 (règle de `EcartsAcma2026`, §17 du lot 15, 15c.4) ; une promotion antérieure absente se crée d'abord (§5) (Q3) |
| Niveau 2026-2027 | M1 ou M2 |
| Parcours | M1 : `TC`, imposé. M2 : option à choisir (`ACFP`, `EPGP`, `FFCP`, `GTFP`, `MPP`), proposée depuis la dernière inscription M2 de l'archive (`CorrespondancesAcma::NIVEAUX`), sinon `categorie_acma` |
| Spécialité | Libellé de `config('specialites')` correspondant au parcours de M2 (lu par `ParcoursAuditeur`) ; vide en M1 si inconnue |
| Motif | Au moins 10 caractères (par exemple la demande écrite de l'auditeur) |

Le récapitulatif avant validation montre l'archive à côté : années, niveaux, décisions d'archive, écart de paiement « non vérifié ».

### 7.4 Contrôles du niveau

- **M2** : exige une décision d'archive `admis` en M1 (enregistrée par ACMA ou transcrite d'un PV, `ArchivesAcma::decision`). Sinon, refus, sauf **dérogation** du responsable avec motif et référence de pièce, tracée dans `reinscriptions_acma` et au journal (Q7).
- **M1** : toujours possible. Si l'archive montre un M1 `admis`, avertissement « déjà admis en M1 en … » (confirmation exigée).
- **Deux inscriptions la même année** (M2 et dettes du M1, comme dans ACMA) : impossible sur la plateforme (une inscription par année). Hors périmètre (§13).

### 7.5 Ce que fait la plateforme, dans une transaction

Service `ReinscriptionAncien::reinscrire(dossier, saisie, auteur)` :

1. **Verrous** : promotion de rattachement (`lockForUpdate`, comme `AjoutRetenu`), puis dossier d'archive (`SELECT … FOR UPDATE` sur `acma_dossiers`, lecture seule permise). Les préalables du §7.2 sont relus sous verrou.
2. **Compte candidat** au téléphone (existant et compatible, ou créé par `CandidatUserService::createCandidat`, code inconnu de tous).
3. **Candidature** : statut `accepte`, `decided_at` maintenant, `submitted_at` **nul** (hors PV, hors statistiques), `ajoute_par` = l'agent, champs du formulaire, `matricule` ACMA, `categorie_matricule` `EFP` sauf forme `…EFSC…`, `rang_retenu` **nul**, `matricule_attribue_at` maintenant, `moodle_password` = `AttributionMatricules::motDePasse()`.
4. **Auditeur** (statut `actif`).
5. **Inscription de l'année en cours** (service `InscriptionAnneeEnCours`, réutilisable par D3) sous `ImputationsCompte::sousVerrou` : barème (année en cours, niveau) copié, `parcours_id` posé, **`acces_notifie_at` nul** (l'avis partira à l'ouverture de l'accès), R3 (`imputerAvance`, déclencheur `reinscription_acma` ajouté à `ImputationsCompte::DECLENCHEURS`), puis `InscriptionsPedagogiques::inscrire` (sans effet si la maquette n'est pas validée : `lmd:inscrire` le fera).
6. **Archive, décision d'abord** : insertion dans `acma_rapprochements` (décision `lie`, auditeur créé, `decide_par` = l'agent, cas de la dernière proposition s'il y en a, motif « Réinscription depuis l'archive : … »), **puis** mise à jour du lien (`auditeur_id`, `statut_rapprochement` = `lie_arbitre`). Le déclencheur l'accepte, avant comme après le gel. Complément `date_naissance` le cas échéant.
7. **Trace** : ligne `reinscriptions_acma` ; journaux (§12.2).

Après validation de la transaction, **hors transaction** :

8. **Compte eCampus** : `ComptesEcampus::traiter` si l'agent détient `candidature.matricules_moodle`, sinon le bouton existant le fera.
   - Compte **créé** : cohorte de la promotion d'accueil (§10), mot de passe généré connu.
   - Compte **existant** (ancien compte sous ce matricule) : jamais modifié ; `moodle_password` est **effacé** dans la même opération, pour que l'espace et la lettre n'affichent pas un mot de passe faux (Q8).
9. **Aucun envoi** au moment de la réinscription : ni SMS, ni e-mail, ni lettre.

### 7.6 Ensuite : le chemin normal, sans contournement

- Le service financier encaisse, la scolarité confirme ; seul l'argent confirmé solde les droits d'inscription.
- Ou bien le responsable débloque l'accès (A1, exceptionnel compris) ou un moratoire est accordé (A3).
- À l'ouverture de l'accès, `NotificationAcces::siDisponible` envoie le lien de la lettre (ou, sans mot de passe connu, le message « vos accès FOAD sont disponibles dans votre espace »), une fois.
- Aucun paramètre, aucune case, aucune commande de la réinscription ne pose `acces_notifie_at`, un déblocage, une remise ou un versement. Un test le vérifie.

### 7.7 Les cas Q7 du lot 15

Quatre auditeurs actifs en 2026-2027 dans ACMA, absents de la plateforme (trois de la P12, un de la P13, au 16/11). Leur dossier est dans l'archive (inscriptions jusqu'en 2025-2026).

- Ils passent par ce geste, **matricule ACMA conservé**, à la place de « Ajouter un retenu » (Q7 du lot 15, décision a) modifiée).
- Niveau et option : ceux de leur inscription ACMA 2026-2027, lus dans la feuille « Auditeurs hors plateforme » du rapport des écarts.
- Leurs versements 2026-2027 enregistrés dans ACMA ne sont pas importés (décision 2 du lot 15) : la scolarité les saisit comme versements ordinaires, pièce à l'appui, puis les confirme.
- Pour eux, la promotion de rattachement peut être la promotion d'accueil (P14 en M1, P13 en M2) si 15l n'est pas encore en production : la cohorte et la lettre sont alors justes sans attendre (§11).

## 8. Garde-fous

### 8.1 Un nouvel auditeur : candidature, promotion en cours seulement

| Point d'entrée | Règle |
|---|---|
| Portail de candidature | Inchangé : seule la campagne ouverte reçoit des dossiers. |
| « Ajouter un retenu » (`AjoutRetenu::ajouter`) | **Promotion courante seulement** (au lieu de « ouverte ou close »). Liste du formulaire réduite. Message pour une autre : « Un ancien auditeur se réinscrit depuis l'archive ACMA ; un nouvel auditeur n'entre que dans la promotion en cours. » |
| Attribution des matricules (`AttributionMatricules::attribuer`) | **Promotion courante seulement.** `anneeFigee` ignore les dossiers à `rang_retenu` nul (matricules repris) : sinon un réinscrit `99EFP…` figerait l'année d'une promotion. |
| `scolarite:importer-auditeurs` | Retirée : la commande refuse et renvoie à la réinscription depuis l'archive (Q9). |
| Réinscription depuis l'archive | Par construction, elle part d'un dossier ACMA : elle ne crée jamais un nouvel auditeur. Elle accepte toute promotion, P14 comprise (décision 2). |

### 8.2 Un ancien qui a aussi candidaté en P14

Détection, à la réinscription et à l'attribution des matricules de la P14 :

| Signal | Où on le cherche |
|---|---|
| Même matricule normalisé | Candidatures (toutes campagnes, supprimées comprises) |
| Même date de naissance et noms compatibles (`ComparaisonIdentite`, seuil 0,66) | Candidatures de toutes campagnes, non retirées |
| Même téléphone E.164 ou même e-mail | Candidatures et comptes, d'après les coordonnées ACMA (désormais reprises, §4) |

Traitement (Q2 tranchée le 16/11 : **le dossier ACMA fait foi**) :

| Situation | Effet |
|---|---|
| La personne est déjà **auditeur** de la plateforme (P14 retenue avec matricule `26…`) | **Réinscription refusée** (elle créerait un second auditeur). Le responsable **convertit** le dossier P14 (§8.3) : il prend le matricule ACMA, tout le reste est conservé, et l'archive se lie à lui. |
| La personne est auditeur d'une autre promotion de la plateforme (P13) sous un autre matricule | Même conversion (§8.3). Cas improbable : le matricule P13 vient en principe d'ACMA. |
| Candidature P14 **en cours** (non décidée) | Réinscription refusée tant que le jury n'a pas décidé ; la fiche d'archive affiche le dossier P14 concerné. |
| Candidature P14 retenue **sans matricule** encore | Réinscription refusée ; l'attribution des matricules lui donne le matricule ACMA au lieu d'un `26…` (§8.3, variante sans conversion). |
| Candidature P14 **refusée** | Réinscription possible, avec avertissement et motif. |
| Ancien **déjà réinscrit**, puis retenu en P14 par le portail | L'attribution du matricule de ce retenu est **refusée** (« déjà auditeur depuis l'archive, dossier … ») ; la scolarité retire la candidature P14 (geste existant). |

Les signaux téléphone et e-mail ne bloquent jamais seuls : ils s'affichent et exigent une confirmation motivée (un numéro peut avoir changé de titulaire).

### 8.3 Q2 : un ancien déjà auditeur de la P14 prend son matricule ACMA (sous-lot 15m)

**Ce que le dossier P14 peut déjà porter** : inscription 2026-2027, versements, imputations (D1), confirmations, reçus numérotés, déblocages et moratoires, engagement de prise en charge (A2), compte eCampus `26…` dans la cohorte P14, mot de passe remis, lettre et lien d'accès envoyés, documents délivrés (lot 7), inscription pédagogique, notes, messages, journal.

**Combien de cas ?** Non mesurable sur le poste : la base locale de la reprise (`pssfp_reprise_acma`) n'a pas les candidatures, et la base de développement n'a que des données fictives. En production, les cas possibles sont les propositions **R6** de la reprise provisoire (même date de naissance, noms compatibles, matricule différent), listées dans le classeur « à arbitrer ». 15m peut donc être livré après 15k.

#### Deux mécanismes comparés

| Critère | (a) **Conversion** du dossier P14 | (b) Nouvelle réinscription, **transfert** puis retrait de la candidature P14 |
|---|---|---|
| Versements, imputations, confirmations | Intacts : ils pendent à l'auditeur et à l'inscription, qui ne changent pas | À déplacer d'un auditeur à l'autre. Les clés étrangères composites de D1 (versement, inscription et imputation du même auditeur) et l'immuabilité des imputations l'interdisent : il faudrait **annuler** chaque versement et le **ressaisir** |
| Reçus | Aucun nouveau reçu, aucune annulation : numérotation intacte | Annulation de chaque reçu et nouveau reçu : la séquence reste sans trou, mais chaque paiement apparaît deux fois, sous deux numéros |
| Accès | Inchangé (même inscription, même situation, même déblocage) | Coupé entre le retrait et la reconstitution ; risque d'un second avis d'accès (nouvelle inscription, `acces_notifie_at` vide) |
| Comptes D1, `CPT_COHERENCE` | Équivalents par construction | À reconstituer ; avance et ordre R1 recalculés, écarts possibles |
| eCampus | Compte et cohorte inchangés | Second compte sous le matricule ACMA, ou bascule manuelle de l'historique Moodle |
| Documents, notes, messages | Intacts | À rattacher à la main, ou perdus pour l'espace |
| Journal | Une entrée de conversion | Des dizaines d'opérations d'argent pour un changement d'identifiant |

**Recommandation : (a), la conversion.** Elle ne touche qu'un champ d'identification ; (b) déplace de l'argent pour changer un numéro.

#### La conversion

Geste « Reprendre le matricule ACMA » sur la fiche d'archive, permission `scolarite.inscrire_ancien`, motif d'au moins 10 caractères, **unitaire**. Service `ConversionMatriculeAcma::convertir(dossierAcma, auditeur, motif, auteur)`, dans une transaction, sous le verrou de la candidature puis du dossier d'archive :

1. **Préalables** : dossier d'archive ni retiré, ni écarté, ni déjà réinscrit, ni lié à un **autre** auditeur ; matricule ACMA libre (aucune candidature, supprimées comprises) ; identité concordante (même date de naissance et noms compatibles), sinon confirmation explicite.
2. **Trace d'abord** : ligne `reinscriptions_acma` de mode `conversion` (§6.2), qui garde **l'ancien matricule** ; la base refuse ensuite de changer un matricule sans cette ligne (déclencheur, ci-dessous).
3. **Matricule** : `candidatures.matricule` = matricule ACMA. `rang_retenu` est **conservé** : le rang reste pris, `max(rang_retenu)` ne recule pas, et l'ancien `26…` ne sera jamais réattribué ; `AttributionMatricules` le vérifie aussi dans `reinscriptions_acma.ancien_matricule`.
4. **Identifiant eCampus figé** : nouvelle colonne `candidatures.identifiant_ecampus`, posée à l'ancien identifiant (`26…` en minuscules). `IdentifiantsMoodle::identifiant` la lit d'abord, sinon le matricule. Le compte eCampus n'est **pas touché** (règle de `ComptesEcampus`), ni sa cohorte, ni le mot de passe : l'auditeur se connecte comme avant. Le contrôle `porteLaMarque` (`idnumber`) compare à l'identifiant figé.
5. **Archive, décision d'abord** : décision `lie` signée vers cet auditeur, puis le lien (comme au §7.5, étape 6).
6. **Journal** : `scolarite`, `matricule_converti_acma` (ancien, nouveau, dossier d'archive, motif). **Aucun envoi.**

Invariants et leur traitement :

| Invariant | Traitement |
|---|---|
| **Le matricule ne change pas** | Exception unique, tracée (`reinscriptions_acma`, journal), réservée au responsable, une fois par dossier. Un déclencheur sur `candidatures` refuse tout changement d'un matricule déjà attribué sans ligne `conversion` correspondante (ancien et nouveau égaux à ceux de la ligne). |
| **Identifiant Moodle et cohorte** | Figés par `identifiant_ecampus` : rien ne change sur eCampus. |
| **Lettre et lien d'accès déjà envoyés** | Le lien ouvert reste valable (même identifiant, même mot de passe). Une lettre régénérée porte le nouveau matricule et le même identifiant ; la lettre d'admission signée, déjà remise, reste telle quelle. |
| **Reçus qui portent l'ancien matricule** | Instantanés légaux, jamais modifiés : ils gardent l'ancien matricule. Les reçus suivants portent le matricule ACMA. La fiche de l'auditeur affiche l'ancien matricule. |
| **Documents délivrés** (lot 7) | Fichiers scellés inchangés ; la vérification publique n'affiche pas de matricule. |
| **Exports, listes, recherche** | Le matricule ACMA partout ; la recherche trouve aussi l'ancien (`reinscriptions_acma`). |
| **Numéro de dossier, campagne, PV** | Inchangés : la candidature reste celle du portail, dans le PV du jury. L'espace garde le récépissé et la lettre d'admission (mode `conversion`). |

**Variante sans conversion** : une candidature P14 retenue **sans** matricule encore, dont le dossier d'archive lui est déjà lié par arbitrage. L'attribution des matricules lui pose le matricule ACMA au lieu d'un `26…`, sans rang ; même trace (`reinscriptions_acma`, mode `attribution`).

## 9. L'espace de l'ancien réinscrit

Il voit l'espace d'un auditeur, avec les différences suivantes (dossier présent dans `reinscriptions_acma`) :

| Rubrique | Contenu |
|---|---|
| Accueil | Pas d'annonce d'admission ni de consignes de la promotion ; message « Bienvenue pour votre année 2026-2027 », synthèse de scolarité et état de l'accès comme les autres. |
| Ma scolarité, Mes accès | Comme les autres : barème, versements, avance ; identifiant = matricule ACMA en minuscules ; mot de passe s'il est connu (§7.5). |
| Mes documents | Pas de récépissé de candidature, pas de lettre d'admission, pas de communiqué des résultats (`peutAvoirRecepisse` faux pour ce dossier). La lettre d'accès FOAD seulement. |
| Mon parcours | Semestres de l'année en cours ; numérotation de la promotion de rattachement. |
| Historique ACMA | **Non** (Q15 du lot 15 maintenue : l'archive reste à l'administration). |

**Connexion** : téléphone et code, comme les P13 importés (« Code oublié » par SMS à sa demande), ou identifiants FOAD.

**Frontière avec la décision 4 du lot 15.** Elle est modifiée pour les seuls anciens réinscrits par ce geste. Pour tous les autres dossiers d'archive : aucun compte, aucun espace, aucun compte eCampus, aucun envoi. C'est garanti par construction (seul le geste crée une candidature, et il exige une inscription dans l'année en cours) et vérifié par les tests (§13.3).

## 10. Année d'accueil : cohorte eCampus et lettre d'accès (sous-lot 15l)

Problème : la cohorte eCampus (`PROMO{n}_TC_{année}`) et les paramètres de la lettre d'accès sont lus sur la **campagne du dossier**. C'est faux pour un ancien rattaché à une promotion antérieure, et déjà faux pour un P13 redoublant inscrit en M1 2026-2027 (il suit les cours de la P14).

Changement proposé :

- **Cohorte** : `ComptesEcampus::cohorte` lit la promotion d'accueil de l'inscription courante (§6.1) : `PROMO14_TC_2026_2027` pour un M1 2026-2027. Pour le M2, le nom des cohortes d'option sur eCampus est à confirmer avec l'USI (Q10) ; d'ici là, un M2 réinscrit est créé sans cohorte et signalé « cohorte à poser » (état `cohorte_a_finir`).
- **Lettre d'accès** (`LiensLettreAcces::possible`, `LettreAccesFoadPdf`, `ScolariteResource.lettre_disponible`) : paramètres (`reference_acces`, `date_lettres`, signature) de la **promotion d'accueil** pour un dossier réinscrit. La lettre d'admission ne concerne pas les réinscrits.
- Pour un dossier ordinaire, la promotion d'accueil est sa promotion : rien ne change pour les P14.

## 11. Effets sur le reste du lot 15 et sur le lot D

| Sujet | Effet |
|---|---|
| **Rejeu avant le gel** | La décision `lie` signée est conservée par `EtapeRapprochement` (« arbitrages conservés »), même si l'identité ACMA change ou si le dossier disparaît de la source (lien gardé, dossier signalé « retiré »). Le contrôle C09 ne compte que les écritures de la transaction de reprise : une réinscription entre deux reprises ne le fait pas échouer. C08 compte un lien de plus. |
| **Gel au J0** | Rien ne change : la réinscription n'écrit que dans `acma_rapprochements` et `acma_complements` (ajout seul) et le lien de `acma_dossiers`, autorisés après le gel. |
| **Rapport des écarts 2026-2027** | Un réinscrit sort de la feuille « Auditeurs hors plateforme » au rapport suivant. |
| **15f, arriérés** | Un ancien réinscrit a une inscription plus récente : ses années passées deviennent éligibles (§8.1 du lot 15). Avec R1, un arriéré porté passe **avant** l'année en cours et peut retarder son accès. Proposition : même circuit ligne par ligne, avec un avertissement propre et l'aperçu chiffré (Q12). |
| **15g, acquis** | Les correspondances ne couvrent que les programmes M1 2024-2025 et 2025-2026. Un ancien redoublant d'une année plus ancienne n'a pas d'acquis capitalisé sauf extension (Q13). |
| **15e, documents historiques** | Inchangé ; `auditeur_id` du document est renseigné pour un dossier lié. |
| **D3, réinscription en groupe** | `InscriptionAnneeEnCours` (§7.5, étape 5) est le service que D3 appellera en groupe ; il n'ouvre pas l'année suivante (refus si l'année demandée n'est pas l'année en cours). La réinscription d'un **P13 déjà sur la plateforme** (redoublant sans inscription 2026-2027) reste à D3 (Q14). |
| **D2, D4** | Non requis par 15k, sauf pour porter un arriéré (15f). |

## 12. Permissions et journal

### 12.1 Permissions (migration additive, sans relancer `RolePermissionSeeder`)

Listes figées, sur le précédent `2026_12_07_100300_grant_archives_acma_permissions` et du §7.10 du lot 15.

| Permission | Module | Nature | Rôles |
|---|---|---|---|
| `scolarite.promotion_anterieure` | Scolarité | Sensible | `super_admin`, `admin`, `scolarite_responsable` |
| `scolarite.inscrire_ancien` | Scolarité | Sensible | `super_admin`, `admin`, `scolarite_responsable` |

- Le geste exige aussi `archives.consulter` (voir la fiche) ; il n'exige pas `archives.rapprocher` : il est lui-même l'arbitrage, et sa décision porte son auteur.
- La dérogation M2 (§7.4) est réservée à `scolarite.inscrire_ancien`, donc au responsable.
- `roles:matrice` et `ModulePermissionMap` mis à jour ; `docs/ops/roles.md` régénéré après déploiement.
- L'élargissement de l'extraction (15i) n'ajoute aucune permission.

### 12.2 Journal

| Journal | Événement | Propriétés (jamais d'identité ni de coordonnée) |
|---|---|---|
| `scolarite` | `promotion_anterieure_creee` | numéro, année, motif |
| `scolarite` | `ancien_reinscrit` | uuid du dossier d'archive, matricule, promotion, niveau, parcours, dérogation M2 (oui/non), champs modifiés (noms), motif |
| `scolarite` | `inscription_creee` | comme aujourd'hui |
| `archives` | `rapprochement_decide` | décision `lie`, origine `reinscription` |
| `archives` | `complement_ajoute` | si une date de naissance est complétée |
| `candidatures` | `retenu_reinscrit_acma` | numéro de dossier |
| `archives` | `transit_charge` | format `/3` en plus |

## 13. Tests (Pest), découpage, risques

### 13.1 15i — Coordonnées

- L'extraction `/3` contient les colonnes ajoutées et **aucune** colonne exclue (secrets, personnel, copies) ; un manifeste `/2` est refusé au chargement.
- `CoordonneesAcma` : tables de cas (9 chiffres camerounais, indicatif, zéros, séparateurs, numéro trop court ; e-mail invalide, majuscules).
- Rejeu sur un jeu fictif : dossiers « modifiés », aucun lien automatique remis à arbitrer, arbitrages et compléments conservés.
- `acma:inventaire` ne sort que des comptes (recherche des téléphones et e-mails fictifs du jeu d'essai dans la sortie : aucun).
- Fiche d'archive : section visible avec `archives.consulter`, absente sans ; aucune coordonnée dans le journal.

### 13.2 15j — Promotions et garde-fous

- Création d'une promotion antérieure : numéro pris refusé, numéro ≥ promotion courante refusé, année relue par `anneeAcademique()`, absente du portail (`currentCampagne`, `derniereCampagneAvecResultats`) et des widgets de campagne.
- `AjoutRetenu` refuse la P13 et une promotion antérieure ; `AttributionMatricules` refuse hors promotion courante ; `anneeFigee` ignore un matricule repris.
- `scolarite:importer-auditeurs` refuse.

### 13.3 15k — Réinscription

- Chaque préalable du §7.2 refuse sans rien écrire.
- Cas nominaux M1 et M2 : candidature hors PV (`submitted_at` nul), matricule ACMA (formes anciennes comprises : `99FPL12`, minuscules normalisées), auditeur, inscription 2026-2027 au barème de l'année et du niveau, parcours posé, `acces_notifie_at` nul, R3 sur une avance existante.
- **Décision d'abord** : la ligne `lie` existe avant la mise à jour du lien ; un lien posé sans elle est refusé par la base ; le lien survit à un rejeu et à un gel.
- Date de naissance absente : complément écrit dans la même transaction ; refus sans référence de pièce.
- M2 sans M1 admis : refus ; dérogation tracée.
- Doublons du §8.2 : auditeur existant (refus), candidature P14 en cours (refus), refusée (avertissement), réinscrit puis retenu P14 (attribution refusée).
- eCampus : création avec cohorte d'accueil ; compte existant → `moodle_password` effacé, espace sans mot de passe.
- **Aucun envoi** au geste (`Notification::fake`, `Queue::fake`, SMS factice) ; envoi du lien **seulement** après confirmation d'un versement soldant les droits, ou déblocage.
- **Aucun contournement** : ni déblocage, ni remise, ni versement, ni `acces_notifie_at` écrit par le geste ; un dossier d'archive non réinscrit n'a ni compte ni candidature, et aucun service d'envoi n'est appelé pour lui (relances, messagerie, notifications : `assertNothingSent`).
- Double clic : une seule réinscription (unicité de `reinscriptions_acma.dossier_acma_id`, verrou).
- `reinscriptions_acma` refuse `UPDATE`, `DELETE`, `TRUNCATE`.
- `CPT_COHERENCE` vert après chaque cas.

### 13.4 15l — Année d'accueil

- Promotion d'accueil : M1 Y → entrée Y ; M2 Y → entrée Y − 1.
- Cohorte d'un réinscrit rattaché à une promotion antérieure, et d'un P14 (inchangée).
- Lettre d'accès d'un réinscrit avec les paramètres de la promotion d'accueil ; P14 inchangés.
- Espace : pas de récépissé, de lettre d'admission ni de communiqué pour un réinscrit.

### 13.5 Découpage

Chaque sous-lot a sa branche, ses tests, sa PR, et se déploie seul.

```
15i (coordonnées, rejeu provisoire) ─┐
15j (promotions, garde-fous) ────────┼─> 15k (réinscription) ─> 15l (année d'accueil)
                                     └─ 15l peut précéder 15k ; les cas Q7 n'attendent pas 15l s'ils sont rattachés à leur promotion d'accueil (§7.7)
```

**15i — Coordonnées des auditeurs dans l'archive.** Prérequis : décision 3 du 16/11. Avant J0.
1. `ExtractionAcma::COLONNES` élargie, format `/3`, refus du `/2` au chargement.
2. Migration additive (`acma_dossiers`, `acma_inscriptions`), `down()` refusé après le gel.
3. Étapes `dossiers` et `inscriptions` : reprise des colonnes, empreintes, divergences des fiches fusionnées.
4. Classe pure `CoordonneesAcma` ; comptes de remplissage dans `acma:inventaire`.
5. Section « Coordonnées (ACMA) » de la fiche d'archive.
6. Tests du §13.1.
7. Procédure : mise à jour de `docs/ops/reprise-acma.md` ; nouvelle extraction de l'export du 15/11, charger, reprendre, contrôler.

**15j — Promotions antérieures et garde-fous de création.** Prérequis : Q1, Q3, Q4, Q9.
1. `AnneeAcademique::courante()` (réglage), promotion courante, promotion d'accueil ; `CorrespondancesAcma::ANNEE_EN_COURS` relu.
2. Permission `scolarite.promotion_anterieure` (migration additive).
3. Action « Créer une promotion antérieure », liste des promotions élargie (filtre « Antérieures »).
4. `AjoutRetenu` et `AttributionMatricules` limités à la promotion courante ; `anneeFigee` corrigée.
5. Retrait de `scolarite:importer-auditeurs`.
6. Tests du §13.2.

**15k — Réinscription d'un ancien.** Prérequis : 15i en production et rejoué, 15j ; Q2, Q5 à Q8.
1. Migration : `reinscriptions_acma` (ajout seul), permission `scolarite.inscrire_ancien`, déclencheur `reinscription_acma` de R3.
2. Service `InscriptionAnneeEnCours` (année en cours seulement, barème, parcours, R3, inscription pédagogique).
3. Service `ReinscriptionAncien` : préalables, doublons, candidature, compte, auditeur, décision d'abord, complément.
4. Garde-fou de l'attribution des matricules P14 contre un ancien déjà réinscrit (§8.2).
5. Action Filament sur la fiche d'archive (formulaire prérempli, récapitulatif) ; filtre « Réinscrit depuis ACMA » de la liste des auditeurs.
6. eCampus après validation (création, ou compte existant et mot de passe effacé).
7. Journal ; tests du §13.3.

**15m — Q2 : matricule ACMA pour un ancien déjà auditeur de la P14.** Prérequis : 15k.
1. Migration : `candidatures.identifiant_ecampus`, colonnes `mode` et `ancien_matricule` de `reinscriptions_acma`, déclencheur de matricule figé.
2. `IdentifiantsMoodle::identifiant` et `ComptesEcampus` lisent l'identifiant figé.
3. Service `ConversionMatriculeAcma` (§8.3) et action sur la fiche d'archive.
4. Attribution des matricules : variante sans conversion, ancien matricule jamais réattribué.
5. Fiche de l'auditeur : ancien matricule.
6. Tests : accès, situation, `CPT_COHERENCE`, reçus et numérotation, lien d'accès, identifiant eCampus inchangés après conversion ; refus sans trace.

**15l — Année d'accueil.** Prérequis : 15j ; Q10.
1. Cohorte eCampus lue dans la promotion d'accueil (M1) ; M2 signalé « cohorte à poser » tant que Q10 n'est pas tranchée.
2. Paramètres de la lettre d'accès lus dans la promotion d'accueil (lien, PDF, espace).
3. Espace d'un réinscrit : rubriques du §9 (`peutAvoirRecepisse`, accueil, documents).
4. Tests du §13.4.

### 13.6 Risques

| Risque | Gravité | Parade |
|---|---|---|
| **Mauvaise personne** : l'archive d'un homonyme rattachée à un nouveau compte, ou un second auditeur créé pour quelqu'un qui en a déjà un | Haute | Préalables (dossier à arbitrer refusé), détection du §8.2 sur identité, téléphone et e-mail, décision signée, motif ; refus à l'attribution P14 |
| **Envoi vers une coordonnée périmée** d'ACMA | Haute | Aucune lecture des coordonnées d'archive pour envoyer ; l'agent vérifie le téléphone et l'e-mail dans le formulaire ; envoi seulement à l'ouverture de l'accès |
| **Accès sans paiement** | Haute | Aucun chemin nouveau : `AccesEcampus` inchangé ; test « aucun contournement » |
| **Données personnelles élargies** (coordonnées de 2 441 anciens) | Moyenne | Lecture réservée à `archives.consulter` ; jamais dans le journal, les classeurs ou les sorties ; transit chiffré et purgé au J0 ; décision du chef de projet du 16/11 (base : registre de scolarité, à confirmer avec la direction, Q14 du lot 15) |
| **Mot de passe eCampus faux** affiché pour un compte existant | Moyenne | Effacement de `moodle_password` (§7.5) ; test |
| **Arriéré ancien** qui bloque l'accès de l'année (R1) | Moyenne | Q12 ; aperçu chiffré ; validation ligne par ligne |
| **Matricules anciens** aux formes inhabituelles (lettres, 6 à 10 caractères) | Faible | Aucune expression régulière de forme dans le chemin ; identifiant eCampus = matricule en minuscules ; test sur des formes fictives |
| **Promotion d'origine mal déduite** (le préfixe du matricule est l'année civile de création, pas l'année d'entrée) | Faible | Proposition depuis les inscriptions de l'archive, choix de l'agent ; la promotion ne commande ni la cohorte ni la lettre (§10) |

## 14. Questions ouvertes

| N° | Question | Options | Recommandation |
|---|---|---|---|
| **Q1** | Correspondance promotion ↔ année d'entrée | a) P n = `2012 + n` (P1 = 2013-2014 … P14 = 2026-2027), 2016-2017 compté comme une promotion sans inscription ; b) liste fournie par la scolarité, saisie à la création ; c) numéros consécutifs des seules années avec inscriptions | **a)**, avec l'année modifiable à la création : c'est la règle déjà en base (P13, P14) et dans `ImportAuditeurs`. La scolarité confirme le cas 2016-2017. |
| **Q2** (tranchée : **dossier ACMA**, §8.3) | Ancien qui a aussi candidaté en P14 : quel dossier fait foi ? | a) la candidature P14 (si retenue) : pas de réinscription, archive liée par arbitrage, matricule `26…` conservé ; b) la réinscription : candidature P14 retirée, matricule ACMA ; c) la candidature P14, mais avec le matricule ACMA | **a)**. La décision du jury et les données saisies par le candidat sont plus fraîches, et le matricule `26…` est déjà communiqué et sert d'identifiant eCampus (un matricule ne change pas). Le matricule ACMA reste lisible dans l'archive. Pour une candidature P14 non encore décidée : attendre le jury. |
| **Q3** | Promotion de rattachement d'un réinscrit, et création des promotions antérieures | a) promotion d'origine proposée, au choix ; promotion antérieure créée à la demande ; b) promotion d'accueil (P14 en M1, P13 en M2) imposée ; c) toutes les promotions P1 à P13 créées d'un coup | **a)**, conforme à la décision 2 (« n'importe quelle promotion »). Avec 15l, le choix n'a pas d'effet sur la cohorte ni sur la lettre. Créer à la demande évite des promotions vides. |
| **Q4** | Année en cours | a) réglage applicatif changé par le responsable ; b) déduite de la date (bascule au 1er septembre) ; c) constante du code | **a)** : la bascule est une décision (D3, décision 11 du lot D), pas une date. |
| **Q5** | Geste unitaire ou aussi en lot ? | a) unitaire seulement ; b) en plus, une commande depuis une liste de matricules, à blanc par défaut ; c) en lot seulement | **a)**. Les cas attendus sont rares (quatre au 16/11) et chacun exige de vérifier une identité et des coordonnées. b) peut venir ensuite sur le même service. |
| **Q6** | Créer un ancien sans l'inscrire ? | a) jamais : création et inscription ensemble ; b) permis, inscription plus tard | **a)** : la frontière avec la décision 4 reste nette (aucun ancien sur la plateforme sans année en cours). |
| **Q7** | Réinscription en M2 sans M1 admis dans l'archive | a) refus, dérogation du responsable avec motif et pièce ; b) refus sans dérogation ; c) avertissement seulement | **a)**. Plusieurs cohortes n'ont pas de décision enregistrée (§3.3 du lot 15) : la transcription du PV d'abord, sinon une dérogation tracée. |
| **Q8** | Compte eCampus déjà existant pour le matricule | a) le garder tel quel, effacer le mot de passe du portail ; l'auditeur utilise « mot de passe oublié » d'eCampus ou l'assistance de l'USI ; b) réinitialiser le mot de passe par le service web ; c) créer un autre compte | **a)** : la règle de `ComptesEcampus` (un compte existant n'est jamais modifié) reste vraie. L'USI précise la procédure d'assistance. |
| **Q9** | `scolarite:importer-auditeurs` (import « Full data » de la P13) | a) retirée (refus avec renvoi) ; b) gardée pour la seule P13 ; c) inchangée | **a)** : l'import de la P13 est fait ; la garder rouvrirait un chemin de création hors candidature et hors archive. |
| **Q10** | Cohorte eCampus d'un M2 | a) une cohorte par option et par année, nom à fixer par l'USI ; b) la cohorte de la promotion d'accueil (`PROMO13_…`) ; c) aucune, inscription aux cours à la main | **a)**, à confirmer avec l'USI selon l'organisation des cours M2 sur eCampus. D'ici là : « cohorte à poser ». |
| **Q11** | Périmètre de « tout importer » | a) toutes les colonnes de `etudiant` et `inscription_academic.parcours` ; copies d'identité des autres tables exclues ; b) en plus les copies de nom et de date des tables de documents et de `du` ; c) seulement téléphones, e-mails, adresse et région | **a)**. Les copies n'apportent rien que `etudiant` n'ait déjà et pourraient diverger. `idcampus` et `expression` sont repris bruts, faute de sens connu : la scolarité dira s'ils sont utiles. **Autre cas à confirmer** : `Suspendu` (fiche suspendue dans ACMA) est repris et affiché ; faut-il qu'il bloque la réinscription ? Recommandation : non, avertissement seulement. |
| **Q12** | Arriérés d'un ancien réinscrit (15f) | a) même circuit que la P13, ligne par ligne, avec un avertissement « réinscrit : l'arriéré passera avant l'année en cours » ; b) exclus par défaut, sur décision expresse seulement ; c) jamais | **a)** : cohérent avec la décision 3 du lot 15 et la décision 10 du lot D ; l'aperçu chiffré montre l'effet sur l'accès avant l'approbation. |
| **Q13** | Acquis d'un ancien redoublant d'une année antérieure à 2024-2025 (15g) | a) correspondances étendues à son programme d'origine, à la demande, validées ligne par ligne ; b) aucun acquis : il refait l'année ; c) acquis décidés par le jury sans correspondance | **a)**, au cas par cas : même règle que Q13 du lot 15, sans nouvelle règle LMD. |
| **Q14** | Un P13 déjà sur la plateforme, sans inscription 2026-2027 (non admis en M1) : par ce geste ou par D3 ? | a) D3 (réinscription en groupe) ; b) un geste unitaire « Inscrire en 2026-2027 » sur la fiche de l'auditeur, réutilisant `InscriptionAnneeEnCours` ; c) ce geste depuis l'archive | **b)** si D3 tarde : le service existe déjà avec 15k, et le geste reste soumis aux mêmes règles (année en cours, barème, aucun envoi, chemin normal vers l'accès). Sinon a). Jamais c) : la personne a déjà un auditeur. |
