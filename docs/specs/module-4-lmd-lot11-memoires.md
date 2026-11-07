# Module 4 — LMD, lot 11 : mémoires et autorisation de dépôt

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`.
**Sources** : dossier « PSSFP Système » : décisions D32, D33, D34 ; RG-061 ; `sql/21_schema_memoires.sql` ; registre V-13, V-17, V-18.

## 1. Objectif

La Scolarité suit l'avancement du mémoire de chaque auditeur autorisé à soutenir (AS). L'auditeur suit le sien et dépose ses livrables dans son espace. À la fin, il obtient une **attestation d'autorisation de dépôt**, délivrée selon le respect des étapes. Le dépôt final est refusé sans elle.

## 2. Règles

1. **Dossier de mémoire** : ouvert pour un auditeur AS (délibération du M2 arrêtée). Il porte :
   - le thème, la structure d'accueil, la période de stage ;
   - l'encadreur académique (obligatoire pour l'attestation) et l'encadreur professionnel ;
   - le statut : en rédaction, prêt à soutenir, soutenu, suspendu ou abandon (motif) ;
   - la vague et la date de soutenance.
2. **Étapes** (D32), datées et pondérées :

   | Étape | Poids |
   |---|---|
   | Début de rédaction | 0 |
   | Cadrage | 5 |
   | Dépôt du projet, validé par les deux encadreurs | 15 |
   | 1re pré-soutenance | 30 |
   | 2e pré-soutenance | 30 |
   | Dépôt final (15 jours avant la soutenance) | 10 |
   | Soutenance | 10 |

   Statuts d'une étape : à faire, déposée, validée, ajournée (avec appréciation), dispensée. Les pré-soutenances ont une note. Chaque document déposé a son empreinte SHA-256.
3. **Avancement** = somme des poids des étapes validées, avec l'estimation de l'encadreur à côté. Toutes les étapes validées avant la soutenance : « prêt à soutenir » ; soutenance validée : « soutenu ».
4. **Fiches de suivi d'encadrement** : date, mode, avancement estimé, chapitres rédigés, observations.
5. **Attestation d'autorisation de dépôt** (D33, D34). Conditions :
   - thème enregistré ;
   - encadreur académique désigné ;
   - cadrage, dépôt du projet et deux pré-soutenances validés ;
   - étapes validées dans les délais, ou retard autorisé par le chef de la scolarité (motif) ;
   - avis favorable de dépôt de l'encadreur académique (le plus récent fait foi) ;
   - rapport de similitude le plus récent au plus à 20 % (V-13, provisoire).

   Les conditions manquantes s'affichent en clair, à tout moment.
6. **Délivrance** : par le chef de la scolarité seulement (permission `lmd.memoires_attester`). Document numéroté `AD-AAAA-NNNNNN`, vérifiable par QR code (lot 7), signé « Le Chef de la scolarité ». Une seule attestation valide par mémoire ; une correction passe par la révocation puis une nouvelle délivrance.
7. **Dépôt final** : refusé sans attestation valide.

## 3. Écrans

- **Administration** :
  - Mémoires : liste par parcours, avancement, retards, encadreur ;
  - fiche avec étapes, suivis, avis, similitude, retards autorisés, conditions de l'attestation ;
  - ouverture des dossiers des AS d'une délibération.
- **Espace auditeur**, rubrique « Mon mémoire » : étapes et dates, avancement, dépôt du projet et des versions (PDF), conditions de l'attestation, attestation téléchargeable.

## 4. Critères d'acceptation

1. Un dossier vide liste ses conditions manquantes ; l'attestation est refusée avec elles.
2. Un avis défavorable bloque. Un rapport de similitude à 27,5 % bloque, à 12,4 % passe.
3. Une étape validée en retard bloque jusqu'à l'autorisation du chef de la scolarité.
4. L'attestation est refusée à l'agent et délivrée au chef de la scolarité. Une seconde attestation est refusée tant que la première est valide.
5. Le dépôt final est refusé sans attestation, accepté avec.
6. Avancement : un mémoire validé jusqu'au dépôt final est à 90 % et « prêt à soutenir ».
