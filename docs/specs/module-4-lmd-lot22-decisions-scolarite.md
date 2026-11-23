# Module 4 — LMD, lot 22 : droit d'inscrire, abandons et exclus, semestre 4

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011, 0013.
**Source** : décisions du 23/11/2026 sur les questions laissées ouvertes par les lots 17a et 18.

## 1. Décisions reçues

1. **Semestre 4.** Le M2 sort des écrits avec 44 crédits : le jury déclare « autorisé à soutenir » (AS) les auditeurs qui les ont. La soutenance porte le total à 60 crédits ; un jury spécial prononce alors « admis » (AD).
2. **Validation UE par UE**, sans dépendance entre UE. C'est déjà le calcul en place : rien à changer.
3. **Abandon et exclu.** L'auditeur reste dans la liste des auditeurs, mais son nom ne figure pas sur les relevés, les PV et les autres documents.
4. **Inscrire.** Le passage en groupe, l'inscription et la réinscription sont réservés aux administrateurs et à la scolarité. Le service financier ne les fait pas.
5. **Relances** des tranches : activables dans la configuration (lot 23).

## 2. Règles de ce lot

1. **Droit « Inscrire ».** Nouveau droit `scolarite.inscrire` : réinscrire un auditeur depuis sa fiche et lancer le passage en groupe. Accordé aux rôles super-administrateur, administrateur, responsable de scolarité et agent de scolarité. Saisir ou confirmer un versement ne donne plus ce droit.
2. **Abandon et exclu.** Un auditeur au statut « abandon » ou « exclu » :
   - n'entre pas dans le calcul d'une délibération : il est absent du PV, de la liste d'affichage et des résultats ;
   - n'obtient aucun document (certificat de scolarité, relevé de notes, attestation), même sur des résultats déjà publiés ;
   - reste visible dans la liste des auditeurs, les inscriptions et les listes de notes.
   Un PV déjà arrêté et archivé n'est pas réécrit.
3. **Semestre 4.** Le semestre 4 ne se délibère pas seul : il porte la soutenance, et la décision des écrits (AS à 44 crédits) est celle de la délibération annuelle. Les semestres 1, 2 et 3 restent délibérables.

## 3. Modèle de données

Migration additive : le droit `scolarite.inscrire` et son attribution aux quatre rôles. Aucune table ne change.

## 4. Tests

- Le service financier ne peut ni réinscrire ni lancer le passage en groupe ; l'agent de scolarité le peut.
- Un auditeur « abandon » est absent du calcul de la délibération ; son relevé est refusé.
- Une délibération du semestre 4 est refusée ; celle du semestre 3 est acceptée.

## 5. Hors périmètre

- **Jury spécial d'admission** après la soutenance (AS → AD à 60 crédits) : aujourd'hui aucune délibération ne le porte. Lot 24, à concevoir.
- Relance des tranches par SMS, activable (lot 23).
