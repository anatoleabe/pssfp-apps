# Module 4 — LMD, lot 26 : certificat de scolarité au modèle officiel, attestation de recherche, lettre de stage

**Date** : 2026-11-23. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Source** : trois modèles remis le 23/11/2026 (« certificat de scolarité 2026 », « attestation de recherche », « lettre de recommandation » pour un stage), à produire depuis la plateforme.

## 1. Objectif

Que la scolarité produise depuis la fiche d'un auditeur les trois documents administratifs que le PSSFP rédigeait à la main, avec le texte des modèles.

## 2. Règles

1. **Trois documents administratifs**, sous l'en-tête du PSSFP (Ministère des finances), signés « Le Président du Comité de Pilotage » :
   - **Certificat de scolarité** : texte du modèle (identité, date et lieu de naissance, année académique, cycle, niveau). Il remplace le texte du lot 25.
   - **Attestation de recherche** : inscription de l'auditeur, thème de son mémoire et encadreur. Le thème et l'encadreur sont repris du dossier de mémoire s'il existe, et restent modifiables à la délivrance.
   - **Lettre de stage** : destinataire, formule d'appel, nature et durée du stage, thème. Tout est saisi à la délivrance.
2. **Qui délivre.** Le droit du lot 25 (`lmd.certificat_delivrer` : administrateurs et scolarité), depuis la fiche de l'auditeur. L'auditeur ne produit aucun de ces documents ; seul le certificat délivré reste visible dans son espace (lot 25).
3. **Conditions.** Celles du certificat : inscription en cours, auditeur ni en abandon ni exclu, en règle de ses frais ou restriction levée.
4. **Registre.** Chaque document est numéroté (`CS`, `AT`, `LS`), scellé, vérifiable par son code et révocable, comme les autres documents délivrés.
   - Certificat et attestation de recherche : un seul document valide par inscription. Pour changer le thème, on révoque puis on délivre à nouveau.
   - Lettre de stage : autant que de structures d'accueil.
5. **Référence du courrier et date.** Le numéro de chrono (« N° 26/____/L/MINFI/SG/PSSFP/PCP/UPAAS ») et la date de signature restent à compléter à la main, comme sur les modèles : le courrier est enregistré et signé hors de la plateforme.
6. **Accords.** « inscrit / inscrite », « Monsieur / Madame » suivent le genre et la civilité du dossier ; à défaut, la forme « (e) ».

## 3. Modèle de données

Une migration : deux types de document admis (`attestation_recherche`, `lettre_stage`) ; l'unicité « un document valide par inscription » ne s'applique plus à la lettre de stage.

## 4. Tests

- Certificat : texte du modèle, signataire, naissance.
- Attestation de recherche : délivrée par la scolarité, refusée aux autres rôles, refusée s'il en existe une valide, champs obligatoires.
- Lettre de stage : plusieurs lettres pour un même auditeur, numéros distincts.
- Refus pour un auditeur en retard de paiement ou sorti.

## 5. Hors périmètre

- Pied de page des modèles (boîte postale, téléphones, ancien site) : les documents gardent le pied de vérification de la plateforme.
- Image de la signature et cachet : question ouverte de l'audit (§ 8).
- Contrat, lettre et communiqué des enseignants : lot à part.
