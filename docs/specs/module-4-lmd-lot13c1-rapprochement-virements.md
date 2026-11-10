# Module 4 — LMD, lot 13c-1 : avis de virement et rapprochement de la paie des enseignants

**Date** : 2026-11-10. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011, 0012 (conventions d'API). Suite des lots 13b-1 et 13b-2 (états de paie, primes).
**Sources** : dossier « PSSFP Système » : décision D38 du 07/11/2026 (statut : proposée), constat AUD-021 (pas de rapprochement entre la paie et les virements) ; `sql/25_schema_controle_gestion.sql` (table `avis_virement`, vues `v_rapprochement_paie` et `v_ctl_rapprochement_paie`, contrôle `PAI_RAPPROCHEMENT`).

Le lot 13c est découpé en deux :
- **13c-1** (ce lot) : avis de virement, rapprochement avec les états de paie, contrôle des écarts ;
- **13c-2** : budget et réalisé, coûts par UE et par parcours, tableau de bord mensuel, dossiers en retard.

## 1. Objectif

Un état de paie « payé » porte une seule date et une seule référence de virement pour tout l'état. Rien ne vérifie que chaque enseignant a bien reçu son net. Ce lot enregistre les virements reçus, enseignant par enseignant, tels que les montrent le relevé bancaire ou l'avis du Trésor. Chaque virement est rapproché du net dû, et tout écart est signalé.

## 2. Règles

1. **Avis de virement** : un virement reçu par un enseignant.
   - Champs : enseignant, état de paie (facultatif), montant en FCFA (entier, supérieur à 0), date de valeur, référence bancaire ou numéro de l'avis, source (relevé bancaire, avis du Trésor, saisie). Les colonnes de la pièce justificative (chemin, empreinte SHA-256) sont prêtes ; son téléversement arrive avec l'import des relevés (§6).
   - Une même référence ne s'enregistre qu'une fois pour un même enseignant, sauf si l'avis précédent a été annulé.
   - Un avis rattaché à un état exige que l'enseignant ait au moins une ligne dans cet état.
   - L'état doit être pris en charge ou payé : on ne rapproche pas un état qui n'a pas quitté la Scolarité.
2. **Ajout seul** : un avis ne se modifie pas et ne se supprime pas. Une erreur se corrige par une **annulation motivée** (motif d'au moins 10 caractères, auteur, date), puis par un nouvel avis. La base refuse toute autre modification (déclencheur, ADR-0011).
3. **Saisie** : par le service financier (`lmd.paie_payer`), depuis la fiche de l'état. Une grille liste les enseignants de l'état avec leur net dû ; on saisit la référence, la date et le montant de ceux qui ont été virés. Un virement sans état (« viré sans état ») se saisit depuis l'écran du rapprochement. Pas d'import de fichier dans ce lot : le format d'échange avec le service financier ou GIDOCEP est « NON VÉRIFIÉ » (D38).
4. **Rapprochement**, par état et par enseignant : net dû (somme des `montant_net` de ses lignes) contre viré (somme de ses avis non annulés). Situations :
   - **rapproché** : viré = net dû ;
   - **non viré** : aucun avis ;
   - **viré partiel** : viré < net dû ;
   - **viré en trop** : viré > net dû ;
   - **viré sans état** : avis sans état de rattachement.
5. **Contrôle PAI_RAPPROCHEMENT** : sont des anomalies les situations « viré partiel », « viré en trop », « viré sans état », et « non viré » sur un état **payé**. Elles s'affichent sur l'écran « Rapprochement des virements ». Leur nombre apparaît dans la pastille du menu et dans l'encart « À traiter » du tableau de bord (socle, étape 3), pour le service financier et pour qui vérifie la paie.
6. **États de paie** : la liste et la fiche indiquent l'état du rapprochement (« complet », « écarts », « à faire »). Un état payé reste payé : le rapprochement ne change pas son statut.
7. **Consultation** : service financier (`lmd.paie_payer`) et vérification de la paie (`lmd.paie_verifier`).
8. **Journal** `paie` (`backend/docs/journaux.md`) : `avis_virement_enregistre` et `avis_virement_annule`, avec l'état, l'enseignant, le montant et le motif.
9. **Conventions d'API (ADR-0012)** : identifiant public UUID ; la saisie d'une grille se fait en une transaction, et une même référence rejouée ne crée pas de doublon.

## 3. Modèle de données

`avis_virement` :

| Colonne | Type | Règle |
|---|---|---|
| `id`, `uuid` | bigint, uuid | uuid unique |
| `etat_id` | FK `etats_paie`, nullable | restrict |
| `enseignant_id` | FK `users` | restrict |
| `montant` | bigint | > 0 |
| `date_valeur` | date | |
| `reference` | string(100) | unique avec l'enseignant parmi les avis non annulés (index partiel) |
| `source` | string(16) | `releve_bancaire`, `avis_tresor`, `saisie` |
| `piece_path`, `piece_sha256` | string, nullable | empreinte de 64 caractères hexadécimaux |
| `saisi_par` | FK `users` | |
| `annule_at`, `annule_par`, `motif_annulation` | nullable | posés une seule fois |
| `created_at`, `updated_at` | timestamptz | |

Déclencheur : refuse la suppression, et toute modification autre que la pose unique de l'annulation.

## 4. Écrans

- **Fiche d'un état de paie** (pris en charge ou payé) : onglet « Virements », qui montre le rapprochement par enseignant (net dû, viré, écart, situation, références). Deux actions : « Enregistrer des virements » (grille) et « Annuler un avis » (motif).
- **Rapprochement des virements** (groupe « Enseignants et paie ») : les anomalies de tous les états, un filtre par situation, et l'action « Virement sans état ». Pastille : nombre d'anomalies.
- **Liste des états de paie** : colonne « Rapprochement ».

## 5. Tests

1. Les situations du rapprochement (rapproché, non viré, partiel, en trop, sans état) avec plusieurs avis par enseignant, dont un annulé.
2. Les anomalies : un « non viré » n'en est une que sur un état payé.
3. Refus : un montant nul, un état en brouillon, un enseignant absent de l'état, une référence déjà utilisée (acceptée après annulation), une annulation sans motif ou déjà annulée.
4. La base refuse la modification et la suppression d'un avis ; seule l'annulation passe.
5. Droits : saisie par `lmd.paie_payer` seulement ; consultation par `lmd.paie_verifier` ; refus pour la Scolarité sans ces droits.
6. La pastille et l'encart « À traiter » comptent les anomalies.
7. Le journal `paie` reçoit l'enregistrement et l'annulation.

## 6. Hors périmètre

- Import des relevés bancaires et des avis du Trésor, et téléversement de la pièce justificative : en attente du format d'échange (D38, « NON VÉRIFIÉ »).
- Lot 13c-2 : budget, coûts, tableau de bord mensuel, dossiers en retard.
- Taux de 17 500 FCFA l'heure de CM, prime de 100 000 FCFA, retenue de 11 % : textes à fournir (D38, AUD-022).
