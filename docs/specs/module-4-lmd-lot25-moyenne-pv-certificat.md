# Module 4 — LMD, lot 25 : moyenne simple, PV d'admission avec ou sans « AS », certificat de scolarité

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : décisions du 23/11/2026, à la lecture des PV finaux signés de la promotion 11.

## 1. Règles

1. **Moyenne.** La moyenne d'un auditeur est le total de ses notes d'UE divisé par le nombre d'UE notées, sans pondération par les crédits (semestre, année, jury d'admission). Elle ne compense rien : une UE sous le seuil n'est pas acquise, quelle que soit la moyenne. La MGP reste pondérée par les crédits. Le PV affiche le total des points.
2. **Délibérations déjà arrêtées.** Leurs résultats, scellés, ne changent pas. La règle vaut pour tout calcul fait à partir de ce lot.
3. **PV d'admission avec ou sans « AS ».** À la création du jury d'admission, la scolarité choisit : « admis seulement » (les auditeurs restés « autorisés à soutenir » ne figurent ni aux résultats ni au PV) ou « admis et autorisés à soutenir » (défaut). Le choix se modifie tant que la délibération est en préparation, et le calcul doit alors être refait.
4. **Certificat de scolarité.** Il n'est plus produit par l'auditeur. Un agent ou le responsable de la scolarité, ou un administrateur, le délivre depuis la fiche de l'auditeur (droit « Délivrer le certificat de scolarité »). Une fois délivré, l'auditeur le retrouve dans son espace ; sinon l'espace l'invite à le demander à la scolarité. Les autres conditions (inscription en cours, auditeur en règle) sont inchangées.

## 2. Modèle de données

- `deliberations.admis_seulement` (booléen, faux par défaut).
- Droit `lmd.certificat_delivrer` : super_admin, admin, scolarite_responsable, scolarite_agent (migration additive).

## 3. Tests

- Moyenne simple sur des UE de crédits inégaux ; total des points au PV.
- Jury d'admission « admis seulement » : l'auditeur resté AS n'a pas de résultat.
- Certificat : refusé à l'auditeur tant qu'il n'est pas délivré, servi ensuite ; délivrance refusée au service financier.
