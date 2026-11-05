# Module 4 — Scolarité, lot 1 : paiements des auditeurs

**Date** : 2026-11-05
**Décideur** : M. ABE ETOUMOU Anatole, Chef USI
**Décision d'architecture** : [ADR-0010](../adr/0010-vie-academique-auditeurs-phase-1.md)
**Échéance visée** : rentrée de la 14e promotion, les 10 et 11 novembre 2026

## 1. Objectif

Aujourd'hui, les droits d'inscription sont une case cochée, sans montant. Ce lot la remplace par un registre des versements :

- la Scolarité enregistre ce que chaque auditeur a payé, quel que soit le montant ;
- le cumul se répartit automatiquement entre les paliers ;
- le code d'accès se débloque seul quand l'inscription est soldée ;
- l'auditeur suit sa situation depuis son espace.

### Feuille de route

| Étape | Contenu |
|---|---|
| **Ce lot** | Registre des versements, fiche auditeur minimale, espace auditeur minimal |
| **Import P13** | Dès réception du fichier de la Scolarité (section 11) |
| **Lot 2** | Gestion complète des auditeurs, réglages de promotion séparés de la campagne, écran Candidatures allégé |
| **Lot 3** | Espace auditeur complet, en rubriques |
| **Lot 4** | LMD, avec sa propre spec : semestres, UE, crédits, notes, relevés |

### Hors périmètre de ce lot

- Paiement en ligne (module 9).
- Remboursements et report d'un trop-perçu d'une année sur l'autre.
- Suspension d'un compte eCampus pour impayé.
- Reçus officiels numérotés : le bordereau de la banque fait foi.
- Saisie groupée ou import de relevés bancaires.
- Passage automatique en M2.

## 2. Barème

| Niveau | Inscription | 1re tranche | 2e tranche | Total |
|---|---|---|---|---|
| **Master 1** | 185 000 | 500 000 | 500 000 | 1 185 000 |
| **Master 2** | 135 000 | 500 000 | 500 000 | 1 135 000 |

Montants en FCFA. Le barème se règle par année académique et par niveau, et peut donc changer d'une année à l'autre.

## 3. Données

### `baremes_scolarite`

`annee_academique`, `niveau` (`M1`, `M2`), `montant_inscription`, `montant_tranche1`, `montant_tranche2`, `inscription_echeance` (texte libre), `tranche1_echeance` et `tranche2_echeance` (dates, facultatives).

Une seule ligne par couple année + niveau.

### `auditeurs`

`uuid`, `candidature_id` (unique, obligatoire). Pas de `user_id` : `AjoutRetenu::corrigerTelephone` peut changer le compte d'un dossier, une copie se désynchroniserait. Le compte se lit à travers la candidature.

Le matricule, le mot de passe FOAD, la connexion et l'identité restent sur la candidature pendant ce lot. Ils seront déplacés au moment du lot 4.

### `inscriptions_annuelles`

`uuid`, `auditeur_id`, `bareme_id`, `annee_academique`, `niveau`, copie des montants du barème, `remise_montant` (0 par défaut), `remise_motif`, `remise_par`, `remise_at`, `acces_notifie_at`.

Une seule inscription par auditeur et par année. La copie des montants garantit qu'une modification du barème ne change rien pour les auditeurs déjà inscrits. Les **échéances**, elles, se lisent en direct sur le barème : une date corrigée par le responsable vaut pour tous les inscrits.

Contraintes en base (`CHECK`) : montants positifs ou nuls, remise entre 0 et le total, versement strictement positif, niveau `M1` ou `M2`.

### `versements`

`uuid`, `inscription_annuelle_id`, `montant`, `date_versement`, `mode`, `reference`, `payeur` (`auditeur`, `employeur`, `autre`), `justificatif_path` (facultatif, stockage privé), `saisi_par`, `annule_at`, `annule_par`, `annule_motif`.

- **Pas de suppression :** une annulation renseigne les trois champs `annule_*`, et la ligne reste.
- **Montant :** entier strictement positif.
- **Date :** jamais dans le futur.
- **Mode :** `cremincam_agence`, `virement`, `especes`, `orange_money`, `mtn_money`, `carte_visa`, `autre`. Le mode `reprise` est réservé à la migration et n'est pas proposé aux agents.
- **Référence :** obligatoire, sans espaces autour et en majuscules. Unique par mode parmi les versements non annulés, quel que soit l'auditeur. Une référence redevient utilisable si le versement qui la portait est annulé.

### Création

- Un auditeur et son inscription M1 sont créés à l'attribution du matricule, quel que soit le chemin (attribution groupée, attribution unitaire, ajout d'un retenu).
- L'année est celle de la campagne du dossier.
- Si le barème de cette année et de ce niveau n'existe pas, l'attribution du matricule aboutit quand même. L'auditeur est créé sans inscription, signalé « Barème manquant » dans la liste, et la fiche propose « Créer l'inscription ». Son code d'accès reste indisponible.

## 4. Règles de calcul

La situation n'est jamais stockée : le cumul, les paliers et le reste à payer sont recalculés à partir des versements non annulés et de la remise.

1. **Remise :** elle s'impute en partant de la fin (2e tranche, puis 1re, puis inscription). Elle est plafonnée au total dû.
2. **Versements :** le cumul remplit l'inscription, puis la 1re tranche, puis la 2e.
3. **Inscription soldée :** le montant dû sur ce palier, après remise, est entièrement couvert. Une exonération totale solde donc l'inscription sans aucun versement.
4. **Trop-perçu :** autorisé après confirmation de l'agent. L'excédent reste affiché et n'est pas reporté sur l'année suivante.
5. **Retard :** un palier non soldé dont la date d'échéance est dépassée est affiché « En retard ». C'est une information, sans aucun effet sur l'accès. L'échéance de l'inscription étant un texte libre, ce palier n'est jamais marqué en retard.

### Exemple de référence (M1, remise de 200 000, 485 000 versés)

| Palier | Dû | Payé | Reste |
|---|---|---|---|
| Inscription | 185 000 | 185 000 | 0 |
| 1re tranche | 500 000 | 300 000 | 200 000 |
| 2e tranche | 300 000 | 0 | 300 000 |
| **Total** | **985 000** | **485 000** | **500 000** |

## 5. Accès à eCampus

L'inscription prise en compte est celle de l'année académique la plus récente de l'auditeur.

| État | Condition | Ce que voit l'auditeur |
|---|---|---|
| **Bloqué** | Inscription non soldée, ou aucune inscription | « Vos accès seront disponibles dès le règlement de l'inscription », avec le reste à payer |
| **En préparation** | Inscription soldée, compte eCampus pas encore créé | « Accès en préparation ». La fiche signale le problème à l'agent |
| **Disponible** | Inscription soldée, compte eCampus créé, identifiants présents | L'identifiant, le bouton « Afficher » et le lien vers eCampus |
| **Indisponible** | Dossier retiré | Aucun bloc d'accès |

- `IdentifiantsMoodle::remettables()` lit désormais cette règle au lieu de l'ancienne case : l'envoi manuel et la lettre d'accès restent possibles pour un auditeur à jour.
- Si une annulation ou une baisse de remise fait repasser l'inscription sous le seuil, le code disparaît de l'espace. Il reste valable sur eCampus, et l'agent en est averti dans le formulaire avant de valider.
- L'export « Comptes Moodle » garde tous les retenus, comme aujourd'hui.
- La lettre d'admission signée reste visible sans condition de paiement.

### SMS « accès disponibles »

Texte, en ASCII et en un seul segment, avec l'adresse tirée de la configuration :

`PSSFP : vos acces FOAD sont disponibles dans votre espace apply.pssfp.org.`

- **Déclenchement :** au premier passage à l'état « Disponible » d'une inscription, que ce soit par un versement, une remise ou la création du compte eCampus.
- **Une seule fois par inscription :** `acces_notifie_at` est posé par une mise à jour conditionnelle, et l'envoi part après la validation de la transaction. Annuler puis ressaisir ne renvoie rien.
- **En cas d'échec :** le versement reste enregistré, `acces_notifie_at` revient à vide, l'agent est averti et la fiche propose « Renvoyer ». Pas de nouvelle tentative programmée ; le prochain versement ou la prochaine remise retente l'envoi.
- **Canal :** SMS seulement, sans e-mail.
- **Contenu :** jamais de mot de passe.

## 6. Administration

Nouveau groupe de navigation : **Vie académique**.

### Liste des auditeurs

- **Recherche :** matricule, nom, téléphone.
- **Colonnes :** matricule, nom, promotion, niveau, cumul versé, reste à payer, situation.
- **Filtres :** promotion, niveau, inscription non soldée, en retard (inscription soldée, tranche échue), à jour, soldé, barème manquant. Même priorité que le badge.
- **Export Excel :** matricule, nom, prénom, promotion, niveau, dû, remise, versé, reste, situation. Sans téléphone. Toutes les valeurs saisies sont écrites comme du texte, jamais comme des formules.

### Fiche auditeur

- **Identité, en lecture seule :** nom, matricule, téléphone, promotion, spécialité.
- **Situation :** paliers (dû, payé, reste), remise, état de l'accès.
- **Versements :** historique complet, lignes annulées comprises et clairement distinguées.
- **Actions :** « Enregistrer un versement », « Annuler un versement » (motif obligatoire), « Accorder une remise » (montant, motif obligatoire), « Renvoyer le SMS », « Créer l'inscription », « Joindre le bordereau ».
- **Bordereaux :** seuls les fichiers du dossier `scolarite/justificatifs/`, nommés par le formulaire, sont acceptés et signés. Un chemin venu d'ailleurs est refusé (même bucket que les pièces des candidats). Chaque consultation est journalisée.
- **Journal :** les barèmes gardent l'ancienne et la nouvelle valeur de chaque montant et échéance.
- **Liens :** dossier de candidature et lettre signée, affichés seulement si l'agent en a déjà le droit.

### Barèmes

Une ligne par année et par niveau. Modifier un barème affiche un avertissement : il ne s'applique qu'aux inscriptions créées ensuite.

### Écrans existants modifiés

- **Candidatures :** les 4 actions de pointage (ligne et groupe) et le filtre « Droits d'inscription » disparaissent. Un lien mène à la fiche auditeur.
- **Campagne :** les champs de montants et d'échéances disparaissent. Les références, la rentrée et la signature restent, jusqu'au lot 2.
- **Lettre d'admission :** ses montants et échéances viennent du barème M1 de l'année de la campagne.

## 7. Droits

Nouveau module `scolarite` dans `ModulePermissionMap`, avec une migration additive. `RolePermissionSeeder` n'est pas relancé en prod.

| Droit | Permission | Agent | Responsable | Admin / super admin |
|---|---|---|---|---|
| Consulter les auditeurs | `view_any_auditeur`, `view_auditeur` (lecture) | Oui | Oui | Oui |
| Enregistrer un versement | `scolarite.versement_saisir` (gestion) | Oui | Oui | Oui |
| Exporter la situation | `scolarite.export` (sensible) | Oui | Oui | Oui |
| Annuler un versement | `scolarite.versement_annuler` (sensible) | Non | Oui | Oui |
| Accorder une remise | `scolarite.remise` (sensible) | Non | Oui | Oui |
| Modifier les barèmes | `scolarite.bareme` (sensible) | Non | Oui | Oui |
| Voir le mot de passe FOAD | `candidature.matricules_moodle` (existante) | Non | Non | Oui |

- **Nouveaux rôles :** `scolarite_agent` et `scolarite_responsable`.
- **Sans accès aux dossiers par défaut :** ces rôles n'ouvrent pas le module Admissions. Une personne qui en a besoin peut cumuler un autre rôle.
- **`candidature.frais_inscription` :** retirée par migration (`down()` la rend à super_admin et admin).
- **Journal :** enregistrement, annulation, remise, modification de barème, affichage du mot de passe par l'auditeur.
- **2FA :** les deux rôles sont soumis à la double authentification de l'admin.

## 8. Espace de l'auditeur

La page `/dossier` reste en place. Pour un retenu, l'ordre devient : Ma scolarité, Mes accès, Mes documents, Messages, Décision, puis le reste. Les autres phases du dossier ne changent pas.

- **En-tête :** matricule, promotion, niveau, année académique.
- **Ma scolarité :** cumul, reste à payer, paliers avec échéance et état, remise éventuelle, versements valides (date, montant, mode, référence), bouton du relevé. Ni le nom de l'agent, ni les lignes annulées.
- **Relevé PDF :** identité, matricule, versements, cumul, reste par palier, mention « le bordereau de la banque fait foi ». Généré à la demande, jamais stocké, sans mot de passe.
- **Frais de dossier (50 000) :** distincts et hors de ce cumul.
- **Textes :** tous via `t('key')`.

### API

| Route | Rôle |
|---|---|
| `GET /v1/applications/me/scolarite` | Situation et versements de l'auditeur connecté |
| `GET /v1/applications/me/releve-scolarite` | Relevé PDF |
| `POST /v1/applications/me/acces/reveler` | Renvoie le mot de passe si l'état est « Disponible » |

- Aucun identifiant interne n'est exposé.
- `GET /me` n'inclut jamais le mot de passe.

### Protection du mot de passe

- Demandé seulement au clic sur « Afficher », jamais présent dans la page initiale.
- Le serveur revérifie l'état à chaque demande : 403 si l'état n'est pas « Disponible ».
- Limité à 10 demandes par heure, par compte et par type de connexion (un agent en consultation n'épuise pas le quota de l'auditeur). Relevé PDF : 20 par heure.
- L'auditeur se retrouve par son compte : un brouillon déposé dans une campagne suivante ne masque pas sa scolarité.
- Réponse `Cache-Control: no-store`.
- Chaque affichage est journalisé, sans jamais écrire le mot de passe.
- 403 pour un agent en consultation de l'espace, qui voit la situation financière mais pas le bouton.

## 9. Concurrence et erreurs

- Enregistrement, annulation et remise se font dans une transaction, avec verrou sur la ligne de l'inscription.
- Une référence en double affiche un message clair dans le formulaire, y compris quand deux agents saisissent en même temps (index unique partiel).
- Les erreurs de stockage du justificatif n'annulent pas le versement : l'agent en est averti et peut ajouter le scan ensuite.
- Le stockage MinIO reste lu sans `HeadObject` (`Storage::exists()` interdit, `fetchFileInformation(false)` sur les formulaires).
- Les formulaires Filament portent leurs `validationMessages`, car l'application n'a pas de `lang/fr/validation.php`.

## 10. Reprise de l'existant

Par migration additive, idempotente et réversible :

1. **Auditeurs :** un auditeur et une inscription M1 pour chaque dossier ayant un matricule, y compris les dossiers retirés, pour conserver leur historique.
2. **Barèmes 2026-2027 :** M1 repris des valeurs de la campagne P14, ou à défaut de la configuration. M2 à 135 000 + 500 000 + 500 000, échéances vides. Le responsable vérifie les valeurs avant la mise en service.
3. **Cases déjà cochées :** chacune devient un versement du montant de l'inscription, mode `reprise`, référence `REPRISE-<matricule>`, date et agent repris du pointage, à compléter par la Scolarité.
4. **Dossier coché sans matricule :** la reprise se fait à l'attribution du matricule. C'est la seule lecture restante de l'ancienne colonne.
5. **Pas de SMS de masse :** `acces_notifie_at` est posé pour les inscriptions dont l'accès est déjà disponible (inscription soldée et compte eCampus présent). Celles dont le compte reste à créer sont prévenues à sa création. D'où l'ordre de la section 13 : `ecampus:rapprocher` avant la migration.
6. **Retour arrière :** la migration des tables refuse de les supprimer dès qu'un versement a été saisi à la main.

`frais_inscription_payes_at` et `frais_inscription_payes_by` restent en place jusqu'à stabilisation, pour permettre un retour arrière.

## 11. Import de la P13 (juste après ce lot)

Fichier fourni par la Scolarité : matricule, nom, téléphone, e-mail, versements déjà reçus. L'import s'appuie sur `AjoutRetenu`, rattaché à une campagne P13 close, avec une inscription M2 pour 2026-2027. Ces dossiers restent hors PV et hors statistiques de dépôt. La spec de détail sera rédigée à réception du fichier.

## 12. Tests

- **Calcul (unitaire) :** table de cas M1, M2, remise partielle, remise touchant l'inscription, exonération totale, trop-perçu, annulation, échéance dépassée.
- **Versements :** référence en double, saisie concurrente, dossier retiré, annulation puis ressaisie de la même référence.
- **SMS :** premier solde, une seule fois, annulation puis ressaisie, échec et renvoi, création du compte après le solde, contenu sans mot de passe.
- **Droits :** chaque ligne de la section 7, dans les deux sens.
- **API :** un auditeur ne lit jamais un autre, 403 sur le mot de passe (non soldé, consultation), limite de demandes, `no-store`, mot de passe absent de `/me` et des journaux.
- **Migration :** les mêmes personnes gardent le code d'accès avant et après, idempotence, retour arrière.
- **Lettres :** la lettre d'admission lit le barème, la lettre d'accès suit la nouvelle règle.
- **Playwright :** les états de l'espace, à 320, 768 et 1440 px.
- **Navigateur :** essai réel des formulaires d'admin, y compris les messages d'erreur.
- **Relectures :** sous-agents `candidature-reviewer`, `security-reviewer`, `filament-reviewer`, `a11y-reviewer`, `i18n-reviewer`.

## 13. Déploiement

1. PR #65 en production, puis `php artisan ecampus:rapprocher` (avant la migration de ce lot, cf. §10.5).
2. Sauvegarde de la base.
3. `php artisan migrate --force`, sans relancer le seeder.
4. Vérification des barèmes par le responsable, notamment les échéances.
5. Attribution des rôles aux personnes désignées par le Chef USI.
6. Essai sur un vrai dossier avec un agent : versement, accès, relevé.

**Retour arrière :** les migrations sont additives et l'ancienne colonne reste en place, donc on peut revenir au code précédent. Les versements saisis entre-temps restent en base, mais l'ancien code ne les prend pas en compte.

## 14. Si le temps manque avant le 10 novembre

**Indispensable :** registre des versements, fiche auditeur, déblocage du code, affichage dans l'espace.

**Reporté en premier, dans cet ordre :** l'export Excel, puis le relevé PDF. Le calcul et les tests sur l'argent ne sont pas négociables.
