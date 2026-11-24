# Module 4 — Scolarité : virements en deux temps, remplacement d'un bordereau

**Date** : 2026-11-24. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI (demande et choix du 23-24/11/2026).
Cette spec complète la spec encaissement-confirmation (§1, §10) et l'espace Finances (ADR-0015). Le reste ne change pas.

## 1. Constat

- Un virement s'enregistre aujourd'hui comme un paiement à l'agence : versement et reçu en un geste, l'argent compte aussitôt. Or, au moment où l'auditeur présente son ordre de virement, l'argent n'est pas forcément arrivé sur le compte du PSSFP.
- Exemple (23/11) : un virement de 685 000 FCFA saisi le 12/11, confirmé, sans reçu. Cinq virements du 10 au 13/11 sont dans ce cas.
- Un bordereau joint à un versement ne se remplace pas : la base n'autorise que le premier dépôt. Un scan illisible ou erroné reste attaché au versement.

## 2. Virement en deux temps (service financier)

1. **Annonce.** Dans le formulaire d'encaissement, le mode « Virement bancaire » n'enregistre plus de versement. Il enregistre un **virement annoncé** : montant, référence, payeur, date de l'ordre et scan de l'ordre ou de l'avis de virement, obligatoire. Pas de reçu.
2. **Hors compte.** Un virement annoncé **ne compte pas** dans le compte de l'auditeur : ni versé, ni accès eCampus, ni listes « à jour ». Pour un accès en attendant, la voie d'exception existe déjà : l'accès anticipé « virement annoncé » (A1).
3. **Validation.** Quand le virement apparaît sur le relevé du compte, le service financier le **valide** en saisissant la **date de paiement**, c'est-à-dire la date du crédit.
   - Le versement est créé à cette date, mode « Virement bancaire », avec la référence, le montant, le payeur et le scan de l'annonce.
   - Son reçu est émis dans la même opération, et l'auditeur est prévenu comme pour tout encaissement.
   - La date de paiement ne précède pas la date de l'ordre et n'est pas dans le futur.
   - La scolarité confirme ensuite le versement comme les autres, sur le scan du service financier (§10).
4. **Abandon.** Un virement jamais arrivé s'abandonne, avec un motif d'au moins 10 caractères. Rien n'est supprimé.
5. **Unicité.** Une référence ne sert qu'à un virement en attente, et jamais à un versement en vigueur.
6. **Droits.** Annoncer, valider et abandonner exigent `scolarite.versement_saisir`. La validation exige aussi `scolarite.recu_emettre`. Ce sont les droits de l'encaissement : service financier et super administrateur.
7. **Écran.** Finances › « Virements à valider » : virements en attente (pastille de menu), validés, abandonnés ; actions « Voir l'ordre », « Valider », « Abandonner ».
   - Une section « Virements enregistrés sans reçu » reprend les virements saisis avant ce circuit, sans reçu. Leur action « Émettre le reçu » garde la date enregistrée. Une date fausse se corrige par la correction à double regard (ADR-0015).
8. **Contrôle interne.** `VIR_ATTENTE` (mineur, 7 jours, service financier) : un virement annoncé en attente depuis plus de 15 jours.
9. **Inchangé.** Le règlement d'un organisme reste en un geste : sa date est déjà celle du crédit.
10. **Exception : la correction à double regard** (ADR-0015). Elle peut créer un remplaçant par virement en un geste : elle corrige un versement déjà crédité. Aucun versement ne peut en revanche reprendre la référence d'un virement annoncé en attente : elle n'encaisse que par sa validation.

## 3. Remplacement d'un bordereau (administrateur)

1. **Qui.** Nouvelle permission sensible `scolarite.justificatif_remplacer`, accordée à `admin` et `super_admin` par migration additive.
2. **Geste.** Action « Remplacer le bordereau » sur un versement qui en a un : nouveau scan et motif d'au moins 10 caractères. Un versement annulé, ou celui d'un dossier retiré, ne se modifie pas.
3. **Rien n'est effacé.** L'ancien fichier est conservé. Chaque remplacement est enregistré (table `remplacements_justificatifs`, en ajout seul : ancien chemin, nouveau chemin, motif, auteur, date), et l'historique reste consultable (« Anciens bordereaux »). Le journal `scolarite` le note.
4. **Confirmation gardée** (choix du 24/11) : la confirmation de la scolarité reste valable, et elle garde la référence de la pièce qu'elle a vérifiée.
5. **Base.** Le déclencheur `versements_immuables` admet le changement de `justificatif_path` d'un versement non annulé seulement si la transaction a enregistré le remplacement correspondant.

## 4. Données

- `virements_annonces` : uuid, auditeur, inscription, montant, référence, payeur, date de l'ordre, scan, auteur et date de saisie, statut (`en_attente`, `valide`, `abandonne`), date de paiement, versement créé, auteur et date du traitement, motif d'abandon. Les contraintes portent la cohérence de chaque statut. Un déclencheur fige le reste : il n'autorise qu'une seule transition, depuis « en attente », et aucune suppression. Index unique partiel sur la référence en attente.
- `remplacements_justificatifs` : versement, ancien chemin, nouveau chemin, motif, auteur, date. En ajout seul.

## 5. Tests

1. Annonce : hors compte, scan obligatoire, référence unique ; pas de versement ni de reçu.
2. Validation : versement à la date de paiement, reçu émis, scan repris, dates bornées, une seule fois même sous deux validations ; abandon motivé.
3. Formulaire : le mode « Virement bancaire » annonce au lieu d'encaisser.
4. Remplacement : réservé à l'administrateur, motif, ancien fichier conservé, confirmation gardée ; refus sur versement annulé ; base : changement sans remplacement enregistré refusé.
5. `VIR_ATTENTE`.
