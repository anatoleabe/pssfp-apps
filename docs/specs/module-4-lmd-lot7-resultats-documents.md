# Module 4 — LMD, lot 7 : résultats et documents vérifiables

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : RG-041, RG-059 ; décisions D9, D26 (documents), D37 ; ENF-09, ENF-12, ENF-14.

## 1. Objectif

L'auditeur consulte ses résultats publiés dans son espace et télécharge ses documents officiels :
- relevé de notes ;
- attestation de réussite ;
- certificat de scolarité.

Chaque document est numéroté et vérifiable par QR code sur une page publique qui n'affiche que le minimum. Un document se révoque ; il ne se modifie jamais.

## 2. Règles

1. **Résultats visibles** : seulement ceux des délibérations arrêtées et publiées, non rectifiées (RG-041). Avant la publication, l'auditeur ne voit rien de ses notes.
2. **Relevé de notes** : pour une délibération publiée. Il donne, pour chaque UE :
   - code, intitulé, crédits, note retenue, grade ;
   - mode d'acquisition : acquise, acquise par décision du jury, capitalisée (année), non acquise.

   Il donne aussi les crédits de l'année, la moyenne, la MGP, la décision et la mention.
3. **Attestation de réussite** : pour une décision AD (ou AS en M2 : « autorisé(e) à soutenir »), après publication.
4. **Certificat de scolarité** : pour l'inscription de l'année en cours.
5. **En règle** (D9) :
   - les impayés ne bloquent pas le relevé de notes ;
   - l'attestation de réussite et le certificat de scolarité exigent un auditeur sans échéance en retard, ou une levée de restriction accordée et tracée par le responsable de la scolarité (permission `lmd.documents_lever`).
6. **Numérotation et scellement** :
   - numéro par type et par année : `RN-AAAA-NNNNNN` (relevé), `AR-AAAA-NNNNNN` (attestation), `CS-AAAA-NNNNNN` (certificat) ;
   - code de vérification aléatoire ;
   - empreinte SHA-256 du PDF.

   Le PDF est conservé dans le stockage privé. Un nouveau téléchargement sert le même fichier : le numéro ne change pas tant que le document n'est pas révoqué.
7. **Révocation** (responsable de la scolarité) : motif obligatoire. Le document reste en base, marqué « révoqué » à la vérification. Une rectification de délibération révoque d'office les relevés et attestations qui s'y rapportent.
8. **Vérification publique** (sans connexion, `GET /v1/documents/verification/{code}`, 30 demandes par minute et par adresse) :
   - type, numéro, date, titulaire (nom et prénom), statut (authentique ou révoqué) ;
   - aucune note, aucun matricule, aucune coordonnée.
9. **En-tête bilingue** (ENF-14), polices incorporées (ENF-09).

## 3. Espace de l'auditeur

- Rubrique « Mes résultats » : par année et par session, la décision, les crédits, la moyenne et la mention, puis le détail des UE et leur mode d'acquisition.
- Rubrique « Mes documents » : relevé de notes, attestation de réussite et certificat de scolarité, quand ils sont disponibles. Un message explique pourquoi un document ne l'est pas : résultats non publiés, ou échéance en retard.
- Page publique `/verifier/{code}` du portail.

## 4. Administration

- Fiche auditeur : documents délivrés, avec numéro, date, statut et révocation ; levée de restriction.
- Liste des documents délivrés, avec recherche par numéro.

## 5. Critères d'acceptation

1. Rien n'est visible avant la publication. Après, l'auditeur voit sa décision et ses UE, sans les résultats d'un autre auditeur.
2. Un second téléchargement rend le même fichier, avec le même numéro et la même empreinte.
3. L'attestation est refusée à un auditeur en retard de paiement, puis délivrée après une levée tracée.
4. La vérification d'un code rend « authentique », puis « révoqué » après révocation, sans aucune note.
5. La rectification d'une délibération révoque ses relevés.
