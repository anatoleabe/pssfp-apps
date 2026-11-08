# Module 4 — LMD, lot 13b-1 : barème et états de paie mensuels des enseignants

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Suite du lot 13a (dossier de paie et pointage).
**Sources** : dossier « PSSFP Système » : décisions D23, D24 et D25 du 29/10/2026 ; `sql/16_schema_enseignants_paie.sql` (sections 2 et 5) ; `reprise/25_calendrier_2026_2027.sql` (barème 2026-2027). Réponses de la Scolarité du 08/11/2026 : surveillance payée au barème (question 8), arrondi au quart d'heure (9), format PDF pour le service financier (14).

Le lot 13b est découpé en deux :

- **13b-1** (ce lot) : barème, état de paie mensuel des heures de cours et des surveillances, circuit jusqu'au virement, PDF de l'état et du bordereau ;
- **13b-2** : primes d'animation en ligne (synthèses, import d'un export WhatsApp), ajoutées à l'état du mois.

## 1. Objectif

Chaque mois, la Scolarité établit l'état de paie des enseignants à partir des pointages validés (lot 13a). L'état suit un circuit jusqu'au virement. Une heure n'est payée qu'une fois, et seulement si elle est validée et comprise dans les heures allouées à l'enseignant.

## 2. Règles

1. **Barème**, par année académique et par nature :
   - un montant unitaire (FCFA), un taux de retenue à la source, la référence du texte et un statut (proposé ou validé) ;
   - seul un barème **validé** sert à payer ;
   - le barème 2026-2027 est livré (D24, D25) : CM 17 500 FCFA l'heure, retenue de 11 % ; TD et TP à 0 FCFA (heures tracées, non payées) ; prime d'animation 100 000 FCFA par cours, retenue de 11 % (lot 13b-2) ; **surveillance** par séance, montant à fixer (proposé, à 0 tant qu'il n'est pas validé).
2. **Surveillance** (question 8) : une séance d'évaluation se pointe désormais ; sa nature est « surveillance ». Elle est payée à la séance, au barème, sans plafond d'allocation.
3. **État du mois** (numéro `EP-AAAA-MM`) : un seul état ordinaire par mois civil (les états rejetés ne comptent pas). Sa génération reprend les pointages validés, non encore rattachés à une ligne de paie, dont la séance tombe dans le mois.
   - **Lignes de cours** : par enseignant, ECUE et nature (CM, TD, TP). Les heures allouées sont celles des programmations **validées, notifiées ou acceptées** (une simple proposition n'ouvre pas droit au paiement). Les heures payées sont plafonnées aux heures allouées pour cette nature, moins l'allocation déjà consommée (heures payées et heures imputées au service dû, sur les états non rejetés). Le reste est « hors quota » : porté sur la ligne, jamais payé.
   - **Permanent** : dans la limite de l'allocation, les heures sont d'abord imputées sur son service dû annuel, puis payées. Le service dû restant se lit sur les imputations déjà portées sur les états ; des heures hors quota ne le consomment pas.
   - **Tout pointage examiné est rattaché** à une ligne (heures payées, imputées ou hors quota) : il ne se représente plus à un état suivant. Seuls les pointages d'un enseignant écarté restent libres.
   - **Surveillance** : une ligne par enseignant et ECUE, quantité = nombre de séances.
   - **Écartés** : un enseignant sans dossier de paie complet (NIU, compte), inactif, ou sans barème validé pour la nature n'est pas porté sur l'état. Il est listé avec la raison ; ses pointages restent disponibles pour un état suivant.
   - **Montants** : brut = quantité × montant unitaire ; retenue = brut × taux ; net = brut − retenue, arrondis au franc.
   - Les pointages portés sur une ligne sont figés (lot 13a).
   - Un état en brouillon se **régénère** : ses lignes sont retirées, ses pointages libérés, puis il est recalculé (régénération journalisée).
   - Une seule génération à la fois (verrou de la paie) ; une séance annulée n'est pas payée, et une séance dont le pointage enseignant est validé ne s'annule plus.
4. **Circuit** (D24) : brouillon (Scolarité) → **vérifié** (responsable de la scolarité) → **visé** (contrôleur interne, seulement si le paramètre `lmd.visa_controleur_paie` est actif ; inactif par défaut) → **transmis** au service financier (référence du bordereau obligatoire) → **pris en charge** (service financier) → **payé** (date et référence du virement obligatoires).
   - **Rejet motivé** avant la prise en charge : il libère les pointages, repris dans un nouvel état du mois. Un état transmis ne se rejette que par le service financier, qui détient le bordereau.
   - Après la prise en charge, pas de rejet : une erreur se corrige par un **état rectificatif** (`EP-AAAA-MM-R1`, R2…) du même mois, qui reprend les pointages validés restants. Un seul rectificatif en cours à la fois, et jamais vide.
   - **Séparation des tâches** (relecture de sécurité ; même principe que les reçus et les pointages) : qui prépare ou génère l'état ne le vérifie pas ; le contrôleur et le service financier sont d'autres personnes que celles des étapes précédentes ; personne ne vérifie, ne vise ni ne prend en charge un état où figurent ses propres heures. Une ligne du barème modifiée est validée par un autre responsable.
   - La vérification porte sur la version affichée : si l'état a été régénéré entre-temps, elle est refusée.
   - Un état sans ligne ne se vérifie pas. La date du virement ne précède pas la prise en charge.
   - Le visa se règle dans l'administration (`settings.manage`), pas par la Scolarité ni le contrôleur ; un état déjà visé se transmet même si le visa est désactivé ensuite.
5. **Documents (PDF)** : l'état (une ligne par enseignant : heures de CM payées, heures de TD et TP faites, service dû imputé, hors quota, surveillances, brut, retenue, net, totaux) et le bordereau de transmission (statut, nombre d'enseignants, heures de CM, brut, retenue, net, référence ; disponible à partir de la transmission). Un état non transmis porte la mention « PROJET », un état rejeté « REJETÉ — NE PAS PAYER ». Montants : brut = arrondi(quantité × montant) ; retenue = arrondi(brut × taux).
6. **Permissions** :
   - `lmd.paie_preparer` (gestion) : barème proposé, création, génération et régénération d'un état. Agent et responsable de la scolarité, admin, super_admin ;
   - `lmd.paie_verifier` (sensible) : valider le barème, vérifier, transmettre et rejeter un état. Responsable de la scolarité, admin, super_admin ;
   - `lmd.paie_viser` (sensible, autonome) : visa du contrôleur interne. super_admin (à attribuer depuis l'écran des rôles si le visa est activé) ;
   - `lmd.paie_payer` (sensible, autonome) : prise en charge et paiement, rejet d'un état transmis. Rôle `service_financier`, super_admin.

## 3. Écrans

Un groupe de navigation **Paie des enseignants** réunit : Pointages des enseignants, Dossiers de paie, Suivi des heures enseignées (lot 13a), Barème des enseignants et États de paie.

- **Barème des enseignants** : par année ; modifier (montant, retenue, référence) ; valider.
- **États de paie** :
  - liste (mois, état, enseignants, brut, net) ; action « Préparer l'état d'un mois » ;
  - fiche : lignes, enseignants écartés et leur raison, totaux ; actions du circuit selon l'état et la permission ; régénérer (brouillon) ; PDF de l'état et du bordereau ; état rectificatif (payé ou pris en charge).

## 4. Critères d'acceptation

1. Essai de D24 (barème validé, CM à 17 500 FCFA, retenue de 11 %) : 6 h de CM et 3 h de TD validées dans le mois → ligne CM 105 000 brut, 11 550 de retenue, 93 450 net ; ligne TD de 3 h à 0 FCFA.
2. 9 h de CM allouées : après 8,5 h payées, un mois suivant à 3 h validées donne 0,5 h payée et 2,5 h hors quota. Un TD ne se paie pas comme un CM.
3. Permanent avec 9 h allouées et un service dû de 6 h : 9 h validées → 6 h imputées, 3 h payées ; 3 h de plus le même mois (rectificatif) → hors quota, non payées. 3 h sur un cours non alloué ne consomment pas le service dû.
4. Un enseignant sans NIU est écarté et listé ; ses pointages restent libres.
5. Un second état ordinaire du même mois est refusé. Transmettre sans bordereau, payer sans référence, viser quand le visa est inactif, sauter une étape : refusés.
6. Un rejet avant prise en charge libère les pointages ; un état payé ne se rejette pas et admet un rectificatif.
7. Une séance d'évaluation pointée et validée est payée en surveillance au barème.
