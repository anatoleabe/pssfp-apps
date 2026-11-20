# Module 4 — Suspension d'un auditeur sur sa demande écrite

> Décisions du chef de projet du 20/11/2026. Complète le statut de l'auditeur (lot 2 §4), qui reste informatif pour les autres valeurs.

## 1. Besoin

Un auditeur demande par écrit à suspendre sa formation. L'administrateur le suspend pour l'**année académique en cours**, en joignant sa demande au moment de la suspension, puis en confirmant.

## 2. Décisions

| Question | Décision |
|---|---|
| Qui suspend et lève | L'administrateur et le super administrateur seuls (`scolarite.suspension`). |
| Accès à eCampus | Le compte eCampus est suspendu, et rouvert à la levée. |
| Argent de l'année | Ce qui est versé reste acquis. Le reste de l'année n'est plus réclamé. |
| Fin de la suspension | Levée manuelle, avec un motif. |

## 3. Suspendre (fiche auditeur › Dossier › « Suspendre pour 2026-2027 »)

L'action est visible seulement si l'auditeur est « Actif », que l'année en cours est réglée et qu'il y est inscrit. La fenêtre a deux étapes.

1. **Demande écrite** :
   - scan signé de l'auditeur (PDF, JPG ou PNG, 10 Mo au plus) ;
   - date de la lettre ;
   - date d'effet ;
   - motif indiqué dans la demande.
2. **Confirmation** : un récapitulatif rappelle l'auditeur, le matricule, l'année, la lettre et les effets. L'administrateur coche « J'ai vérifié que la demande est signée de l'auditeur, et je confirme la suspension ».

Le service `SuspensionsAuditeurs::suspendre()` refuse dans les cas suivants :
- l'appelant n'a pas la permission ;
- l'année en cours n'est pas réglée ;
- la demande manque, ne vient pas du formulaire, ou sert déjà ailleurs (une pièce ne sert qu'une fois) ;
- le motif est vide ;
- une date est dans le futur ;
- la confirmation n'est pas cochée ;
- l'auditeur n'est pas actif ;
- l'auditeur n'est pas inscrit dans l'année en cours.

Ce que fait la suspension :

- une ligne dans `suspensions_auditeurs` ;
- l'auditeur passe « Suspendu » (motif repris avec l'année et la date de la lettre) ;
- l'inscription de l'année reçoit `suspendue_at` ;
- une entrée au journal (`suspension`) ;
- le compte eCampus est suspendu.

## 4. Effets d'une année suspendue

- **Finances** : `SituationFinanciere::suspendue`. Les échéances sont ignorées, donc il n'y a jamais de retard ni d'échu impayé. La solvabilité ne compte pas l'année. La situation affichée est « Année suspendue ». Les montants (dû, versé, reste) restent affichés tels quels.
- **Listes et tableau de bord** :
  - la liste des auditeurs a un filtre « Année suspendue » ;
  - l'année est exclue des filtres « non soldée », « à jour » et « en retard », et de la liste des non-soldés ;
  - le tableau de bord ajoute la ligne « Années suspendues (reste non réclamé) » ;
  - l'année est exclue de l'exigible et des impayés par ancienneté.
- **Vérification du compte (D1)** : elle compare les imputations à l'ancien calcul et ignore la suspension (`enRetard(true)`).
- **eCampus** :
  - le compte est trouvé par son identifiant, et touché seulement s'il porte le matricule ou l'e-mail du dossier ;
  - sans compte, rien n'est à faire ;
  - en cas de panne ou de refus, la suspension reste faite dans le portail. Le constat est gardé, et l'action « Suspendre le compte eCampus » (ou « Rouvrir… ») refait le geste.

## 5. Lever (fiche › Dossier › « Lever la suspension »)

La levée demande un motif. L'auditeur redevient « Actif » et son compte eCampus est rouvert.

- **Même année** : `suspendue_at` est retiré, et le reste de l'année redevient dû.
- **Année suivante** : l'année suspendue reste non réclamée (`reprise_meme_annee` faux).

## 6. Garde-fous

- **« Changer le statut »** :
  - ne propose plus « Suspendu » (le service le refuse aussi) ;
  - est masqué pendant une suspension en cours.
- **Base de données** :
  - une seule suspension en cours par auditeur (index unique partiel) ;
  - le déclencheur `suspensions_auditeurs_immuables` refuse la suppression, toute modification de la demande et toute retouche d'une levée ;
  - des contraintes CHECK imposent une levée complète (date, auteur, motif).
- **Accès à la demande** : « Voir la demande de suspension » ouvre un lien signé de 30 minutes. Il est réservé aux titulaires de `scolarite.suspension` ou `scolarite.statut`, et chaque consultation est journalisée.

## 7. Données

- `suspensions_auditeurs` contient :
  - `uuid`, `auditeur_id`, `inscription_annuelle_id`, `annee_academique` ;
  - `demande_path`, `demande_du`, `motif`, `effet_le`, `suspendu_par`, `created_at` ;
  - `ecampus_suspendu_at`, `ecampus_rouvert_at`, `ecampus_detail` ;
  - `levee_at`, `levee_par`, `levee_motif`, `reprise_meme_annee`.
- `inscriptions_annuelles.suspendue_at`.
- Migrations :
  - `2026_12_12_100800_create_suspensions_auditeurs_table` ;
  - `2026_12_12_100900_grant_scolarite_suspension` (additive : admin et super_admin).
