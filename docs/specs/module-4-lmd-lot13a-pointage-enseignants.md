# Module 4 — LMD, lot 13a : dossier de paie et pointage des cours enseignés

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Feuille de route : `module-4-lmd-feuille-de-route.md`. Suite du lot 12d (programmation des enseignants).
**Sources** : dossier « PSSFP Système » : décisions D23 et D24 du 29/10/2026 ; `sql/16_schema_enseignants_paie.sql` (sections 1, 3, 4 et 6 : dossier de l'enseignant, pointage, heures effectives, contrôles).

Le lot 13 (enseignants et paie) est découpé en trois :

- **13a** (ce lot) : dossier de paie de l'enseignant, pointage des cours enseignés, heures effectives, contrôles ;
- **13b** : barème, primes d'animation en ligne, états de paie mensuels et leur circuit ;
- **13c** : rapprochement des virements, budget, coûts et tableau de bord (D38).

## 1. Objectif

Chaque séance de l'emploi du temps est pointée par l'enseignant qui l'a faite, avec son heure d'arrivée, son heure de départ et le contenu enseigné (cahier de textes). La Scolarité valide ou rejette chaque pointage. Les heures validées sont les heures effectives : c'est la base de l'état de paie du lot 13b.

Le dossier de paie de l'enseignant (statut, NIU, compte bancaire) est tenu par le service financier. La Scolarité voit seulement si le dossier est complet.

## 2. Règles

1. **Dossier de paie** : un par enseignant (compte qui a le rôle d'enseignant). Il porte :
   - le statut : permanent, vacataire, missionnaire ou professionnel ;
   - la catégorie, facultative : professeur, maître de conférences, chargé de cours, assistant, docteur, expert ;
   - le service dû annuel d'un permanent, en heures (au-delà : heures complémentaires, lot 13b) ;
   - le NIU, la banque et le numéro de compte. Le paiement se fait par virement uniquement (D24).

   Le NIU et le numéro de compte sont **chiffrés** en base. Ils ne figurent pas dans le journal d'activité.

   Un dossier est **complet** quand il a un NIU et un numéro de compte. Un enseignant au dossier incomplet ne sera pas payé (lot 13b) : il est signalé.
2. **Pointage d'une séance** :
   - il porte sur une séance d'un emploi du temps **publié**, ni annulée ni reportée, dont la date est passée ou du jour. Une séance d'évaluation ne se pointe pas comme un cours (la surveillance relève du barème, lot 13b) ;
   - il est fait par l'enseignant qui a fait cours, une seule fois par séance et par enseignant ;
   - il donne l'heure d'arrivée, l'heure de départ et la nature (CM, TD, TP). L'enseignant pointe la nature de la séance ; seule la Scolarité la change ;
   - il donne le **contenu enseigné** (cahier de textes), obligatoire pour valider ;
   - il indique le mode : signature dans le back-office (l'enseignant lui-même, seulement), badge, QR code, Zoom ou saisie par la Scolarité. Un pointage modifié par la Scolarité passe en « saisie », à son nom ;
   - la confirmation du délégué de la classe est facultative, cochée par la Scolarité.

   **Remplacement** : un enseignant qui fait cours à la place de l'enseignant de la séance pointe pour lui-même. Le pointage garde la trace de l'enseignant remplacé.

   Un enseignant ne pointe que ses séances, ou une séance d'un cours sur lequel il a une programmation validée, notifiée ou acceptée **pour la période de la séance** (remplacement), et seulement si aucun autre enseignant ne l'a déjà pointée. La Scolarité peut pointer pour tout enseignant ; un second pointage sur une séance est alors signalé.
3. **Validation** : la Scolarité valide le pointage, ou le rejette avec un motif.
   - Valider exige l'arrivée, le départ et le contenu enseigné.
   - **Une séance n'a qu'un pointage validé** : pas de co-animation payée deux fois.
   - **Séparation des tâches** : personne ne valide ses propres heures d'enseignement. L'agent valide les pointages qu'il a saisis (réponse de la Scolarité du 08/11/2026).
   - La validation marque la séance « tenue » : elle alimente l'avancement de la programmation et du Gantt (lot 12d).
   - Un pointage à valider ou rejeté se modifie ; un pointage validé qui est modifié repasse à valider, et la séance redevient « prévue » si plus rien ne la marque tenue.
   - Un pointage **payé** (rattaché à une ligne d'état de paie, lot 13b) ne se modifie plus ; il se régularise par un état rectificatif.
4. **Heures effectives** : durée effective (départ moins arrivée), plafonnée à la durée prévue de la séance, arrondie au quart d'heure inférieur. Le pas d'arrondi est un paramètre (`lmd.arrondi_paie_minutes`, 15 par défaut). Les heures sont figées à la validation.
5. **Contrôles** (non bloquants, affichés au suivi), pour une période de cours :
   1. séance passée sans pointage de l'enseignant ;
   2. auditeurs pointés présents, sans pointage de l'enseignant (ou pointage rejeté) ;
   3. pointage à valider depuis plus de 7 jours (paramètre `lmd.delai_validation_pointage_jours`) ;
   4. cours fait par un enseignant sans programmation active sur ce cours ;
   5. pointages d'un même enseignant qui se chevauchent ;
   5 bis. séance pointée par plusieurs enseignants ;
   6. heures validées au-delà des heures allouées à l'enseignant, par nature (un TD ne compense pas un CM) ;
   7. enseignant avec des heures validées et un dossier de paie absent ou incomplet.
6. **Permissions** :
   - `lmd.pointage_enseignants` (gestion) : saisir, modifier, valider et rejeter les pointages de tous les enseignants ; tenir le statut des dossiers de paie ; suivre les heures. Rôles : agent et responsable de la scolarité, admin, super_admin ;
   - `lmd.pointage_emarger` (autonome) : pointer ses séances et suivre ses pointages. Rôles : enseignant, super_admin ;
   - `lmd.dossier_paie` (sensible) : voir et saisir le NIU, la banque et le compte. Rôles : service financier (nouveau rôle `service_financier`), super_admin.

   Le rôle `service_financier` (« Service administratif et financier ») reçoit `lmd.dossier_paie`. Le lot 13b lui ajoutera la prise en charge des états de paie.

## 3. Écrans

- **Administration**, Parcours LMD :
  - **Pointages des enseignants** :
    - liste de tous les pointages, avec des filtres (période, enseignant, état) ;
    - actions : saisir un pointage, modifier, valider (aussi en lot), rejeter avec un motif ;
    - pour un enseignant, la rubrique devient « Mes émargements » : il n'y voit que ses pointages, et « Émarger un cours » lui propose ses séances à pointer.
  - **Dossiers de paie** :
    - liste des enseignants, avec leur statut, leur catégorie, leur service dû et l'état du dossier (complet ou non) ;
    - le NIU, la banque et le compte ne s'affichent et ne se saisissent qu'avec `lmd.dossier_paie`.
  - **Suivi des heures enseignées** : choix d'une période, puis :
    - les sept contrôles ;
    - le bilan par enseignant et par cours, y compris les enseignants programmés qui n'ont rien pointé : heures allouées, pointées, validées, à valider, au-delà de l'allocation.

## 4. Critères d'acceptation

1. Un enseignant pointe sa séance passée (arrivée, départ, contenu) ; il ne peut pas pointer une séance future, ni la séance d'un autre cours sans programmation, ni pointer deux fois la même séance.
2. Arrivée 20 min en retard sur une séance de 3 h : 2,5 h effectives (2 h 40 arrondies au quart d'heure inférieur). Un départ tardif ne dépasse pas la durée prévue.
3. Valider sans contenu enseigné est refusé ; rejeter sans motif est refusé. La validation passe la séance à « tenue ».
4. Un remplaçant programmé (programmation validée) sur le cours pointe la séance : le pointage trace l'enseignant remplacé. Une programmation seulement proposée ne suffit pas. Un remplaçant non programmé (saisi par la Scolarité) produit le contrôle 4. Une séance déjà validée pour un enseignant ne se valide pas pour un second ; un compte à la fois enseignant et agent ne valide pas ses propres heures.
5. Un pointage payé ne se modifie plus.
6. Les contrôles se déclenchent sur leurs cas : séance sans pointage, auditeurs sans enseignant, pointage en attente, chevauchement, heures validées au-delà de l'allocation, dossier incomplet.
7. Le NIU et le compte sont chiffrés en base et absents du journal d'activité. Un agent de la scolarité ne les voit pas ; le service financier les voit et les saisit.
