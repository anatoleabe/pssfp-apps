# Module 4 — LMD, lot 9 : anonymat des copies

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : décision D36 (proposée, à valider) ; RG-045 à RG-052 ; critères ANO-01 à ANO-28 ; `sql/24_schema_anonymat.sql` ; registre V-10 à V-12, V-15, V-16.

## 1. Objectif

Les notes d'examen se saisissent sur des numéros d'anonymat. Personne, administrateur compris, ne relie une copie à un auditeur sans la clé du gestionnaire des anonymats. La levée est autorisée par un témoin, exécutée par le gestionnaire, tracée et irréversible.

## 2. Règles

1. **Épreuve** : un ECUE pour une session (normale ou rattrapage). États :
   - préparation ;
   - anonymisée ;
   - verrouillée ;
   - levée (irréversible).
2. **Numéros** (RG-045) :
   - 9 caractères : 8 tirés par générateur cryptographique dans un alphabet de 32 signes (sans 0, 1, I, O), plus un caractère de contrôle ;
   - uniques dans l'épreuve ;
   - attribués dans un ordre aléatoire, sans lien avec le matricule ni l'ordre alphabétique.
3. **Correspondance chiffrée** (RG-046) :
   - numéro ↔ inscription, chiffrée (AES-256-GCM) avec une clé dérivée de la phrase secrète du gestionnaire ;
   - la phrase n'est jamais stockée ;
   - une empreinte de contrôle détecte une mauvaise clé ;
   - toute consultation de la correspondance est tracée.
4. **Séparation des tâches** (RG-047) :
   - le gestionnaire des anonymats (`lmd.anonymat_gerer`) ne saisit pas de notes ;
   - l'opérateur de saisie (`lmd.anonymat_saisir`) ne voit que des numéros ;
   - le témoin de la levée (`lmd.notes_verrouiller`, responsable de la scolarité) n'est pas le gestionnaire.
5. **Documents** :
   - étiquettes à talon rabattable avec le QR code du numéro ;
   - liste d'émargement sans numéro ;
   - bordereaux de notes par correcteur, avec les numéros seulement.
6. **Constats** (RG-050) :
   - présente, absente (pas de note), blanche (0), fraude (signalée), égarée (puis traitée, avec décision motivée) ;
   - candidat hors liste : bloquant jusqu'à décision.
7. **Double saisie** (RG-048) : deux opérateurs différents saisissent chaque note ; un écart est tranché par un troisième.
8. **Double correction** (RG-049, V-10 à V-12) :
   - seconde correction pour les notes de la zone 10 à 12 (exclu) ;
   - troisième correction si deux corrections diffèrent de plus de 3 points ;
   - note retenue : la moyenne des corrections, ou la troisième.
9. **Verrouillage** (RG-051) : refusé s'il reste une copie présente sans note, un écart non tranché, une copie non constatée, égarée non traitée ou hors liste.
10. **Levée** (RG-052) :
    - autorisée par le témoin, exécutée par le gestionnaire avec la clé ;
    - les notes sont reportées dans les notes d'examen (lot 5) ;
    - l'étape « examen » de l'ECUE est verrouillée ;
    - PV de levée scellé (SHA-256) ;
    - irréversible.
11. **Requêtes sur l'examen** (lot 8) : recevables seulement après la levée de l'anonymat.

## 3. Écrans

- **Épreuves** (gestionnaire) :
  - création ;
  - attribution des numéros (avec la phrase secrète) ;
  - étiquettes et émargement en PDF ;
  - constats ;
  - bordereaux ;
  - verrouillage ;
  - levée.
- **Saisie anonyme** (opérateurs) : grille des numéros, première et seconde saisie, écarts.
- **Autorisation de levée** (témoin).

## 4. Critères d'acceptation

1. Les numéros sont uniques, au bon format, avec un caractère de contrôle valide ; leur ordre ne suit pas l'ordre alphabétique.
2. Sans la bonne phrase secrète, aucune correspondance ne se lit ; une mauvaise phrase est refusée.
3. Le gestionnaire ne saisit pas ; l'opérateur ne voit que des numéros ; une seconde saisie par le même opérateur est refusée.
4. Le verrouillage est refusé dans chacun des cas de la règle 9.
5. La levée sans témoin est refusée. Après la levée, les notes sont dans le lot 5 et l'étape d'examen est verrouillée ; une nouvelle levée est impossible.
6. Aucun numéro d'anonymat n'est stocké avec les notes (SAI-07).
