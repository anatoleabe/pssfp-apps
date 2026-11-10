# Module 4 — LMD, lot 14a : contrôle interne (catalogue de contrôles, anomalies, tableau de bord)

**Date** : 2026-11-10. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D30 du 30/10/2026 (module « Suivi, relance, audit et contrôle du système », demande de M. MBIANA) ; `sql/19_schema_supervision.sql` (tables `controle_systeme`, `anomalie_systeme`, fonction `executer_controle`).

Le lot 14 est découpé en quatre :
- **14a** (ce lot) : catalogue de contrôles, cycle de vie des anomalies, tableau de bord du contrôle interne ;
- **14b** : relances graduées et messagerie interne (D30, D31) ;
- **14c** : demandes d'autorisation, avec routage et double décision (D31) ;
- **14d** : audit renforcé (chaîne d'empreintes du journal, verrouillage des comptes, revue des habilitations).

## 1. Objectif

Que la plateforme se surveille elle-même. Des contrôles s'exécutent chaque jour, et chacun ouvre une anomalie pour chaque cas anormal qu'il trouve. Une anomalie se ferme seule quand le cas disparaît. Sinon, quelqu'un la prend en charge ou la justifie, et tout est tracé.

## 2. Règles

1. **Contrôle** : un code, un libellé, un domaine, une criticité (bloquant, majeur, mineur), une fréquence (quotidienne, hebdomadaire, mensuelle) et un délai de traitement en jours. Chaque contrôle est une classe PHP qui renvoie ses cas : une clé stable et un détail lisible, sans donnée personnelle superflue.
2. **Catalogue du lot**, limité aux contrôles que les données déjà en base permettent de calculer :

   | Code | Contrôle | Criticité | Délai |
   |---|---|---|---|
   | `PAI_RAPPROCHEMENT` | Écarts entre la paie et les virements (lot 13c-1) | bloquant | 5 j |
   | `PAIE_ETATS_BLOQUES` | États de paie ni payés ni rejetés, sans changement depuis 15 jours | majeur | 3 j |
   | `REQ_RETARD` | Requêtes ouvertes au-delà du délai de traitement (lot 13c-2) | majeur | 2 j |
   | `REQ_NON_COTEES` | Requêtes déposées depuis plus d'un jour, non cotées | majeur | 1 j |
   | `SEC_SEPARATION` | Un compte réunit des droits incompatibles (règle 3) | bloquant | 3 j |
   | `SEC_COMPTES` | Comptes du personnel sans connexion depuis 90 jours | mineur | 15 j |
   | `SYS_CONTROLES` | Contrôles en erreur lors de leur dernière exécution | majeur | 1 j |

   Les lots suivants ajoutent leurs contrôles au catalogue : calendrier, emplois du temps, programmation, archives, crédits.
3. **Séparation des tâches** (D30). Couples incompatibles :
   - instruire et valider une requête ;
   - valider les heures et modifier les coordonnées bancaires ;
   - coordonnées bancaires et états de paie ;
   - administrer les habilitations et consulter le journal ;
   - préparer et signer le calendrier.

   Chaque couple est contrôlé sur les comptes du personnel, rôles compris. Un cumul voulu (le super administrateur par exemple) se **justifie**.
4. **Exécution** (port de `executer_controle`) : chaque jour à 05:30, et à la demande depuis l'écran.
   - Un cas nouveau ouvre une anomalie, avec une échéance égale au jour de détection plus le délai du contrôle.
   - Un cas encore présent met à jour sa dernière détection et son détail.
   - Un cas disparu ferme l'anomalie en « corrigée » (fermeture automatique).
   - Une anomalie justifiée reste justifiée tant qu'elle est détectée.
   - Un contrôle qui échoue n'interrompt pas les autres : l'erreur est enregistrée et alimente `SYS_CONTROLES`.
   - Chaque exécution est enregistrée : début, fin, cas trouvés, nouveaux, corrigés, erreur.
5. **Traitement manuel** (`supervision.traiter`) :
   - **prendre en charge** une anomalie ouverte (elle passe « en traitement » et lui est assignée) ;
   - **justifier** une anomalie acceptée (motif d'au moins 10 caractères).

   Les deux sont journalisés (journal `supervision`). La correction se constate : c'est l'exécution suivante qui ferme l'anomalie.
6. **Écran « Contrôle interne »** (groupe Administration) :
   - un tableau de bord par contrôle : anomalies ouvertes, en retard, justifiées, corrigées sur 30 jours, délai moyen de correction ;
   - la liste des anomalies, filtrable par contrôle, statut et criticité ;
   - l'action « Exécuter les contrôles ».

   Pastille : anomalies ouvertes ou en traitement dont l'échéance est passée. Elle remonte dans l'encart « À traiter ».
7. **Droits** : `supervision.consulter` (super_admin, admin, responsable de scolarité) et `supervision.traiter` (super_admin, admin), accordés par migration additive, puis attribuables depuis l'écran Rôles.

## 3. Modèle de données

- `anomalies_systeme` : uuid, `controle_code`, `cle`, `detail` (jsonb), `premiere_detection`, `derniere_detection`, `echeance` (date), `statut` (`ouverte`, `en_traitement`, `corrigee`, `justifiee`), `assigne_a`, `justification`, `traite_par`, `traite_le`, `fermee_auto`. Index unique partiel sur (`controle_code`, `cle`) pour les statuts actifs. Contrainte : une anomalie justifiée porte sa justification et son auteur.
- `executions_controles` : `controle_code`, `debut`, `fin`, `trouves`, `nouveaux`, `corriges`, `erreur`.

## 4. Tests

1. Cycle de vie : ouverture, mise à jour, fermeture automatique, une justifiée qui reste justifiée, réouverture d'un cas réapparu après sa correction.
2. Un contrôle en erreur n'arrête pas les autres et remonte dans `SYS_CONTROLES`.
3. Chaque contrôle du catalogue sur un cas positif et un cas négatif.
4. Traitement : prise en charge, justification (motif obligatoire), droits.
5. Écran : accès selon les droits, pastille et encart « À traiter », tableau de bord.
