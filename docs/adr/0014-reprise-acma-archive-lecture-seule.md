# ADR-0014 — Reprise d'ACMA : copie de transit, archive en lecture seule et dossier d'ancien auditeur sans candidature

**Statut** : Accepté (15/11/2026)
**Date** : 2026-11-15
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026 sur la reprise d'ACMA)
**Référence** : [spec du lot 15](../specs/module-4-lot15-reprise-acma.md) ; [ADR-0010](0010-vie-academique-auditeurs-phase-1.md) ; [ADR-0011](0011-lmd-pssfp-systeme-dans-la-plateforme.md) ; [ADR-0013](0013-compte-de-l-auditeur.md) ; analyse du schéma `gestionacademic` (dossier « PSSFP Système », annexe 5)

## Contexte

ACMA est l'ancien logiciel de scolarité (WinDev, MySQL 5.7, base `gestionacademic`). Le chef de projet veut tout reprendre dans la plateforme, puis le retirer. L'export complet du 15/11/2026 contient :

- 2 778 fiches d'auditeurs et 10 038 inscriptions, de 2013-2014 à 2026-2027 ;
- 49 987 notes et 309 580 versions de notes ;
- 7 453 lignes de paiement ;
- 801 PV de soutenance et 197 certificats.

Contraintes :

- **Le modèle vivant ne sait pas représenter un ancien auditeur.** `auditeurs.candidature_id` est NOT NULL et unique. L'identité, le matricule et le compte se lisent à travers la candidature (ADR-0010). Une candidature exige un téléphone, une date de naissance et une adresse. ACMA n'a aucune date de naissance exploitable pour 449 fiches, aucun e-mail et aucun numéro de pièce.
- **Les résultats d'ACMA ne sont pas des résultats LMD.** Les maquettes d'ACMA ne sont pas les nôtres : l'« UE » d'ACMA correspond à peu près à notre ECUE, et 14 lignes de programme ont des pondérations dont la somme n'est pas 1. Aucune délibération n'est tracée. Le chef de projet a décidé de ne pas refaire les délibérations passées ni de recréer de PV (décision 1 du 15/11/2026).
- **Les paiements d'ACMA ne sont pas fiables comme créances.** On y trouve 324 lignes négatives, des reçus datés de 2050 et 49 groupes de doublons exacts. Calculé depuis ACMA, l'arriéré atteint 1,5 milliard de FCFA sur 1 701 auditeurs-années. Les arriérés n'entrent au compte qu'au cas par cas, après validation (décision 3 ; décision 10 du lot D).
- **Les données sont réelles et personnelles.** Elles comprennent des mots de passe en clair et des identifiants FTP.
- **La reprise doit pouvoir se rejouer** sur un export plus récent tant qu'ACMA n'est pas arrêté.
- **La production n'a pas de MySQL.**

## Décision

1. **Copie de transit brute dans un schéma PostgreSQL séparé, `acma_transit`.**
   - Sur le poste de l'USI, une commande lit un MariaDB jetable chargé avec l'export. Elle écrit un CSV par table utile et un manifeste : colonnes, nombre de lignes, empreinte SHA-256 de chaque fichier.
   - Ne sortent jamais d'ACMA : les mots de passe, les identifiants FTP et la clé de la plateforme, ni les tables du groupware et du journal d'ACMA.
   - En production, une seconde commande recharge ce lot de fichiers dans `acma_transit` : toutes les colonnes en texte, aucune transformation, et le manifeste vérifié.
   - Le transit se supprime et se recharge à volonté. Il est purgé après le gel.
2. **Une archive distincte du modèle vivant, dans des tables `acma_*`.** Elle comprend les dossiers, les inscriptions, les résultats et les notes, les versions des notes, les paiements, les barèmes, les soutenances et les documents d'ACMA. Une seule commande l'écrit, `acma:reprendre`, à blanc par défaut.
   - La commande est idempotente : chaque ligne a une clé naturelle d'ACMA et l'empreinte de sa source.
   - Une ligne disparue d'un export plus récent est marquée, jamais supprimée.
3. **L'archive est en lecture seule, puis gelée.**
   - Un déclencheur refuse toute écriture hors du mode reprise, que la commande ouvre dans sa transaction (`SET LOCAL app.reprise_acma = 'on'`). Il refuse aussi `TRUNCATE`.
   - Le gel (`acma:geler`) est définitif : après lui, même le mode reprise est refusé.
   - Seule exception : le lien d'un dossier vers un auditeur (`auditeur_id`, statut du rapprochement). Il reste modifiable après le gel, mais seulement par l'arbitrage, qui écrit d'abord sa décision dans une table en ajout seul.
   - Les corrections passent par des compléments en ajout seul (`acma_complements`), datés, motivés et signés de leur auteur. Il y en a deux sortes : une date de naissance tirée du dossier papier, une décision transcrite d'un PV signé.
4. **Un ancien auditeur est un dossier d'archive, pas un auditeur.**
   - `acma_dossiers` porte l'identité minimale qu'ACMA connaît : nom complet, sexe, date et lieu de naissance, nationalité, matricule.
   - Il n'a ni candidature, ni compte, ni espace, ni accès eCampus, et ne reçoit aucun envoi.
   - Un lien facultatif vers `auditeurs` rattache le dossier d'un auditeur de la plateforme (P13). Le lien se pose seul sur un matricule identique et une identité concordante, sinon il s'arbitre.
   - **Le modèle vivant ne change pas** : `auditeurs.candidature_id` reste NOT NULL.
5. **Pas de reprise dans le LMD.**
   - L'« UE » et le groupe d'UE d'ACMA restent une photographie annuelle (`acma_unites`). Aucune maquette, UE, ECUE, note par ECUE, délibération ni PV n'est créé.
   - L'archive affiche les valeurs enregistrées par ACMA (décision, moyenne). Elle les accompagne de valeurs recalculées selon les règles d'ACMA, marquées « indicatif ».
   - Seul pont vers le LMD : les acquis des redoublants encore en formation. Il passe par une table de correspondances validée ligne par ligne, et `acquis_ue` reçoit l'origine `correspondance`.
6. **L'argent d'ACMA est un historique, pas des versements.**
   - Les paiements jusqu'en 2025-2026 sont archivés en lecture seule. Ceux de 2026-2027 ne sont pas repris : ils ne servent qu'au rapport des écarts.
   - Un arriéré n'entre au compte que par une demande du circuit du lot 14c (`porter_arriere_acma`). Il faut deux personnes : l'agent qui demande et le responsable qui approuve, sans action directe.
   - L'exécution suit l'ADR-0013 :
     - elle se fait sous le verrou de l'auditeur ;
     - elle crée l'inscription annuelle de l'année passée, aux montants du barème de cette année ;
     - si l'auditeur avait payé une partie, elle enregistre un versement de mode `reprise_acma`, du dû moins l'arriéré validé. Ce versement est réservé à cette inscription par son mode, n'a pas de reçu et passe par `ImputationsCompte` (R2), comme tout versement ;
     - elle applique ensuite R3, l'imputation de l'avance.
   - L'entrée est refusée si l'auditeur n'a pas d'inscription plus récente, car l'année passée deviendrait alors son année courante.
7. **Les documents délivrés depuis l'archive passent par `documents_delivres`.**
   - Deux types : `releve_historique` et `attestation_historique`.
   - L'archive peut en être propriétaire : `acma_inscription_id`, avec `auditeur_id` et `inscription_annuelle_id` rendus facultatifs sous une contrainte `CHECK`.
   - Numérotation, scellement, vérification publique et révocation restent ceux du lot 7.
8. **SQL brut autorisé**, en plus des exceptions des ADR-0010, 0011 et 0013 :
   - création, chargement (`COPY`) et suppression du schéma `acma_transit` ;
   - déclencheurs de lecture seule et de gel de l'archive, et déclencheur d'ajout seul des compléments ;
   - contraintes `CHECK` et index partiels des tables `acma_*` et de `documents_delivres`.

   La transformation elle-même s'écrit en PHP (Query Builder et `upsert` par lots), et la normalisation dans des classes pures testées.

## Conséquences

### Positives

- Rien ne change pour les auditeurs, les candidatures, l'espace, eCampus, les envois et les statistiques de campagne. Un ancien auditeur ne peut ni recevoir un SMS ni ouvrir un compte par erreur.
- L'historique est consultable, et des relevés et attestations vérifiables se délivrent après le retrait d'ACMA.
- La reprise se répète à l'identique : sur une copie locale de la production, puis sur l'export final.
- Les chiffres d'ACMA restent ceux d'ACMA. Les écarts (décisions absentes, notes écrasées, paiements douteux) sont visibles au lieu d'être « corrigés » sans trace.
- Le compte de l'auditeur garde ses invariants : barème copié, versements et imputations écrits par les seuls services prévus, verrou de l'auditeur, cohérence vérifiée par la base.

### Négatives ou trade-offs

- Deux représentations d'une même personne : le dossier d'archive et, pour la P13, l'auditeur. Le lien doit être arbitré quand l'identité diverge.
- La fiche d'archive est une vue en lecture seule, distincte de la fiche de l'auditeur. Pour les auditeurs liés, un onglet « Historique ACMA » relie les deux.
- Un ancien auditeur qui demanderait plus tard un accès en ligne devra être créé comme auditeur, puis lié.
- Le compte doit traiter un mode de versement de plus, `reprise_acma`, réservé par son mode et exclu des reçus. Le planificateur et `RecusVersements` changent légèrement, avec des tests.
- Les décisions absentes d'ACMA ne sont pas reconstituées. Plusieurs cohortes resteront sans décision, et sans attestation, tant que la scolarité ne les aura pas transcrites des PV signés.
- Le transit contient des données personnelles entre le chargement et la purge. Il entre donc dans les sauvegardes quotidiennes pendant cette période.

### Neutres ou à surveiller

- L'arbitrage du rapprochement n'est pas gelé : c'est un lien entre l'archive et le modèle vivant, tracé et réversible avec motif.
- Les colonnes d'ACMA connues pour être fausses (`notes.moy`, `du.Versement`, `du.RestePayer`, `du.montantautres`, `notes.anneeacademic`) sont gardées dans le transit et ne sont jamais affichées comme vraies.
- Avec un arriéré au compte, les versements suivants de l'auditeur règlent d'abord cet arriéré (R1 de l'ADR-0013). La demande montre cet effet avant l'approbation. La reprise d'un arriéré ouvre la situation « un versement sur deux années », dont l'annulation exige la demande d'autorisation du sous-lot D4 : D4 est un prérequis.

## Alternatives envisagées

- **Candidature et auditeur d'archive pour chaque ancien**, comme l'import de la P13. Écartée :
  - il faudrait inventer des valeurs pour les champs obligatoires (téléphone, date de naissance, adresse) ;
  - les campagnes, les statistiques et les PV d'admission seraient pollués ;
  - chaque liste, relance, synchronisation eCampus ou envoi devrait exclure ces dossiers, et un seul oubli enverrait un SMS à un ancien, contre la décision 4.
- **Rendre `auditeurs.candidature_id` facultatif et porter l'identité sur l'auditeur.** Écartée : tout le code lit l'identité par la candidature (ADR-0010). La régression toucherait l'argent, l'espace et eCampus pour 2 400 lignes qui n'en ont pas besoin.
- **Reprise dans les tables du LMD**, comme les scripts du dossier « PSSFP Système » : maquettes par année, résultats, acquis, délibérations « reprise ». Écartée :
  - elle contredit la décision 1 ;
  - les contrôles de nos maquettes (pondérations, ECUE, mémoire) refusent les programmes d'ACMA ;
  - les résultats arrêtés exigent une délibération et un PV.
- **pgloader, ou une connexion MySQL directe depuis la production.** Écartée :
  - les conversions implicites de types sont risquées (dates `0000-00-00`, années 1120 ou 2230) ;
  - la production n'a ni client MySQL ni accès réseau au poste ;
  - le chemin n'a pas de manifeste vérifiable.

  Le transit tout en texte, avec conversion explicite et contrôlée, reprend la leçon des scripts « PSSFP Système » (`20_copie_acma.py`).
- **Schéma PostgreSQL séparé pour l'archive elle-même.** Écartée : `migrate:fresh` et les tests ne gèrent que le schéma courant. Un préfixe `acma_` suffit, avec le déclencheur de lecture seule. Le transit, lui, est un schéma à part : il n'est pas migré et il est jetable.
- **Arriéré porté comme une inscription dont le dû est l'arriéré lui-même**, sans versement de reprise. Plus simple, mais l'inscription ne serait plus au barème de son année (ADR-0013, point 2). Et l'auditeur ne verrait pas ce qui a été tenu pour payé.
- **Garder ACMA en lecture seule.** Écartée : WinDev et MySQL 5.7 sont obsolètes, les mots de passe sont en clair, et ACMA continue d'être utilisé pour 2026-2027, ce qui crée une double saisie.

## Références

- `docs/specs/module-4-lot15-reprise-acma.md` : inventaire, correspondances, modèle, arriérés, mécanique, sous-lots, cas à arbitrer.
- `docs/specs/module-4-scolarite-comptes-d1.md` (§6, §7) et `module-4-scolarite-comptes-auditeur-proposition.md` (décision 10).
- `docs/specs/module-4-lmd-lot14c-demandes-autorisation.md` et `module-4-scolarite-acces-anticipe.md` (§10, précédent de la colonne `parametres`).
- `docs/specs/module-4-lmd-lot7-resultats-documents.md` (documents vérifiables).
- `NewACMA/PSSFP_Systeme_package_2026-11-07/3_code_source/reprise/` (scripts 20 à 30) et `5_annexes/analyse_schema_gestionacademic.md`.
