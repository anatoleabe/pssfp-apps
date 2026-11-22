# Audit de la gestion académique LMD — novembre 2026

> **Date** : 2026-11-22
> **Périmètre** : module 4 (vie académique, LMD, scolarité, finances) et chaîne d'admission du module 5, côté `backend/` et espace auditeur `candidature/`.
> **Méthode** : lecture du code et des migrations, sans exécution (aucun test lancé, aucune donnée de production consultée). Comparaison avec le référentiel SI du CAMES, la directive CEMAC sur le LMD et les logiciels de scolarité documentés publiquement.
> **Limites** : les deux CDC au format Word (site et bibliothèque) n'ont pas été relus, l'audit s'appuie sur l'ADR-0011 et les spécifications `docs/specs/module-4-*`. Les « absences » reposent sur une lecture ciblée et des recherches par mots-clés, pas sur une lecture exhaustive.

## 1. Verdict

Le socle est solide et dépasse souvent les systèmes de référence sur l'anonymat des copies, les reçus, la correction des versements par contre-passation et le circuit des requêtes. Quatre failles touchent toutefois la valeur probante des notes et des actes, et le niveau « semestre » manque dans le modèle alors que les PV et relevés semestriels sont attendus.

Niveau de preuve utilisé dans ce document :

- **Vérifié** : relu directement dans le code pendant l'audit.
- **Rapporté** : relevé par une analyse du code, avec référence, mais non relu une seconde fois.

Sauf mention « vérifié », les constats des tableaux sont rapportés.

## 2. Référentiel de comparaison

| Source | Ce qu'on en retient | Statut |
|---|---|---|
| CAMES, *Référentiel pour le cadre de développement des SI* (2014), § B.1 à B.12 | Couverture fonctionnelle cible : candidatures, inscription administrative, inscription pédagogique (« contrat pédagogique »), structuration de l'offre en domaine / mention / spécialité, groupes, modalités de contrôle des connaissances, logistique des épreuves (listes préparatoires et de présence, affectation salles / surveillants), résultats (anonymat, calcul, jurys, PV, parchemins et annexe descriptive), charge d'enseignement, pilotage | Lu dans le texte |
| Directive CEMAC n° 02/06-UEAC-019-CM-14 du 11 mars 2006 | Master sur 4 semestres et 120 crédits (art. 15) ; mémoire ou travaux personnels obligatoires (art. 17) ; 60 crédits pour les deux premiers semestres (art. 18) | Lu dans le texte |
| Apogée, Pégase (France) | Offre en arbre, versionnée par année, règles de calcul paramétrées. Contre-modèle pour la lourdeur et les délais de déploiement | Sources secondaires |
| ScoDoc (logiciel libre, IUT français) | Périmètre étroit ; absent = 0, excusé = neutralisé, note en attente = bloque le jury ; seules les décisions « de droit » sont calculées, le reste est saisi par le jury ; distinction « deuxième session » (remplace) et « rattrapage » (remplace si meilleure) | Documentation éditeur |
| Cameroun | Paiement rattaché au matricule avec suivi partiel ou total, quitus téléchargeable (Douala, Yaoundé I, Maroua) ; plateforme nationale d'authentification des diplômes depuis octobre 2024 | Presse et sources tierces |
| UCAD Dakar, mars 2025 | Fraude par un agent qui modifiait des notes après coup, découverte par dénonciation et non par un contrôle | Presse |

Non trouvé : aucun nom fiable de logiciel de scolarité pour les universités d'État camerounaises hormis le portail de Douala ; aucun texte camerounais vérifié sur l'anonymat, la compensation ou les délais de requête. Ces règles sont donc traitées ici comme des choix de l'établissement.

## 3. Points bloquants

### B1. Une note se rouvre après une délibération arrêtée, sans alerte — vérifié

`backend/app/Services/Lmd/SaisieNotes.php:92-107` : `rouvrir()` exige une permission et un motif, mais ne regarde pas si une délibération arrêtée porte sur cette note. Seule la voie « requête » marque la délibération « à rectifier » (`backend/app/Services/Lmd/Requetes.php:351-354`). Les résultats annuels restent figés par déclencheur, mais notes et résultats peuvent diverger en silence.

Correction proposée : refuser la réouverture dès qu'une délibération arrêtée couvre l'ECUE, et n'autoriser la correction que par la rectification déjà prévue.

### B2. Le PV de délibération n'est pas archivé — vérifié

`backend/app/Services/Lmd/PvDeliberation.php:32-49` : le PDF est régénéré à chaque téléchargement. Seule l'empreinte du premier rendu est conservée, sans le fichier, et le rendu change ensuite avec les signatures. Le PV ne porte ni QR code ni code de vérification.

Correction proposée : archiver le PDF à l'arrêt, puis une version signée, par le même mécanisme que `DocumentsOfficiels` (numéro, code, QR, empreinte recontrôlée).

### B3. La vérification publique ne prouve pas le contenu — vérifié

`backend/app/Http/Controllers/Api/Public/VerificationDocumentController.php` renvoie type, numéro, date, titulaire et statut. C'est un choix de confidentialité (RG-059), mais un PDF falsifié portant un vrai QR ressort « authentique ».

Correction proposée : sur la page `/verifier/{code}`, permettre de déposer le PDF reçu ; l'empreinte est calculée dans le navigateur et comparée à celle du document archivé. Aucune note n'est exposée.

### B4. Passage en M2 sur « M1 suivi » et sans traitement de groupe — vérifié

`backend/app/Services/Scolarite/ReinscriptionAuditeur.php:55-61` : `aSuiviLeM1()` teste l'existence d'une inscription M1, pas un résultat « admis ». La voie ACMA, elle, vérifie « admis » (`backend/app/Services/Acma/ReinscriptionAncien.php:228`, rapporté). Il n'existe pas de passage M1 → M2 en groupe ni d'écran pour changer l'année en cours. À régler avant la rentrée 2027.

## 4. Constat par domaine

### 4.1 Offre de formation

En place : Parcours → Maquette (année × parcours) → UE → ECUE, crédits, heures CM/TD/TP/TPE, pondérations par UE, validation sous verrou, reconduction d'une année sur l'autre, contrôles de cohérence (`backend/app/Services/Lmd/ControleMaquette.php`).

| Écart | Référence | Gravité |
|---|---|---|
| Pas de table « année académique » ni de semestre ; l'année est une chaîne répétée, celle d'une promotion est déduite du nom de la campagne | `backend/app/Models/CampagneCandidature.php:72-82` | Important |
| Domaine et mention absents (attendus par le CAMES § B.4 sur relevés et diplômes) | migration `2026_11_07_100000` | Important pour les documents |
| Le gel d'une maquette validée ne tient qu'à l'interface | `backend/app/Policies/MaquettePolicy.php:28-31` | Important |
| Le catalogue d'UE n'est pas versionné : renommer une UE réécrit les années passées | `UniteEnseignement` | Important |
| Règles d'évaluation modifiables après délibération | `backend/app/Policies/RegleEvaluationPolicy.php:28-31` | Important |
| Une maquette à 58 crédits se valide alors que la décision exige 60 | `ControleMaquette.php:64-68`, `CalculScenario.php:110` | Important |
| Pas d'UE optionnelle ou à choix, ni de prérequis | `InscriptionsPedagogiques.php:52-59` | Confort |

### 4.2 Scolarité et admissions

En place : chaîne candidature → acceptation → matricule → auditeur → inscription annuelle → inscription pédagogique, matricule sous verrou et index uniques, suspension sur demande écrite, certificat de scolarité.

| Écart | Référence | Gravité |
|---|---|---|
| Le redoublant est réinscrit à toutes les UE ; les statuts « capitalisée » et « dispense » ne sont posés nulle part | `backend/app/Models/AcquisUe.php:16-21` | Important |
| Abandon, exclu, diplômé sont informatifs : l'auditeur reste dans les listes de notes et les délibérations | `SaisieNotes.php:42-51`, `CalculScenario.php:40-43` | Important |
| Équivalences, VAE, transferts absents (CAMES § B.2, B.3) | — | Important |
| Changement de parcours en erreur non gérée dès qu'une note existe | `ParcoursActions.php:61-68` | Important |
| Acceptation en lot sans transaction ni verrou | `ActionsGroupees.php:326-338` | Important |
| Journal écrit à la main par les services : une édition directe de `Candidature`, `Auditeur` ou `InscriptionAnnuelle` n'est pas tracée | — | Important |
| Carte d'étudiant absente (CAMES § B.2.4) | — | Confort |

### 4.3 Saisie des notes

En place : écran unique, rôle `enseignant` limité au CC et à l'assiduité de ses ECUE, modèle Excel et import avec essai à blanc, étapes fermée → ouverte → verrouillée, historique en ajout seul par déclencheur, motif obligatoire.

| Écart | Référence | Gravité |
|---|---|---|
| Import tout ou rien, erreurs tronquées à 15, pas de rapport ligne à ligne | `SaisieNotes.php:185-187` | Important |
| Double saisie sous anonymat seulement | — | Important |
| Absence justifiée et injustifiée valent toutes deux 0 (ScoDoc neutralise l'excusé) | `CalculNotes.php:73` | Important, à trancher |
| Ouvrir, saisir et verrouiller peuvent être la même personne | — | Important |
| Pas de fenêtre de saisie liée au calendrier | — | Confort |
| Le poids nommé `ptp` porte l'assiduité | — | Confort (voir décision D4) |

### 4.4 Anonymat

En place et solide : numéros à clé de contrôle, correspondance chiffrée par une phrase secrète non stockée, deux opérateurs distincts et un troisième en cas d'écart, levée avec témoin et PV scellé, verrouillage bloqué par toute copie non constatée (`backend/app/Services/Lmd/Anonymat.php`).

| Écart | Référence | Gravité |
|---|---|---|
| Le PDF d'étiquettes contient numéro, matricule et nom en clair, et circule ensuite | `EpreuveAnonymeResource/Pages/ViewEpreuveAnonyme.php:86-103` | Important |
| Phrase secrète perdue : épreuve irrécupérable, sans procédure de secours | — | Important |
| La levée écrit les absences sans ligne d'historique | `Anonymat.php:317-318` | Important |

### 4.5 Calcul, rattrapage, jury

En place : moteur unique (`CalculNotes` appelé par `CalculScenario` et par la saisie), règles lues en base par année, éligibilité au rattrapage calculée, dérogations tracées, scénarios, mesures de jury motivées et bornées, arrêt irréversible, rectification par délibération liée.

| Écart | Référence | Gravité |
|---|---|---|
| Aucun niveau semestre : ni moyenne ni crédits semestriels | `CalculScenario.php` | Important |
| Instantané partiel : le détail par UE et les notes d'ECUE ne sont pas figés, les grades sont relus dans la règle courante | `ResultatsAuditeur.php:57-66` | Important |
| La grille des mentions n'a presque aucun effet, la mention vient de la grille des grades | `CalculScenario.php:257-275` | Important |
| Seuil comparé sur 3 décimales, affichage à 2 | — | Voir décision D2 |
| Pas de liste officielle des admis au rattrapage | — | Important |
| Signatures papier attestées sans pièce jointe | `Deliberations.php:338-353` | Important |
| Un « cas particulier » valide une UE avec n'importe quelle note | `Deliberations.php:485-498` | À encadrer |
| Tests : cas nominal couvert, bornes peu couvertes (arrondi au seuil, ex æquo, mentions, rattrapage plus bas, réouverture après arrêt) | `tests/Unit/Lmd/CalculNotesTest.php` | Important |

### 4.6 Documents officiels

Générables aujourd'hui : PV annuel, liste d'affichage, relevé annuel, attestation de réussite, certificat de scolarité, autorisation de dépôt, émargement des épreuves anonymes, reçus.

Pour le relevé, l'attestation, le certificat et l'autorisation : numéro de série, code de 12 caractères, QR, PDF archivé avec empreinte recontrôlée, révocation motivée (`backend/app/Services/Lmd/DocumentsOfficiels.php:84-123`).

| Demandé | État |
|---|---|
| PV de délibération par semestre | Absent |
| PV de délibération par année | Présent, non archivé (B2) |
| Relevé semestriel | Absent |
| Relevé annuel | Présent |
| Attestation de réussite | Présente |
| Diplôme et annexe descriptive | Absents |
| Liste d'émargement d'examen | Épreuves anonymes seulement |
| Fiche de présence en cours | À l'écran seulement |
| QR code et numéro de série | Présents sauf sur le PV |
| Filigrane, signature | Absents |
| Registre global des documents délivrés | Absent (liste par fiche auditeur) |

### 4.7 Finances

En place : barème par année et niveau (inscription et deux tranches datées), sept modes de paiement dont espèces, virement, Orange Money et MTN Money, reçu numéroté immuable, annulation par écriture, contre-passation à deux auteurs, confirmation sur bordereau, impayés calculés à la lecture, tiers payeurs, extrait de compte, tableau de bord de recouvrement.

| Écart | Référence | Gravité |
|---|---|---|
| Règle « solde → accès » éclatée en quatre notions (`inscriptionSoldee`, `enRetard`, `Solvabilite`, suspension) | `AccesEcampus.php:64-69`, `DocumentsOfficiels.php:166-171`, `Solvabilite.php:10-16` | Important |
| Trois dérogations qui se recouvrent : `DeblocageAcces`, demande de moratoire, `LeveeRestriction` | `DeblocagesAcces.php`, `DemandeMoratoire.php:80-85`, `LeveeRestriction.php:10-17` | Important |
| Relevé de notes, résultats et accès aux examens non bloqués en cas d'impayé | `DocumentsOfficiels.php:206-219` | Voir décision D5 |
| Un déblocage échu resterait ouvert, relancé mais jamais révoqué | `InscriptionAnnuelle.php:213-217`, `RelancesDeblocages.php:12-18` | Important, à confirmer |
| Pas d'échéancier individuel : le moratoire n'ouvre que l'accès | `DeblocageAcces.php:12-31` | Important |
| Pas de relance automatique des tranches échues | `routes/console.php:52-55` | Important |
| Pas de clôture de caisse ni de rapprochement bancaire des encaissements | — | Important |
| Situation financière codée deux fois, en PHP et en SQL | `InscriptionAnnuelle.php:179-239` | Fragilité |

### 4.8 Planification

En place : séance = ECUE + enseignant + salle + plage, conflits bloquants (enseignant, salle, parcours, jour non ouvré, indisponibilité, hors période) et alertes (capacité, volume dépassé), publication refusée s'il reste un conflit, pointage des enseignants, états de paie et contrôle de gestion.

| Écart | Référence | Gravité |
|---|---|---|
| Aucun export ni impression de l'emploi du temps | `EmploiDuTempsResource/Pages/ViewEmploiDuTemps.php:28` | Important |
| Aucune notification à la publication ou au report | `EmploisDuTemps.php` | Important |
| Pas de planning d'examens, de répartition en salles ni de surveillants (CAMES § B.7) | — | Important |
| `Seance` porte une salle en texte libre et une salle référencée ; `SeanceResource` crée des séances sans contrôle de conflit | `Seance.php:30`, `SeanceResource.php:61-72` | Doublon |

### 4.9 Requêtes

En place : dépôt par l'auditeur avec pièces, délai de 10 jours, attribution automatique, séance de jury, PV numéroté et scellé, validation par un chef qui n'a pas instruit, correction effective de la note, journal visible de l'auditeur.

| Écart | Référence | Gravité |
|---|---|---|
| Requête recevable avant toute publication | `Requetes.php:55-56`, `380-386` | Important |
| L'enseignant n'est pas une étape, son avis est une pièce versée par l'agent | `RequetePiece.php:21` | Important |
| Aucune notification des changements d'état | `Requetes.php` | Important |
| Deux « délais des requêtes » de même nom (dépôt 10 j, traitement 30 j) | `ControleGestion.php:28-30` | Confort |

### 4.10 Portails et pilotage

- **Auditeur** : scolarité, résultats, emploi du temps, requêtes, parcours, mémoire, messages, documents, reçus, relevé, vérification publique. Limite : la situation financière ne couvre que l'année courante.
- **Enseignant** : pas de portail distinct, un rôle dans le panneau d'administration limité à ses cours. Manquent « mon emploi du temps », le récapitulatif de ses heures et une ergonomie mobile.
- **Direction** : tableau de bord financier complet. Aucun tableau de bord académique (taux de réussite, effectifs par parcours et promotion, comparaison entre années), alors que le CAMES en fait un domaine à part entière (§ B.12).

## 5. Décisions du chef de projet (22/11/2026)

| N° | Sujet | Décision |
|---|---|---|
| D1 | Note éliminatoire | 7 par défaut, valeur paramétrable, et la règle doit pouvoir être activée ou désactivée par année |
| D2 | Arrondi | Proposition ci-dessous validée le 22/11 |
| D3 | Rattrapage | Mode paramétrable. Les modes existants sont donc conservés (la simplification envisagée est abandonnée) |
| D4 | Note de TP | Le TP correspond à l'assiduité. Pas de quatrième note ; seul le libellé est à clarifier |
| D5 | Impayés | Le relevé de notes et l'accès aux examens sont bloqués en cas de solde débiteur, avec dérogation |

### Proposition pour l'arrondi (D2)

Règle : **la valeur affichée fait foi.**

1. Les calculs intermédiaires (ECUE) restent en pleine précision.
2. Chaque moyenne publiée (UE, semestre, année) est arrondie au centième, le demi vers le haut.
3. Le seuil de validation et la note éliminatoire sont comparés à cette valeur arrondie.

Conséquences : 11,995 devient 12,00 et l'UE est acquise ; 11,994 devient 11,99 et ne l'est pas. Un document ne peut plus afficher « 12,00 » avec la mention « non acquis ». Aucun arrondi de faveur automatique : faire passer un 11,99 reste une mesure du jury, motivée et tracée. Des tests aux bornes (11,994 ; 11,995 ; 6,995 ; 7,00) accompagnent le changement.

## 6. Simplifications recommandées

- Un seul objet « année académique » avec ses semestres, source unique de l'année en cours.
- Une seule « dérogation financière » (portée, motif, pièce, échéance, révocation automatique) et une seule règle de solvabilité.
- Un gel à la délibération : notes, maquette et règles deviennent intouchables, toute correction passe par la rectification.
- Trois écrans pour l'enseignant, utilisables sur téléphone : mes cours, mes notes, mes heures.
- Une page de direction : effectifs, taux de réussite, recouvrement.
- Retraits : grille de mentions sans effet (ou la brancher réellement), écran Campagnes / Promotions en double, salle en texte libre, champ `pv_pdf_sha256` inutile en l'état.

## 7. Plan proposé

Plan d'ensemble validé par le chef de projet le 22/11/2026. Chaque étape fait l'objet d'un plan détaillé validé avant tout code, selon la règle Plan → Act → Verify.

1. Gel après délibération et archivage du PV avec QR code (B1, B2).
2. Vérification publique par dépôt du PDF et comparaison d'empreinte (B3).
3. Année académique, semestres, PV et relevés semestriels ; arrondi selon D2.
4. Passage M1 → M2 en groupe sur « admis », gestion des redoublants et des statuts abandon / exclu (B4).
5. Dérogation financière unique, blocage des relevés et des listes d'examen (D5), relance des tranches.
6. Écrans enseignant et rapport d'import ligne à ligne.
7. Planning d'examens, listes d'émargement, notifications (emploi du temps, requêtes).
8. Tableau de bord académique et registre des documents délivrés.

## 8. Questions encore ouvertes

- Note éliminatoire : la Scolarité avait répondu le 08/11 « pas de note éliminatoire, paramètre désactivé par défaut » ; la décision D1 du 22/11 la fixe à 7. À faire confirmer par la Scolarité avant la prochaine délibération.
- Validation ECUE par ECUE : demandée par la Scolarité le 08/11 (chaque ECUE se valide seul à 12/20 et apporte ses crédits). L'audit a relevé une note d'UE pondérée par les crédits des ECUE (`RegleEvaluation.php:42-46`) ; à vérifier que le recodage a bien été fait.

- Absence justifiée : compte-t-elle 0, ou neutralise-t-elle l'épreuve jusqu'à un examen de remplacement ?
- Signature des documents : image de signature et cachet, ou signature manuscrite sur un tirage numéroté ?
- Diplôme et annexe descriptive : produits par la plateforme ou par la tutelle ?
- Équivalences et transferts : cas réels à prévoir, ou hors périmètre pour un institut à recrutement sur concours ?
