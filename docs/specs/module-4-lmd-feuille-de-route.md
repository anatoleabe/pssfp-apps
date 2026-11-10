# Module 4 — LMD complet : feuille de route

**Date** : 2026-11-07. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0010, 0011.
**Source** : dossier « PSSFP Système » du 07/11/2026 (`NewACMA/PSSFP_Systeme_package_2026-11-07`) : cahier des charges, décisions D1 à D38, registre des décisions à valider V-01 à V-21.

## 1. Objectif

Remplacer ACMA par un LMD complet dans la plateforme, utilisable par chaque acteur depuis un navigateur ou un téléphone :
- l'équipe de scolarité ;
- les enseignants, les jurys, le contrôle interne et la direction ;
- l'auditeur, depuis son espace.

Les lots 1 à 3 (paiements, fiche auditeur, espace) sont en production depuis le 6 novembre 2026.

## 2. Lots

Chaque lot a sa spec, sa branche, ses tests, et se déploie seul.

| Lot | Contenu | Décisions | État |
|---|---|---|---|
| 4 | **Référentiel pédagogique** : parcours, maquettes versionnées (UE, ECUE, crédits, volumes, pondérations), règles d'évaluation de l'année, inscription pédagogique, rubrique « Mon parcours » de l'espace | D1, D2, D10, D14 à D16, D22 (maquettes) | PR #72 |
| 5 | **Notes** : saisie par ECUE et par étapes (CC, assiduité et TP, examen), verrouillage et réouverture motivée, historique en ajout seul, import Excel, moyennes, éligibilité au rattrapage | D10, D11, D16 | PR #73 |
| 6 | **Délibérations** : jurys, préalables, scénarios et mesures du jury, séance et quorum, arrêt (résultats figés, PV numéroté et scellé), signatures, publication, rectification, PV en PDF, liste d'affichage, correspondances d'UE et acquis des redoublants | D4, D5, D17, D20, D35, D37 | PR (lot 6) ; correspondances d'UE et acquis des redoublants avec la reprise d'ACMA (lot 15) |
| 7 | **Résultats et documents** : « Mes résultats », relevé de notes, attestation de réussite, certificat de scolarité ; documents numérotés, vérifiables par QR code, révocables | D9, D26 (documents), D37 | PR #75 |
| 8 | **Requêtes** : dépôt dans la fenêtre, accusé de réception, cotation, instruction, jury des requêtes, validation définitive, application aux notes, demandes de documents | D26 à D28 | PR #76 |
| 9 | **Anonymat des copies** | D36 | PR #77 |
| 10 | **Assiduité** : séances, pointage présentiel et à distance (export Zoom), justifications, note d'assiduité | D12, D13 | PR #78 |
| 11 | **Mémoires** et attestation d'autorisation de dépôt | D32 à D34 | PR #79 |
| 12a | **Calendrier académique** : circuit de signature, exécution, réajustement, contrôles, Gantt | D18 à D21 | PR #80 |
| 12b | **Actes** produits à partir du calendrier (notes de service, communiqués, PV, listes) | D18, D19 | PR #81 |
| 12c | **Emplois du temps** : salles, plages, séances programmées, conflits, volume horaire, espace auditeur | D21 | PR #82 |
| 12d | **Programmation des enseignants** : quotas horaires par cours et par période, charge, circuit de notification | D22 | PR #83 |
| 13a | **Dossier de paie et pointage des enseignants** : statut, NIU et compte chiffrés (service financier), émargement et cahier de textes, validation, heures effectives, contrôles | D23, D24 | PR #84, déployée le 08/11 |
| 13b-1 | **États de paie** : barème, état mensuel des heures et des surveillances, circuit jusqu'au virement, PDF de l'état et du bordereau | D23 à D25 | PR #86, fusionnée le 08/11 |
| 13b-2 | **Primes d'animation en ligne** : synthèses, import de l'export WhatsApp, ajout à l'état du mois | D25 | PR #87, fusionnée le 09/11 |
| 13c | **Rapprochement et contrôle de gestion** : avis de virement, budget, coûts, tableau de bord | D38 | À faire |
| 14 | **Supervision**, messagerie interne, demandes d'autorisation | D30, D31 | À faire |
| 15 | **Archivage numérique** et reprise de l'historique d'ACMA | D29 ; plan de reprise | À faire |

Avant les lots 13c à 15 : chantier « Socle » (navigation, charte de l'admin, rôles, journaux, préparation du hors-ligne), cf. `docs/plans/2026-11-09-socle-plateforme-objectifs.md`.

## 3. Règles retenues

| Règle | Valeur | Origine |
|---|---|---|
| Note d'une UE | contrôle continu 30 % + assiduité (note de TP) 15 % + examen 55 % ; pondération modifiable par UE | Chef USI, 06/11 ; D12 (TP = assiduité) |
| Note d'UE à partir des ECUE | moyenne des ECUE pondérée par leurs crédits | **proposé** : règle non écrite (D22, point 11) |
| UE acquise | moyenne d'UE au moins 12/20, sans note éliminatoire ; capitalisée définitivement | D1 |
| Note éliminatoire | examen inférieur à 7/20, à partir de 2026-2027 ; porte sur l'examen de l'UE | D16 ; portée **proposée** |
| Compensation | aucune entre UE | D1 ; Chef USI, 06/11 |
| Rattrapage | **la note de rattrapage remplace la moyenne de l'UE** (CC, assiduité et examen) | Chef USI, 06/11, **confirmé le 07/11** : prime sur D10 (qui ne remplaçait que l'examen). Reste un paramètre de l'année |
| Décision annuelle | M1 : 60 crédits = AD, sinon AJ. M2 : 44 crédits écrits = AS, 60 avec le mémoire = AD, moins de 44 = AJ | D2, D4 |
| Mémoire | UE distincte de 16 crédits (partie « soutenance ») ; le reste du S10 forme une UE écrite de 14 crédits | D2 ; proposition D22 (point 2) |
| MGP, mentions | MGP sur 4 par les crédits acquis (classement) ; mention du diplôme par la moyenne du cycle : 12, 14, 16, 18 (félicitations proposées) | D14, D15 |
| Maquette de référence | celle du parcours, de l'année et du niveau de l'inscription | D35 |
| Jury | quorum 50 %, président présent ; signatures de tous les présents ; relèvement 0,5 point au plus, repêchage 1 point au plus, note éliminatoire opposable | V-02 à V-07 (proposés) |

## 4. Écarts avec les lots 1 à 3 (à arbitrer, sans urgence)

- **Compte de l'auditeur** (D7) : le dossier calcule le reste à payer sur tout le parcours (M1 et M2). La plateforme le calcule par année.
- **Prises en charge par une administration** (D8) : non gérées. Le payeur « employeur » existe, mais il n'y a pas de créance sur l'administration.
- **Reçus par objet de frais** (D7) : non gérés.

## 5. Questions ouvertes

1. Note d'UE à partir des ECUE : moyenne pondérée par les crédits (proposé), ou validation ECUE par ECUE ?
2. Note éliminatoire : sur l'examen de l'UE (proposé) ou sur l'examen de chaque ECUE ?
3. Auditeurs P13 non admis en M2 (100 dossiers) : réinscription en M1 2026-2027 avec leurs UE capitalisées ? Leurs notes de M1 sont dans ACMA : il faut la copie de la base pour les reprendre.
4. Textes à fournir (T-01 à T-06 du registre) : règlement des études et des examens, textes LMD, taux de paie, procédure d'anonymat.
