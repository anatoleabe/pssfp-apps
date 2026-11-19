# Module 4 — Scolarité : encaissement par le service financier, confirmation par la scolarité

**Date** : 2026-11-15. **Décideur** : M. ABE ETOUMOU Anatole, Chef USI (décisions du 15/11/2026, prises après celles de la bascule ACMA et de l'avis à l'auditeur, lot 4 §5, qui restent valables).

Cette spec modifie le lot 4 (reçus), D1 (compte de l'auditeur), A1 (accès anticipé), A2 (prises en charge) et C (listes et états). Livraison en deux PR empilées, déployées ensemble :

- **PR 1** — encaissement par le service financier : un seul geste « Enregistrer et émettre le reçu », séparation des tâches levée sur le reçu, saisie retirée à la scolarité. Elle contient aussi la bascule ACMA et l'avis à l'auditeur (lot 4 §5).
- **PR 2** — confirmation par la scolarité : confirmation de chaque versement, effet « à jour », écran « Versements à confirmer », relance, contrôle interne et reprise des versements existants.

## 1. Nouveau circuit

1. L'auditeur paie à la banque (CREMINCAM). Il reçoit un reçu bancaire.
2. Le **service financier** enregistre le versement (référence du bordereau) **et émet aussitôt le reçu officiel**, en un seul geste. L'auditeur est prévenu par SMS et e-mail (lot 4 §5).
3. L'auditeur présente son reçu bancaire original à la **scolarité**. Un agent vérifie le reçu, scanne le bordereau bancaire et **confirme** le versement.
4. Un auditeur n'est « à jour » qu'avec des versements confirmés. L'argent compte dans son compte et sur le reçu dès l'enregistrement, mais l'accès à eCampus et les listes « à jour » attendent la confirmation.

## 2. Décisions

### 2.1 Encaissement (PR 1)

1. **Un geste.** « Enregistrer et émettre le reçu » écrit le versement et son reçu dans la même transaction : un versement ainsi saisi n'existe jamais sans son reçu. Le geste exige `scolarite.versement_saisir` et `scolarite.recu_emettre`.
2. **Séparation levée.** La règle « celui qui a saisi le versement n'en émet pas le reçu » (lot 4 §2.1, `raisonRefus()`, `enAttente()`) est supprimée. Le contrôle passe à la scolarité, par la confirmation.
3. **Seul le service financier enregistre des versements.** `scolarite.versement_saisir` est retirée aux rôles `scolarite_agent` et `scolarite_responsable`, par une migration additive (jamais `RolePermissionSeeder` en prod). `ModulePermissionMap` et `RolePermissionSeeder` suivent.
4. Le **règlement réparti d'un organisme** (A2) dépend de la même permission : il passe au service financier.
5. `scolarite.versement_annuler` ne change pas : le responsable de scolarité annule, le service financier n'annule pas sa propre saisie.

### 2.2 Confirmation (PR 2)

1. **Permission.** Nouvelle permission sensible `scolarite.versement_confirmer`. Elle va à `scolarite_agent`, `scolarite_responsable` et `admin`. `super_admin` la reçoit avec toutes les actions sensibles. Le service financier ne la reçoit pas.
2. **Geste.** Confirmer un versement exige le **scan du bordereau bancaire**, déposé par la scolarité, et la case « J'atteste avoir vérifié le reçu bancaire original : le montant a bien été versé ». *Remplacé par le §10 (décision du 19/11/2026) : la scolarité confirme sur le scan du service financier.*
3. **Contrôle croisé.** Personne ne confirme un versement qu'il a lui-même saisi.
4. **Définitive.** La confirmation est tracée (qui, quand, pièce) et écrite au journal `scolarite`. Elle se fait une seule fois. Une erreur se corrige par l'annulation du versement.
5. **Effet « à jour ».**
   - L'accès à eCampus (`AccesEcampus::accesOuvert`, `inscriptionSoldee`) ne compte que les montants confirmés. Il en va de même pour tout ce qui en découle : codes remettables, lien et lettre d'accès, export Word des lettres, régularisation des déblocages A1 et A2, SMS « accès disponibles ».
   - Les listes « à jour » (lot C : filtres, listes de situation, relances des impayés) ne comptent aussi que les montants confirmés.
   - Partout, le versé s'affiche avec « dont X à confirmer ».
   - Le déblocage anticipé (A1) reste la voie d'exception.
6. **Reçu.** Le reçu porte « Versement vérifié par la scolarité : <nom>, le <date> » quand la confirmation existe au moment de l'émission (§5, point 6).
7. **Relance.** Un versement non confirmé 7 jours après son enregistrement déclenche un SMS et un e-mail à l'auditeur, ainsi qu'un contrôle interne (catalogue du lot 14a, relances graduées du lot 14b-2). La tâche tourne chaque jour à 08:00, heure de Yaoundé. L'auditeur est relancé au plus une fois tous les 7 jours.
8. **Écran.** Un écran « Versements à confirmer » (groupe Scolarité) donne des filtres, l'action de confirmation et une pastille de menu.
9. **Reprise.** Les versements existants sont repris comme confirmés par leur auteur (`saisi_par`), à leur date d'enregistrement, avec leur scan s'il existe. Aucun auditeur ne perd son accès au déploiement : un test vérifie que l'état d'accès est le même avant et après.

## 3. Données

### 3.1 PR 1

Aucune table. Une migration de permissions, `2026_12_04_100400_revoke_scolarite_versement_saisir` :

- elle passe **après** la reprise des confirmations et l'octroi de la confirmation (relecture du 15/11) : un échec de la reprise ne laisse pas la scolarité sans saisie ;
- elle retire `scolarite.versement_saisir` à `scolarite_agent`, `scolarite_responsable` et `admin` ; `super_admin` garde tout ;
- elle s'assure que `service_financier` porte `scolarite.versement_saisir` et `scolarite.recu_emettre` ;
- elle met à jour les descriptions de l'agent de scolarité et du service financier, si elles n'ont pas été modifiées depuis l'écran Rôles ;
- `down()` rend la permission aux trois rôles.

### 3.2 PR 2 — `confirmations_versements` (nouvelle, en ajout seul)

| Colonne | Type | Règle |
|---|---|---|
| `id` | bigserial | interne |
| `versement_id` | FK `versements`, **unique** | une confirmation par versement, suppression refusée |
| `confirme_par` | FK `users`, nullable | nul seulement pour une reprise sans auteur |
| `confirme_at` | timestamptz | |
| `justificatif_path` | varchar, nullable | scan du bordereau ; nul seulement pour une reprise sans scan |
| `attestation` | boolean | vraie pour toute confirmation faite à l'écran |
| `reprise` | boolean, défaut faux | ligne posée par la migration de reprise |
| `created_at` | timestamptz | |

- Une contrainte `CHECK (reprise OR (confirme_par IS NOT NULL AND justificatif_path IS NOT NULL AND attestation))` impose ces règles.
- Le déclencheur `confirmations_versements_immuables` refuse UPDATE et DELETE, comme pour les reçus.

**Pourquoi une table, et pas des colonnes sur `versements`.** Le déclencheur de D1 (`versements_immuables`) n'autorise que l'annulation et le bordereau. Ajouter la confirmation sur la ligne obligerait à élargir ce déclencheur, donc à affaiblir l'immuabilité de l'argent. Une table en ajout seul garde le déclencheur intact et rend la confirmation définitive par construction. Elle laisse aussi l'historique lisible : la confirmation d'un versement annulé ensuite reste visible.

### 3.3 PR 2 — `recus_versements` (modifiée)

- Deux colonnes nullables, figées à l'émission : `verifie_par_nom` et `verifie_le`.
- Le déclencheur d'immuabilité des reçus n'est pas touché : les colonnes sont remplies à l'insertion.

### 3.4 PR 2 — autres changements

- Journal des envois : nouveau motif `confirmation` (relance), réaffiché dans l'espace de l'auditeur.
- Contrôle interne : nouveau contrôle `VER_NON_CONFIRMES`.

## 4. Effets sur les lots existants

| Lot | Effet |
|---|---|
| D1 (compte) | Inchangé. Les imputations sont posées à la saisie et le reçu compte l'argent dès l'enregistrement. `CompteAuditeur`, le formulaire de saisie (reste, avance annoncée) et `CPT_COHERENCE` lisent tout l'argent. |
| Lot 4 (reçus) | Émission au moment de la saisie ; séparation levée ; mention de vérification figée à l'émission (§3.3). |
| A1 (accès anticipé) | Régularisé à la confirmation qui solde les droits, plus à la saisie. La tâche quotidienne de rattrapage suit la même règle. |
| A2 (prises en charge) | Le règlement est saisi par le service financier. Ses parts se confirment comme tout versement (§5, point 4). « Réglé » reste l'argent reçu de l'organisme. |
| C (listes, états) | Les filtres et les états « soldé », « à jour », « en retard » et « inscription soldée » ne comptent que les montants confirmés. Les colonnes de montants affichent le versé, avec la part à confirmer. |
| Accès | Les codes, la lettre d'accès, l'export Word des lettres et le SMS « accès disponibles » partent à la confirmation qui solde les droits d'inscription. |

## 5. Points tranchés en réalisation

### PR 1

1. **Atomicité du geste.** « Enregistrer et émettre le reçu » fait la saisie et l'émission sous le même verrou de l'auditeur et dans la même transaction. Si l'émission est refusée (par exemple, date de mise en service non réglée), rien n'est enregistré et l'agent voit le motif. L'avis à l'auditeur et la notification des accès partent après la validation.
2. **Libellé du geste.** Avec `scolarite.recu_emettre`, le bouton de la fiche devient « Enregistrer et émettre le reçu ». Sans elle, il reste « Enregistrer un versement » (administrateurs). L'action « Émettre le reçu » de la ligne reste disponible pour un versement sans reçu en vigueur : reçu annulé puis réémis, part de règlement, saisie d'un administrateur, reçu demandé pour un versement antérieur à la bascule.
3. **Rôles d'administration (relecture du 15/11).** `admin` perd la saisie, comme décidé (« le service financier seul ») : sans `recu_emettre`, il ne pouvait plus encaisser un versement, seulement un règlement d'organisme, ce qui était incohérent. `super_admin` garde tout. Le module Scolarité de `admin` passe au niveau « Lecture ».
4. **Niveau du module.** `scolarite.versement_saisir` reste la permission de gestion du module Scolarité. Les deux rôles de scolarité passent au niveau « Lecture » et gardent leurs actions sensibles. Le service financier reste au niveau « Gestion ».
5. **Gestes de scolarité qui dépendaient de la saisie.**
   - « Renvoyer le SMS et l'e-mail » des accès et « Envoyer un nouveau lien » vers la lettre d'accès passent sous `scolarite.lettre_acces` (agent, responsable, administrateurs).
   - « Joindre le bordereau » reste à la saisie (service financier).
   - « Créer l'inscription » reste à la saisie dans la PR 1. La PR 2 l'ouvre aussi à la confirmation (point 5 de la PR 2).
6. **Pastille « Versements sans reçu ».** Sans séparation, elle compte aussi les versements saisis par l'agent lui-même. Dans le nouveau circuit, elle ne compte donc que les versements sans reçu en vigueur.
7. **Matrice des rôles.** `docs/ops/roles.md` se régénère en production après le déploiement (`php artisan roles:matrice`), parce qu'elle compte les comptes réels.

### PR 2

1. **« À jour » sans changer le compte.**
   - `SituationFinanciere` garde ses montants (versé, payé par palier, reste), lus sur tout l'argent imputé, comme D1.
   - Elle gagne `aConfirmer`, l'imputé des versements non confirmés.
   - Ses états (`inscriptionSoldee`, `solde`, `enRetard`) se calculent sur l'imputé confirmé.
   - Les filtres SQL d'`InscriptionAnnuelle` (`inscriptionSoldee`, `accesOuvert`, `entierementSoldee`, `enRetard`) ne lisent que les imputations de versements confirmés.
   - Le compte (`CompteAuditeur`, avance, formulaire de saisie, avertissements « rebloque ») reste sur tout l'argent.
2. **Confirmation sans verrou de l'auditeur.** La confirmation n'écrit pas d'argent : elle verrouille la ligne du versement, ce qui la sérialise avec son annulation. L'index unique tranche entre deux confirmations simultanées. La régularisation A1/A2 et la notification des accès partent après la validation.
3. **Scan.**
   - Le scan de la confirmation est propre à la confirmation : une même pièce ne sert jamais deux fois (`Versement::pieceDejaJointe`).
   - Si le versement n'a pas encore de bordereau, le même scan lui est joint, ce que le déclencheur de D1 autorise.
4. **Parts d'un règlement d'organisme.** L'organisme n'a pas de reçu à présenter pour chaque auditeur. Une part se confirme donc avec son règlement : l'action confirme d'un coup toutes les parts non confirmées du règlement, avec le scan de la pièce bancaire du règlement. Le contrôle croisé s'applique à chaque part.
5. **« Créer l'inscription »** est ouverte à la saisie ou à la confirmation : le service financier en a besoin pour saisir, la scolarité pour tenir le dossier.
6. **Mention sur le reçu.** Le reçu est figé et se régénère à l'identique (lot 4 §2.3).
   - La mention « Versement vérifié par la scolarité : <nom>, le <date> » n'y figure que si la confirmation existait au moment de l'émission, c'est-à-dire pour un reçu réémis après une annulation. Elle est figée dans les colonnes de §3.3.
   - Dans le circuit normal, le reçu précède la confirmation : la vérification se lit alors dans l'espace de l'auditeur (« Vérifié par la scolarité le JJ/MM/AAAA »), sur la fiche et sur le relevé de scolarité, qui se recalculent.
7. **Relance de l'auditeur.**
   - Elle est envoyée par le circuit des messages aux auditeurs, sous le motif `confirmation`, et réaffichée dans l'espace.
   - Le délai de 7 jours se compte par auditeur, sur le journal des envois : au plus une relance aboutie tous les 7 jours.
   - Un dossier retiré, exclu ou en abandon n'est pas relancé.
8. **Contrôle `VER_NON_CONFIRMES`.**
   - Niveau majeur, délai de 7 jours, responsable `scolarite_agent`.
   - Un cas par versement non confirmé depuis 7 jours, désigné par le matricule, sans nom.
9. **Reprise.**
   - La migration confirme tous les versements non annulés présents au déploiement (`reprise = true`) : `confirme_par = saisi_par`, `confirme_at = created_at`, scan = bordereau du versement.
   - Les versements annulés ne sont pas repris : ils ne comptent plus.
   - Le test d'équivalence compare l'état d'accès de chaque dossier avant la migration (ancienne règle, tout l'argent) et après.
   - La migration vérifie aussi, dans sa transaction, que l'imputé confirmé de chaque inscription égale son imputé. Au moindre écart, elle s'annule en nommant les inscriptions, comme la reprise de D1.
10. **Calendrier des 7 jours.** Le délai se compte en jours de Yaoundé : un versement enregistré le 10 est relancé le 17 à 08:00, puis le 24. Le contrôle interne et le filtre « Plus de 7 jours » de l'écran suivent la même borne.
11. **Pointage repris (lot 1).** Quand un matricule est attribué, la case « droits payés » d'un dossier devient un versement. Ce versement est confirmé dès sa création (reprise) : la case cochée était déjà la vérification de la scolarité.
12. **Vérification du compte (D1).** `scolarite:verifier-comptes` et la reprise de D1 comparent toujours les imputations aux versements sur tout l'argent. Les filtres SQL prennent pour cela l'option « tout l'argent ». Ainsi, un versement non confirmé n'est jamais signalé comme une incohérence du compte.
13. **Retour de la confirmation.** Une confirmation qui solde les droits annonce à l'agent que l'auditeur a été prévenu de ses accès, ou que l'envoi a échoué, comme le faisait la saisie avant le 15/11/2026.
14. **Affichage de « dont X à confirmer ».** La mention figure à six endroits :
    - fiche (situation et onglet Versements) ;
    - liste des auditeurs ;
    - listes et états du lot C (lignes, sous-totaux, total) ;
    - export Excel de la situation (colonne « Dont à confirmer ») ;
    - relevé de scolarité (colonne « Vérifié par la scolarité ») ;
    - espace de l'auditeur (montant, et pour chaque versement « Vérifié le » ou « Reçu bancaire à présenter à la scolarité »).
15. **Espace en attente de confirmation (relecture du 15/11).** Quand l'argent saisi solde les droits mais que l'argent confirmé ne les solde pas encore, l'espace ne dit jamais « il reste 0 FCFA à payer ».
    - L'API expose `acces.confirmation_en_attente` et `acces.recu_a_presenter`, et ne donne plus `acces.reste_inscription`.
    - L'espace affiche « Présentez votre reçu à la scolarité pour faire confirmer votre versement : vos accès seront disponibles dès sa confirmation. »
    - Il affiche « Votre versement est en cours de confirmation par la scolarité… » si tout l'argent à confirmer vient d'un organisme.
    - Chaque palier n'est « soldé » que sur l'argent confirmé ; un palier payé mais à confirmer se lit « Versé, en attente de confirmation par la scolarité ». Le relevé suit (« À confirmer »).
16. **Messages aux auditeurs (relecture du 15/11).** Un message qui chiffre l'impayé (`{reste}`, `{reste_inscription}`, `{prochaine_echeance}`) écarte l'auditeur dont un versement de l'inscription courante attend sa confirmation : la relance de confirmation lui dit de présenter son reçu. L'aperçu le compte parmi les écartés ; un message sans montant part à tous.
17. **Relance d'un accès anticipé échu (A1).** Elle n'est plus envoyée à l'auditeur dont les droits payés attendent leur confirmation (« votre virement n'est pas encore arrivé » serait faux). Le contrôle DEB_ECHUS continue de suivre le déblocage pour le personnel.
18. **Parts d'un règlement d'organisme (relecture du 15/11).** La relance « présentez votre reçu bancaire » ne part jamais pour elles : l'organisme a payé, l'auditeur n'a pas de reçu. L'espace les dit « Confirmation en cours » (origine `reglement` dans l'API). Le contrôle VER_NON_CONFIRMES continue de les suivre pour le personnel.
19. **Séparation des tâches.** Le couple `scolarite.versement_saisir` / `scolarite.versement_confirmer` entre dans SEC_SEPARATION : un compte qui cumule les deux est signalé (un cumul voulu, super administrateur, se justifie).
20. **Commande `scolarite:reprendre-confirmations`.**
    - Elle appelle la reprise vérifiée (`RepriseConfirmations::executer()`, qu'utilise aussi la migration), en tout ou rien.
    - Elle est idempotente.
    - En cas d'écart, elle refuse et nomme les inscriptions par leur uuid, sans nom.
    - Elle est **refusée hors maintenance** : l'application ouverte, elle confirmerait sans scan des versements que le service financier vient de saisir.

## 6. Permissions avant et après

| Permission | `scolarite_agent` | `scolarite_responsable` | `service_financier` | `admin` | `super_admin` |
|---|---|---|---|---|---|
| `scolarite.versement_saisir` (gestion du module) | oui → **non** | oui → **non** | oui | oui → **non** | oui |
| `scolarite.recu_emettre` | non | non | oui | non | oui |
| `scolarite.versement_annuler` | non | oui | non | oui | oui |
| `scolarite.versement_confirmer` (nouvelle, PR 2) | **oui** | **oui** | **non** | **oui** | **oui** |
| `scolarite.lettre_acces` (renvoi des accès en plus, PR 1) | oui | oui | non | oui | oui |

## 7. Textes

- SMS de l'avis de reçu et e-mail : voir le lot 4 §5.2, point 5.
- SMS de relance (ASCII, un segment, sans adresse ni téléphone) : `PSSFP : votre versement n'est pas encore confirme. Presentez votre recu bancaire original a la scolarite pour le faire valider.`
- E-mail de relance, sujet : `PSSFP — Versement à confirmer à la scolarité`. Corps : la liste des versements à confirmer (date, montant, référence, numéro du reçu), puis la consigne de présenter le reçu bancaire original à la scolarité, et l'adresse de l'espace en fin de ligne.

## 8. Critères d'acceptation

### PR 1

1. Un agent du service financier enregistre un versement et son reçu en un geste. L'auditeur reçoit l'avis.
2. Si l'émission est refusée, aucun versement n'est écrit.
3. Un agent et un responsable de scolarité ne peuvent plus enregistrer de versement, ni par l'écran ni par le service.
4. La migration retire la permission aux deux rôles, la garde au service financier, et se défait.
5. L'émetteur peut émettre le reçu d'un versement qu'il a saisi. La pastille compte ces versements.

### PR 2

1. Une confirmation exige le scan et l'attestation. Elle est refusée pour son propre versement, un versement annulé ou déjà confirmé, et sans la permission.
2. Un versement non confirmé ne débloque pas l'accès. Sa confirmation, si elle solde les droits, ouvre l'accès, régularise le déblocage et envoie le SMS des accès.
3. Les listes et filtres « à jour » ignorent les versements non confirmés. L'affichage donne « dont X à confirmer ».
4. La relance part au 7e jour, puis au plus tous les 7 jours. Le contrôle interne relève les mêmes versements.
5. Après la reprise, l'état d'accès de chaque dossier est celui d'avant.
6. Une part de règlement se confirme avec son règlement.

## 9. Déploiement (les deux PR ensemble)

Les commandes se lancent en utilisateur `pssfp`, depuis `backend`. L'application reste en maintenance de la fusion à la reprise : aucun versement ne se saisit entre la migration et le rechargement du code.

1. `php artisan down`
2. Fusionner (#134 puis #138) et récupérer le code.
3. `php artisan migrate`, qui passe dans cet ordre :
   1. `2026_12_04_100100_create_confirmations_versements_table` : table des confirmations, colonnes de vérification du reçu ;
   2. `2026_12_04_100200_reprise_confirmations_versements` : reprise vérifiée, qui s'annule au moindre écart ;
   3. `2026_12_04_100300_grant_scolarite_versement_confirmer` : confirmation pour la scolarité, `admin` et `super_admin` ;
   4. `2026_12_04_100400_revoke_scolarite_versement_saisir` : saisie retirée à la scolarité et à `admin`.
4. `php artisan optimize`
5. Redémarrer la file : `php artisan queue:restart`, puis contrôler `pssfp-queue`.
6. `php artisan scolarite:reprendre-confirmations` : reprend ce que l'ancien code aurait saisi entre la migration et le rechargement. Elle rend normalement « 0 versement(s) repris ».
7. `php artisan up`
8. Vérifier que la crontab de `schedule:run` est en place : relance de 08:00.
9. Au moment de l'arrêt d'ACMA : `php artisan scolarite:bascule-recus` (lot 4 §5.4).
10. Régénérer `docs/ops/roles.md` (`php artisan roles:matrice`), puis vérifier les rôles dans l'écran Rôles.
11. Contrôler que « Versements à confirmer » est vide juste après la reprise, et que `scolarite:verifier-comptes` rend 0.

## 10. Confirmation sur le scan du service financier (décision du 19/11/2026)

**Décideur** : M. ABE ETOUMOU Anatole, Chef USI. Cette section remplace le §2.2 point 2 et le §5 PR 2 point 3 ; le reste de la spec ne change pas.

**Constat.** Depuis le 15/11, le service financier scanne le bordereau à l'encaissement (72 versements sur 72 en production au 19/11). La scolarité devait ensuite déposer son propre scan du même papier : une deuxième copie, qui ne prouve rien de plus. Ce qui compte dans la confirmation, c'est qu'un autre agent que celui de l'encaissement ait comparé le reçu bancaire original à ce qui a été saisi.

1. **Scan exigé à l'encaissement d'un paiement en banque.** Pour les modes « Agence CREMINCAM » et « Virement bancaire » (`Versement::MODES_BANQUE`), le formulaire du service financier exige le scan du bordereau, pour un versement comme pour un règlement d'organisme. Les autres modes le gardent facultatif. La règle vit dans le formulaire : les reprises et les imports n'en dépendent pas, et la confirmation garde son propre filet (point 3).
2. **La scolarité confirme sur ce scan, sans rien joindre.** La fenêtre « Confirmer le versement » donne le montant, la référence et la date saisis, et un lien vers le scan du service financier. L'agent le compare au reçu bancaire original présenté par l'auditeur, puis coche : « J'ai comparé le reçu bancaire original présenté par l'auditeur au scan du service financier : montant, référence et date concordent. » Pour une part de règlement : « J'ai vérifié la pièce bancaire du règlement jointe par le service financier : montant, référence et date concordent. »
3. **Sans scan du service financier**, la scolarité dépose le scan comme avant (versements anciens, modes hors banque, pièce manquante). Il est alors joint au versement, comme le permet D1.
4. **Désaccord.** Si le reçu original ne concorde pas, l'agent ne confirme pas. Il signale l'écart au service financier, qui demande la correction (ADR-0015, `scolarite.versement_corriger`) ; la demande suspend la confirmation.
5. **Trace.** La confirmation garde le chemin de la pièce vérifiée (`justificatif_path`) et la nouvelle colonne `sur_scan_finances` (vrai quand la pièce est celle du service financier). La contrainte `CHECK (NOT sur_scan_finances OR justificatif_path IS NOT NULL)` l'impose. Le journal `scolarite` note la pièce utilisée.
6. **Inchangé.** Contrôle croisé (personne ne confirme sa propre saisie), confirmation définitive, effet « à jour », relance au 7e jour, parts de règlement confirmées ensemble.

Migration : `2026_12_12_100700_add_sur_scan_finances_to_confirmations_versements` (réversible, sans réécriture des confirmations existantes).
