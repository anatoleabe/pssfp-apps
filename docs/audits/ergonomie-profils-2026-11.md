# Ergonomie par profil — état des lieux du 25/11/2026

Demande du Chef de projet du 25/11/2026 : « tout revoir pour que ce soit un vrai système de gestion administratif pensé
pour les utilisateurs divers : staff admin, auditeur, enseignant, points focaux ». Ordre validé le même jour : état des
lieux, puis un lot par sujet. La fiche de l'auditeur et la recherche unique sont faites (lot 32, en production le 25/11).

Méthode : accueil de l'administration ouvert sous chaque rôle du seeder (test provisoire, données fictives), menu extrait
du rendu, captures à 1440 px ; lecture du code du portail pour l'espace de l'auditeur.

## 1. Taille du menu par profil

| Profil | Entrées | Rubriques | Remarque |
|---|---|---|---|
| Super administrateur | 71 | 11 | tout |
| Administrateur | 69 | 11 | tout, sauf deux écrans |
| Responsable de scolarité (compte réel : rédacteur + réception + responsable) | 59 | 10 | travail courant noyé dans le paramétrage |
| Responsable de scolarité (rôle seul) | 54 | 8 | idem |
| Agent de scolarité | 41 | 6 | Planification : 13 entrées |
| Service financier | 20 | 4 | deux « Tableau de bord » |
| Enseignant | 10 | 4 | ses écrans personnels sont répartis sur trois rubriques |
| Point focal | 6 | 3 | « Ma classe » sous Planification |
| Réception, comité d'admission, bibliothécaire, rédacteur, opérateur de saisie, chef de structure | 5 à 6 | 2 | correct |

## 2. Constats

1. **Le travail courant est mêlé au paramétrage.** L'agent de scolarité voit 13 entrées en Planification, dont six de
   réglage qui servent une fois par an (salles, plages horaires, jours non ouvrés, modèles d'actes, quotas horaires,
   calendrier) ; le Référentiel pédagogique (parcours, maquettes, règles d'évaluation, attributions, points focaux) est
   aussi du paramétrage. Les barèmes, rubriques et étiquettes de même.
2. **L'accueil est presque vide.** Un bloc « Bonjour » et, seulement s'il y a quelque chose, les compteurs « À traiter ».
   Aucun raccourci vers les gestes du jour (encaisser, confirmer un versement, retrouver un auditeur, saisir des notes).
3. **Libellés incohérents** : « Prises En Charge », « Registre Des Documents », « Anciennes Adresses » (majuscules à
   chaque mot) ; deux entrées « Tableau de bord » pour le service financier ; « Tableau de bord » placé après Messagerie
   et Demandes.
4. **Enseignant et point focal** : leurs écrans personnels (Mes cours, Mes heures, Mes émargements, Mes programmations,
   Mes indisponibilités, Saisie des notes ; Ma classe) sont dispersés dans des rubriques pensées pour la scolarité.
5. **Espace de l'auditeur (portail)** : 11 rubriques dans une barre qui défile de côté ; sur téléphone, la plupart sont
   hors de l'écran. Plusieurs se recoupent (Parcours, Emploi du temps, Résultats ; Documents, Accès).
6. **Fiches** : la fiche de l'auditeur est refaite (lot 32). Celle d'un dossier de candidature (six gestes) et d'une
   demande restent lisibles ; à aligner sur le même modèle quand on y touchera.

## 3. Lots proposés

| Lot | Contenu | Pour qui |
|---|---|---|
| 33 | **Menu par rôle** : le paramétrage dans une rubrique « Réglages » repliée en bas ; ordre des rubriques selon le métier ; libellés harmonisés ; « Accueil » en tête | tout le personnel |
| 34 | **Accueil par rôle** : « À traiter » toujours visible (avec « rien à traiter »), raccourcis des gestes fréquents du rôle, recherche mise en avant | tout le personnel |
| 35 | **Espace enseignant et point focal** : une rubrique « Mon espace », un accueil dédié (cours de la semaine, émargements à faire, notes à saisir ; ma classe) | enseignants, points focaux |
| 36 | **Espace de l'auditeur** : 11 rubriques ramenées à 6, barre en bas d'écran sur téléphone, accueil « ce que je dois faire » | auditeurs |
| 37 | **Fiches** : même modèle (bandeau, onglets, un bouton et un menu) pour le dossier de candidature et la demande | admissions, décideurs |
| 38 | **Vérification** : captures à 390 et 1440 px et contrôle d'accessibilité (WCAG 2.1 AA) pour chaque profil | tous |

Chaque lot : spec, tests, captures, PR ; fusion et déploiement sur accord.
