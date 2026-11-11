# Module 4 — LMD, lot 14d-1 : chaîne d'empreintes du journal d'activité

**Date** : 2026-11-11. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011.
**Sources** : dossier « PSSFP Système » : décision D30 du 30/10/2026 (audit : « chaîne d'empreintes SHA-256 du journal : chaque entrée scelle la précédente ; une entrée modifiée ou supprimée, même par un administrateur de la base, est détectée ») ; `sql/19_schema_supervision.sql` (`verifier_chaine_journal`, contrôle `SEC_JOURNAL`).

Le lot 14d (audit renforcé) est découpé en trois :
- **14d-1** (ce lot) : chaîne d'empreintes du journal d'activité ;
- **14d-2** : échecs de connexion, verrouillage des comptes et déverrouillage ;
- **14d-3** : revue annuelle des habilitations.

## 1. Objectif

Que le journal d'activité (`activity_log`, tous domaines) fasse foi. Toute entrée modifiée ou supprimée après coup doit être détectée, même par un accès direct à la base, et signalée au contrôle interne.

## 2. Règles

1. **Scellement** : chaque entrée reçoit un rang dans la chaîne et l'empreinte SHA-256 de son contenu, chaînée à l'empreinte de l'entrée de rang précédent.
   - Contenu scellé : rang, identifiant, journal, description, objet, auteur, propriétés, événement, lot, date de création.
   - Le calcul a lieu dans la base (fonction `activity_log_sceller`), une fois par minute (`audit:sceller-journal`), sur les entrées pas encore scellées, dans l'ordre des identifiants. Toute entrée y passe, qu'elle vienne de l'application ou d'un accès direct.
   - Pourquoi pas à l'insertion : un verrou tenu jusqu'à la fin de chaque transaction bloquerait tout le journal pendant les longues opérations (imports, inscriptions). Et l'ordre des identifiants n'est pas celui des validations de transactions : la chaîne aurait des fausses ruptures. Le rang, attribué au scellement, suit l'ordre réel.
   - Fenêtre assumée : une entrée reste non scellée au plus une minute environ. Une entrée non scellée depuis plus de 10 minutes est une anomalie (scellement arrêté).
2. **Reprise** : les entrées existantes sont scellées une fois, dans l'ordre, par la migration.
3. **Vérification** : la base recalcule chaque empreinte et la compare à la précédente réelle. Est une rupture :
   - une entrée dont le contenu ne correspond plus à son empreinte (modifiée) ;
   - une entrée dont l'empreinte précédente n'est pas celle de l'entrée d'avant (une entrée supprimée ou insérée hors ordre) ;
   - une entrée non scellée depuis plus de 10 minutes ;
   - la disparition du dernier sceau vérifié (suppression des entrées les plus récentes). Le dernier sceau, identifiant et empreinte, est conservé dans les paramètres à chaque vérification réussie.
4. **Contrôle `SEC_JOURNAL`** (bloquant, 1 jour, administrateur) : chaque matin, une anomalie par rupture constatée, avec l'identifiant de l'entrée et la nature de la rupture. Il relance comme les autres contrôles (lot 14b-2).
5. **Commande** `audit:verifier-journal` : vérification à la demande, avec le bilan.
6. **Limite assumée** : un administrateur de la base qui recalculerait toute la chaîne après une falsification ne serait pas détecté par la chaîne seule. Le dernier sceau conservé hors de la table, et à terme sa copie externe (sauvegarde quotidienne), réduisent ce risque.
7. **Reporté** : la détection des modifications faites directement dans la base sur les tables métier (`SEC_HORS_APPLI`). Elle demande un journal par déclencheur sur 29 tables, à traiter avec la reprise (lot 15).

## 3. Modèle de données

`activity_log` reçoit `chaine_rang` (unique), `empreinte` et `empreinte_precedente` (64 caractères hexadécimaux). La fonction `activity_log_contenu` est partagée par le scellement (`activity_log_sceller`) et la vérification (`activity_log_ruptures`).

## 4. Tests

1. Chaque nouvelle entrée est scellée et chaînée ; la reprise scelle l'existant dans l'ordre.
2. Une entrée modifiée, une entrée supprimée au milieu, les entrées les plus récentes supprimées : chaque cas est détecté et ouvre une anomalie `SEC_JOURNAL`.
3. Une chaîne intacte ne remonte rien et met à jour le dernier sceau.
