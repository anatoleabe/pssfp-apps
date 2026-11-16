# ADR-0015 — Correction des versements par contre-passation liée, jamais par modification ni suppression

**Statut** : Accepté (16/11/2026)
**Date** : 2026-11-16
**Décideur** : Chef USI (chef de projet), sur demande du service financier ; décisions du 16/11/2026 (spec, §12, Q1 à Q12)
**Référence** : [spec de l'espace Finances](../specs/module-4-scolarite-espace-financier.md), §7 et §8 ; [ADR-0013](0013-compte-de-l-auditeur.md), qu'elle précise ; [ADR-0011](0011-lmd-pssfp-systeme-dans-la-plateforme.md) (SQL brut sur les tables d'argent) ; lot 14c (demandes d'autorisation)

## Contexte

Le service financier enregistre un versement et émet son reçu en un seul geste. La scolarité confirme ensuite le versement sur le reçu bancaire original, et seul l'argent confirmé ouvre les accès. Des erreurs de saisie arrivent : montant, référence, date, mode, payeur, mauvais auditeur, doublon. Le service financier demande alors qu'on « supprime l'enregistrement » pour le ressaisir.

Contraintes constatées dans le code :

- La base interdit déjà la suppression et la modification des versements (`versements_immuables`, D1), des reçus et de leurs annulations (lot 4), des imputations (D1) et des confirmations (15/11/2026). Seules l'annulation et le bordereau, une fois chacun, sont permis sur un versement.
- L'annulation existe, mais elle est réservée au responsable de scolarité et aux administrateurs (`scolarite.versement_annuler`), et elle n'est liée à rien. Une correction faite en deux gestes (annuler, puis ressaisir) fait disparaître l'argent du compte entre les deux. Elle perd aussi la confirmation et peut couper l'accès à eCampus. Les deux reçus ne se citent pas.
- La séparation des tâches doit être conservée : le service financier n'annule pas sa propre saisie et ne confirme pas.

## Décision

**Une erreur sur un versement se corrige par une contre-passation liée et atomique, enregistrée comme une pièce de correction en ajout seul. Rien n'est jamais modifié ni supprimé.**

1. **Pièce de correction.** Une nouvelle table `corrections_versements`, immuable, numérotée `COR-AAAA-NNNNN` sans trou, relie :
   - le versement annulé et, pour un remplacement, le versement remplaçant ;
   - l'écriture d'annulation du reçu et le nouveau reçu ;
   - la nature, la catégorie et le motif ;
   - les valeurs avant et après ;
   - le demandeur, l'exécutant et, s'il y a lieu, la demande d'autorisation.
2. **Une transaction**, sous le verrou des auditeurs concernés pris par `id` croissant :
   - annulation de l'ancien versement, de ses imputations (R4) et de son reçu, sans imputation d'avance intermédiaire ;
   - nouveau versement et ses imputations (R2), puis l'avance (R3) une seule fois ;
   - nouveau reçu, qui cite celui qu'il remplace ;
   - pièce de correction.

   À montant et auditeur égaux, les imputations finales sont celles d'avant.
3. **Deux regards.** Le service financier demande (nouvelle permission `scolarite.versement_corriger`). Un titulaire de `scolarite.versement_annuler` décide, par le circuit du lot 14c, avec un nouveau type `corriger_versement`. L'exécution agit sous deux identités, chacune dans son propre droit :
   - l'annulation, au nom du décideur ;
   - la nouvelle saisie et le reçu, au nom du demandeur.

   Une rectification descriptive immédiate par le service financier seul est retenue (spec, Q2 tranchée le 16/11/2026) : référence, date, mode ou payeur, à montant et auditeur égaux, sur un versement non confirmé, dans les 48 h. Elle produit la même pièce.
4. **La confirmation se reporte, elle ne se modifie pas.** Une nouvelle ligne de `confirmations_versements` est liée à la pièce (colonne `correction_id`, contrainte `CHECK` élargie) :
   - elle est reportée d'office à montant et auditeur égaux ;
   - sinon, elle est attestée par le décideur, qui détient `scolarite.versement_confirmer`.

   Une correction à montant et auditeur égaux ne change donc jamais l'accès de l'auditeur.
5. **Lien entre reçus.** Une colonne `recus_versements.remplace_recu_id`, écrite à l'insertion, relie le nouveau reçu au reçu annulé. Les déclencheurs d'immuabilité des versements, des reçus et des confirmations ne sont pas modifiés.

## Conséquences

### Positives

- Le besoin du service financier (« toujours possible, avec des traces ») est couvert sans affaiblir aucune garantie de la base.
- Le contrôle interne et la comptabilité reconstituent chaque correction par une pièce unique : qui, quand, pourquoi, avant et après, quels reçus.
- L'auditeur ne perd jamais son argent ni son accès pour une faute de saisie qui n'est pas la sienne. Il reçoit un reçu qui explique le remplacement.
- La séparation des tâches existante est réutilisée : le demandeur ne décide jamais, et le contrôle `SEC_SEPARATION` est étendu.

### Négatives ou trade-offs

- Une table d'argent de plus, un compteur de plus, deux colonnes ajoutées, une contrainte `CHECK` remplacée, et du SQL brut à maintenir (contraintes, déclencheur réutilisé).
- Le circuit des demandes du lot 14c doit savoir exécuter avec l'identité du demandeur en plus de celle du décideur. C'est une extension générique de `TypesDemande::executer`.
- Une correction du changement d'auditeur verrouille deux auditeurs. Cela déroge à « un seul auditeur par transaction » (R8), dans l'ordre déjà prévu pour D4.
- Le second regard introduit un délai (24 h, avec relances).
- L'objet du nouveau reçu peut découper les paliers autrement que le reçu remplacé, à total égal : le remplaçant arrive après les versements enregistrés entre-temps.

### Neutres ou à surveiller

- Un versement imputé sur plusieurs années reste non corrigeable tant que D4 n'est pas livré (même refus que R4.1).
- Une part de règlement d'organisme ne se corrige qu'avec son règlement entier. Une correction de règlement sur le même modèle pourra suivre.
- Le volume des corrections par agent est suivi par le contrôle `VER_CORRECTIONS`. Un volume élevé signale un problème de saisie ou d'écran, autant qu'un risque.

## Alternatives envisagées

- **Annulation par le responsable puis ressaisie libre (statu quo)** : rien à développer, mais aucun lien entre les pièces, un trou dans le compte entre les deux gestes, une confirmation perdue et un accès parfois coupé.
- **Modification en place avec table d'historique** : simple pour l'agent, mais contraire à l'immuabilité garantie par la base (D1, lot 4) et à l'usage comptable. Le reçu figé contredirait le versement modifié, et il faudrait affaiblir trois déclencheurs.
- **Colonne `remplace_versement_id` sur `versements`** : permise par le déclencheur (écrite à l'insertion), mais elle ne porte ni l'annulation sans remplacement, ni le motif catégorisé, ni l'avant/après, ni les deux auteurs, ni le report de la confirmation. Elle oblige aussi à toucher la table d'argent.
- **Suppression physique puis ressaisie** : interdite par la base depuis D1, et contraire à toute exigence de traçabilité.

## Références

- `docs/specs/module-4-scolarite-espace-financier.md` : règles R-C1 à R-C12, données, permissions, tests, questions ouvertes.
- `docs/specs/module-4-scolarite-comptes-d1.md` : R2 à R4, R7, R8, déclencheurs.
- `docs/specs/module-4-scolarite-lot4-recus.md` : écriture d'annulation des reçus, numérotation.
- `docs/specs/module-4-scolarite-encaissement-confirmation.md` : confirmation, contrôle croisé.
- `docs/specs/module-4-lmd-lot14c-demandes-autorisation.md` : circuit des demandes, empreinte, paramètres figés.
