# Module 4 — LMD, lot 13b-2 : primes d'animation en ligne des enseignants

**Date** : 2026-11-08. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI. **ADR** : 0011. Suite du lot 13b-1 (barème et états de paie).
**Sources** : dossier « PSSFP Système » : décision D25 du 29/10/2026 (prime par cours au prorata, preuves WhatsApp, retenue de 11 %) ; `sql/16_schema_enseignants_paie.sql` (tables `synthese_animation`, `activite_animation`, vue `v_synthese_animation`, partie (2) de `generer_etat_paie`) ; `reprise/27_import_whatsapp.py`.

## 1. Objectif

Chaque enseignant qui anime un cours en ligne reçoit une prime par cours : 100 000 FCFA (barème 2026-2027) multipliés par son taux de réalisation. La Scolarité établit une synthèse par enseignant et par cours : les activités attendues et celles réalisées. Les réalisations se saisissent, ou se comptent à partir de l'export WhatsApp du groupe du cours. Une synthèse validée est payée une seule fois, sur l'état du mois où le cours se termine.

## 2. Règles

1. **Synthèse** : une par enseignant, par cours (ECUE) et par année. Elle se crée à partir d'une programmation de l'enseignant sur ce cours (validée, notifiée ou acceptée). La période du cours reprend celle de la programmation et se corrige.
2. **Activités** : chacune appartient à une famille, contribution ou animation. Pour chacune, la Scolarité fixe la quantité attendue (au moins 1) et la quantité réalisée. Trois activités sont proposées à la création :
   - Documents partagés (contribution) ;
   - Jours d'animation (animation) ;
   - Messages aux auditeurs (animation).

   D'autres activités s'ajoutent : classe virtuelle, quiz, devoir corrigé, etc.
3. **Taux de réalisation** = somme des réalisations, chacune plafonnée à l'attendu, divisée par la somme des attendus. Les taux de contribution et d'animation sont affichés à part.
4. **Mode de calcul** : réglage `lmd.mode_prime_animation`, dans l'administration (`settings.manage`).
   - « prorata » (par défaut, D25) : la quantité payée est le taux, arrondi à 4 décimales.
   - « seuil » : la prime entière est payée si le taux atteint `lmd.seuil_prime_animation_pct` (100 % par défaut), rien sinon.
   - Une synthèse dont le taux donne une prime nulle n'est pas payée.
   - Le mode s'applique aux générations d'états suivantes, y compris pour les synthèses déjà validées. L'état reste vérifié par une autre personne que celle qui l'a préparé.
5. **Import de l'export WhatsApp** (port de `27_import_whatsapp.py`). On fournit le fichier exporté sans médias (.txt, ou .zip de l'iPhone) et le nom ou le numéro de l'enseignant tel qu'il apparaît dans le groupe.
   - Formats lus : Android (« 29/10/2026 15:26 - Nom: texte ») et iPhone (« [29/10/2026 15:26:03] Nom: texte »).
   - Les lignes de suite d'un message et les messages hors de la période sont ignorés. Les noms sont comparés sans accents, sans casse, sans espaces ni ponctuation (le « + » des numéros est gardé).
   - Ce qui est compté pour l'enseignant, sur la période :
     - **Documents partagés** : médias, fichiers, PDF et documents bureautiques ;
     - **Jours d'animation** : jours distincts où il a écrit ;
     - **Messages aux auditeurs** : messages texte et vocaux.
   - Seules les activités déjà présentes dans la synthèse sont mises à jour.
   - **Protection des données** : le fichier n'est pas conservé. Le contenu des messages, les messages des auditeurs et le nom saisi ne sont pas enregistrés. Seuls restent les comptages, le nom du fichier, son empreinte SHA-256 (justificatif), la date et l'auteur de l'import.
   - Le fichier est refusé dans trois cas : aucune ligne reconnue, une archive sans fichier texte, ou un fichier de plus de 10 Mo (une fois décompressé).
   - Un envoi temporaire resté sur le serveur (fenêtre fermée sans import) est effacé au bout d'une heure, par une tâche planifiée tous les quarts d'heure.
6. **Circuit** (droit `lmd.pointage_enseignants`, comme les pointages) :
   - brouillon → **validée**, ou **rejetée** (avec un motif) ;
   - une synthèse validée ou rejetée qui n'est pas payée se **rouvre** en brouillon (motif journalisé) ;
   - **séparation des tâches** (relecture de sécurité) : un enseignant qui a aussi le droit de pointage ne crée, ne modifie, n'importe, ne rouvre ni ne valide sa propre synthèse ;
   - la validation porte sur la version affichée (période et activités) : si la synthèse a changé entre-temps, elle est refusée ;
   - la programmation doit être encore active (validée, notifiée ou acceptée) à la validation et à la génération de l'état. Sinon, la prime n'est pas due et l'enseignant figure parmi les écartés ;
   - chaque correction des activités est journalisée (avant et après) ;
   - l'écran est réservé à la Scolarité (droit de pointage) : comme les états de paie, il porte des montants de paie et n'est pas ouvert en simple consultation du LMD ;
   - une synthèse portée sur un état de paie est figée.
7. **État de paie** (complète le lot 13b-1) :
   - la génération reprend les synthèses validées, non encore rattachées, dont la prime est due et dont le cours se termine dans le mois de l'état ;
   - une ligne « prime d'animation » par synthèse : quantité = coefficient, montant = barème `prime_animation` validé de l'année, retenue au taux du barème ; observation « Animation en ligne du cours : 64,3 % réalisé » ;
   - mêmes écartés que pour les heures (dossier incomplet ou inactif, barème non validé) : la synthèse reste libre ;
   - un rejet de l'état ou une régénération libère les synthèses ;
   - un rectificatif reprend aussi les synthèses restées libres du mois.
8. **Documents** : l'état imprimable ajoute une colonne « Primes d'animation » (montant brut), et le bordereau le nombre de primes.

## 3. Écrans

Une entrée **Primes d'animation** dans le groupe « Paie des enseignants ».

- **Liste** : enseignant, cours, période, taux, prime estimée, état (brouillon, validée, rejetée, payée).
- **Fiche** : période, groupe WhatsApp, activités (famille, attendu, réalisé), justificatif. Les actions disponibles sont :
  - importer l'export WhatsApp ;
  - valider ;
  - rejeter ;
  - rouvrir.
- Le réglage du mode de calcul est une action de la liste, réservée à l'administration.

## 4. Critères d'acceptation

1. L'essai de D25 porte sur un export fictif de 10 lignes : formats Android et iPhone, un message sur deux lignes, un message hors période. Il compte 2 documents, 3 jours et 4 messages. Avec 4 documents, 5 jours et 5 messages attendus, le taux est de 64,3 % : prime brute 64 290 FCFA, retenue 7 072, net 57 218.
2. État de novembre (CM à 17 500 FCFA, retenue 11 %) :
   - enseignant A, 6 h de CM et une prime à 100 % : 205 000 brut, 182 450 net ;
   - enseignant B, 3 h de CM et une prime à 90 % : 142 500 brut, 126 825 net ;
   - bordereau : 347 500 brut, 309 275 net.
3. Une synthèse payée ne se modifie pas et ne se rouvre pas. Un rejet de l'état la libère, et elle est reprise sur l'état suivant du mois.
4. Un enseignant ne valide pas sa propre synthèse. Une synthèse en brouillon n'est pas payée.
5. En mode seuil (100 %), une prime à 90 % n'est pas payée et une prime à 100 % est payée entière.
6. Le fichier importé n'est pas conservé. Seuls le nom du fichier et son empreinte figurent sur la synthèse.
