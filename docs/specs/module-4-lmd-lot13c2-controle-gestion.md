# Module 4 — LMD, lot 13c-2 : contrôle de gestion (coûts, tableau de bord mensuel, dossiers en retard)

**Date** : 2026-11-10. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 10/11 : délai des requêtes réglable, 30 jours par défaut ; budget reporté ; accès réservé à la direction et au service financier). **ADR** : 0011. Suite du lot 13c-1.
**Sources** : dossier « PSSFP Système » : décision D38 du 07/11/2026, constat AUD-024 (aucun indicateur de coût) ; `sql/25_schema_controle_gestion.sql` (vues `v_realise_paie`, `v_cout_ue`, `v_cout_parcours`, `v_tableau_bord_mensuel`, `v_dossiers_en_retard`).

## 1. Objectif

Donner à la direction et au service financier :
- ce que coûte chaque UE, chaque parcours et chaque auditeur en paie des enseignants ;
- l'activité du mois sur douze mois ;
- les dossiers qui ont dépassé leur délai.

## 2. Règles

1. **Réalisé** : les lignes des états de paie **transmis, pris en charge ou payés**. Les brouillons, les états vérifiés ou visés non transmis et les rejets ne comptent pas (`v_realise_paie`). Les montants sont des bruts en FCFA.
2. **Coût par UE** (année académique choisie), à partir des lignes rattachées à un cours (ECUE) de l'UE :
   - heures de CM payées et leur coût ;
   - coût des primes d'animation ;
   - coût total (toutes natures) ;
   - inscrits (inscriptions pédagogiques « inscrit » à l'UE) ;
   - coût par inscrit (total ÷ inscrits) ;
   - coût horaire complet (total ÷ heures de CM payées).

   Une division par zéro donne « — ».
3. **Coût par parcours** : somme des UE de ses maquettes de l'année. Les auditeurs sont ceux qui ont au moins une UE de ce parcours (inscription pédagogique « inscrit »), et le coût par auditeur vaut total ÷ auditeurs. Le parcours d'un auditeur se déduit de son inscription pédagogique : l'inscription annuelle ne porte pas de parcours.
4. **Hors UE** : les surveillances ne sont rattachées à aucun cours. Elles figurent dans le total de l'année, pas dans les coûts par UE ni par parcours.
5. **Tableau de bord mensuel**, sur les 12 derniers mois :
   - heures validées : minutes payables des pointages validés, au mois de la séance ;
   - heures de CM payées, paie brute, retenues et enseignants payés : réalisé, au mois de fin de la période de l'état ;
   - requêtes déposées (date de dépôt) et clôturées (événement « clôturée ») ;
   - délibérations publiées.
6. **Dossiers en retard** :
   - **requête** ouverte (statuts de `Requete::OUVERTS`) déposée depuis plus de `lmd.delai_requetes_jours` jours. C'est un réglage, de 30 jours par défaut ; il se modifie avec `settings.manage`, en attendant le règlement des examens (T-01) ;
   - **état de paie** ni payé ni rejeté, 30 jours après la fin de sa période ;
   - pour chacun : numéro, statut, date d'ouverture, échéance, jours de retard, responsable s'il est connu.
7. **Accès** : nouvelle permission `lmd.controle_gestion`, accordée par migration additive à `super_admin`, `admin` et `service_financier`, puis attribuable depuis l'écran Rôles.
8. **Écran « Contrôle de gestion »** (groupe « Enseignants et paie ») : quatre sections (tableau mensuel, coûts par parcours, coûts par UE, dossiers en retard) et un choix de l'année académique. Sa pastille donne le nombre de dossiers en retard ; elle remonte dans l'encart « À traiter ».
9. **Lecture seule** : rien n'est enregistré. Le seul réglage, le délai des requêtes, est journalisé dans le journal `parametres`.

## 3. Tests

1. Le réalisé exclut les brouillons, les états vérifiés et les rejets.
2. Coûts par UE et par parcours : heures, coûts, inscrits, coût par inscrit, coût horaire, « — » pour une division par zéro ; surveillances hors UE.
3. Tableau mensuel : mois de la séance pour les heures validées, mois de fin de période pour la paie, requêtes déposées et clôturées.
4. Dossiers en retard : requête ouverte au-delà du délai (réglage respecté), requête clôturée exclue, état de paie non payé 30 jours après la période.
5. Accès : `lmd.controle_gestion` ouvre l'écran, la Scolarité ne le voit pas ; seul `settings.manage` règle le délai.

## 4. Hors périmètre

- **Budget** (lignes, réalisé par ligne, taux d'exécution) : reporté par décision du 10/11, en attente de l'acte budgétaire.
- Demandes d'autorisation et anomalies du système (lot 14).
