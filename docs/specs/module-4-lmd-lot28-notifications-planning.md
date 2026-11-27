# Module 4 — LMD, lot 28 : notifications des emplois du temps et des plannings d'examens

> Étape 7 de l'audit `docs/audits/lmd-gestion-academique-2026-11.md` (suite du lot 27).
> Décision de M. ABE du 24/11/2026 : « email, et SMS activable ».

## 1. Besoin

Un auditeur n'apprend aujourd'hui la publication de son emploi du temps, le report d'une séance ou la date de
ses examens que s'il ouvre son espace. La plateforme le prévient.

## 2. Règles

1. **Événements notifiés** :
   - publication d'un emploi du temps ;
   - report ou annulation d'une séance d'un emploi du temps **publié** ;
   - publication d'un planning d'examens ;
   - changement de date ou d'heures d'une épreuve d'un planning **publié**.
2. **Destinataires** : pour un emploi du temps, les auditeurs inscrits au parcours pour l'année du calendrier ;
   pour un planning d'examens, les candidats des épreuves concernées. Jamais les auditeurs en abandon ou exclus.
3. **Canaux** : e-mail, actif par défaut ; SMS, **inactif par défaut**, activable. Deux interrupteurs dans
   Paramètres (droit `settings.manage`), chaque changement journalisé.
4. **Texte** : court, sans donnée personnelle autre que le prénom, terminé par l'adresse de l'espace, sans
   ponctuation après l'adresse (incident du lien SMS, PR #114). Le SMS ne dépasse pas un message.
5. **Envoi en file d'attente**, après l'enregistrement : une panne d'e-mail ou de SMS n'annule ni la
   publication ni le report. Chaque message est tracé au journal des messages (cause `planning`) et réaffiché
   dans « Mes messages » de l'espace.
6. Rien ne part quand les deux canaux sont coupés, ni pour une séance d'un brouillon.

## 3. Livrables

- `App\Services\Lmd\NotificationsPlanning` (réglages, destinataires, textes) et la tâche
  `App\Jobs\NotifierPlanning`.
- Appels depuis `EmploisDuTemps` (publier, reporter, annuler) et `PlanningsExamens` (publier, enregistrer).
- Paramètres : « Prévenir les auditeurs par e-mail » et « … par SMS ».
- Aucune migration, aucun droit nouveau.

## 4. Hors périmètre

Notification des enseignants et des surveillants ; rappel la veille d'une épreuve ; états des requêtes.

## 5. Tests

`tests/Feature/Lmd/NotificationsPlanningTest.php` : tâche mise en file à la publication et au report, avec les
bons destinataires ; rien pour un brouillon ni canaux coupés ; SMS seulement s'il est activé ; réglage réservé
à `settings.manage` et journalisé ; message tracé avec la cause `planning`.
