# Analyse de l'application candidature_p13 (PHP existante)

> **Date d'analyse** : 2026-05-05
> **Source** : `candidature_p13.zip` fourni par M. ABE ETOUMOU
> **Méthode** : extraction et lecture du code source PHP + schéma SQL
> **Statut développement** : Promotion 13 (cycle 2025), partiellement déployé selon le fichier "A faire"

Ce document complète l'audit Joomla biblio en analysant le **système actuel de candidature** (différent du Joomla biblio). C'est un mini-CRUD PHP avec PDO+MySQL, dompdf, PHPMailer, PHPQRCode, développé sur mesure pour la promotion 13. Le présent audit alimente la révision de la spec module 5 et identifie ce qui doit être conservé, modifié ou abandonné dans le nouveau système.

## 1. Architecture du système actuel

```
candidature.pfinancespubliques.org/index.php/  (URL publique actuelle)
├── index.html                  # Landing page avec hero + CTA
├── préinscription.php          # Page intermédiaire explicative
├── formulaire.php              # Formulaire multi-étapes (1 page, JS pour le wizard)
├── save_first_step.php         # Endpoint AJAX (auto-save étape 1)
├── submit.php                  # Soumission finale + création compte user
├── recapitulatif.php           # Page récap candidat post-soumission
├── login.php                   # Connexion candidat ou admin
├── auth.php                    # Helpers session
├── logout.php
├── dashboard.php               # Dashboard admin (compteurs + liste candidats)
├── get_candidate_details.php   # AJAX détail candidat dans dashboard
├── update_status.php           # AJAX changement postulant ↔ candidat
├── update_candidate.php        # Édition admin
├── delete_candidate.php
├── get_regions.php             # AJAX cascading région
├── get_departements.php        # AJAX cascading département
├── get_indicatif.php           # AJAX indicatif téléphone par pays
├── telecharger_pdf.php         # Génération PDF récépissé avec filigrane + QR
├── envoyer_mail.php            # Envoi email confirmation (incomplet — TODO)
├── pssfp_candidatures.sql      # Dump BDD
├── dompdf/                     # Lib génération PDF
├── PHPMailer/                  # Lib envoi mail
├── phpqrcode/                  # Lib QR code
├── style/                      # CSS
└── uploads/                    # Photos uploadées par les candidats
```

**Stack** : PHP 8.3.14 (selon le dump), MySQL 9.1, PDO. Hébergement supposé sur le même infrastructure que biblio (creawebhosting / cloudlogin).

**Dépendances** : dompdf, PHPMailer (configuré mais SMTP placeholder), PHPQRCode (importé mais QR pas encore implémenté).

**Branche Git** : présence d'un `.git/` dans l'archive — code sous versioning.

## 2. Schéma BDD `pssfp_candidatures`

Quatre tables principales :

### Table `candidats` (cœur du système)

35 colonnes capturant tout le profil candidat. Tous les champs en `varchar` ou `int`/`date`, MyISAM (pas de FK strictes). PK auto-increment.

| Champ | Type | Notes |
|---|---|---|
| id | int AI | PK |
| numero_candidat | varchar(20) | Format `P13025-{id}` (P=promotion, 13=13e, 025=2025, id) |
| **specialite** | varchar(100) | **1 seul vœu** (pas 3) — texte libre alimenté par dropdown |
| type_etude | varchar(20) | Présentiel / Distanciel |
| premiere_langue | varchar(20) | Français / Anglais |
| civilite | varchar(10) | M. / Mme / Mlle |
| nom, prenom, epouse | varchar(100) | epouse = nom de jeune fille pour les femmes mariées |
| date_naissance | date | |
| lieu_naissance | varchar(100) | Ville de naissance (texte libre) |
| region, departement | varchar | Région + département camerounais (cascading) |
| statut_matrimonial | varchar(20) | Célibataire/Marié/... |
| pays_origine, pays_residence | varchar(100) | Code ISO-2 (CM, FR, etc.) |
| adresse, ville_residence | varchar | |
| telephone1, indicatif1 | varchar | Tel principal Whatsapp avec indicatif pays |
| telephone2, indicatif2 | varchar | Tel secondaire optionnel |
| email | varchar(100) | |
| diplome_obtenu, institut, specialite_diplome, annee_diplome | varchar/int | Cursus académique du candidat |
| **statut_actuel** | varchar | **Etudiant / Fonctionnaire-Contractuel / Privé** (3 enum) |
| employeur, adresse_employeur2, tel_employeur | varchar | Si statut ≠ Etudiant |
| moyen_connaissance | varchar | Marketing tracking : Réseaux sociaux, Recommandation, Site web, etc. |
| **engagement_nom** | varchar | **Signature numérique** : le candidat tape son nom complet en confirmation |
| mode_paiement | varchar | Espèces / Virement / autre — saisi par admin lors du dépôt physique |
| photo | varchar(255) | Chemin `uploads/{hash}.png` — seule pièce numérique uploadée |
| date_inscription | timestamp | Auto |
| **statut** | enum | **`postulant`** (juste inscrit en ligne) ou **`candidat`** (a déposé physiquement le dossier) |

**Champs absents de mes specs initiales** :
- `epouse` (nom de jeune fille)
- `engagement_nom` (signature)
- `moyen_connaissance` (marketing)
- `premiere_langue`
- `mode_paiement` (saisi par admin)

**Champs présents dans mes specs et absents ici** (à abandonner ou conserver V1) :
- `voeu_2_specialite_id`, `voeu_3_specialite_id` → pas dans le système réel, **1 vœu suffit**
- `nb_enfants` → présent dans submit.php mais pas dans la BDD finale (incohérence)
- `projet_professionnel`, `motivation` (textareas longues) → **pas dans le système réel** ! Le candidat ne rédige pas de motivation côté V1.

### Table `users`

Authentification simpliste :

| Champ | Type | Notes |
|---|---|---|
| id | int AI | |
| username | varchar(50) UNIQUE | = `numero_candidat` (P13025-XX) ou login admin |
| password | varchar(255) | Hashé bcrypt — initialement = telephone1 du candidat |
| role | enum('candidat','admin') | |
| candidat_id | int | FK vers candidats.id (sans contrainte stricte) |
| created_at | timestamp | |

**Auth dans le code actuel** : login = `numero_candidat`, password = `telephone1`. Le compte est créé **automatiquement** à la soumission du formulaire avec le téléphone comme mot de passe initial. Très simple côté UX, mais peu sécurisé.

### Table `pays` (référentiel)

200+ pays avec code ISO-2 et indicatif téléphonique (`+237` Cameroun, `+33` France, etc.). Sert au cascading dropdown.

### Table `region` (Cameroun)

11 régions avec **quota d'admission** :

| Région | Quota |
|---|---|
| ADAMAOUA | 5 % |
| CENTRE | 15 % |
| EST | 4 % |
| EXTREME-NORD | 18 % |
| LITTORAL | 12 % |
| NORD | 7 % |
| NORD-OUEST | 12 % |
| OUEST | 13 % |
| SUD | 4 % |
| SUD-OUEST | 8 % |
| Z AUTRES | 100 % |

**Implication métier importante** : la sélection des candidats à l'admission tient compte de ces quotas pour assurer l'équilibre régional. Le dashboard admin doit afficher la **répartition régionale en temps réel** vs ces quotas.

### Table `departement` (Cameroun)

58 départements avec FK région et chef-lieu. Sert au cascading dropdown final (Pays → Région → Département).

## 3. Workflow utilisateur (parcours actuel)

```
1. Visiteur arrive sur index.html
   → CTA « S'inscrire » mène à préinscription.php (page explicative)

2. Préinscription explique le processus
   → Bouton « Commencer l'inscription » mène à formulaire.php

3. Formulaire multi-étapes (wizard JS sur 1 page)
   Étape 1 : Spécialité, type étude, langue, photo, identité
   Étape 2 : Coordonnées, géographie (cascading), contact
   Étape 3 : Diplôme, institut, situation professionnelle
   Étape 4 : Engagement (signature) + mode paiement
   → Submit.php

4. submit.php
   - Génère un nouveau numéro candidat « P{yy}{rand4} »
     (note : code actuel rand4, mais le trigger SQL fait CONCAT('P13025-', id))
   - Insert candidat
   - Crée user auto (username=numero, password=telephone1, role=candidat)
   - Session candidat ouverte
   - Redirect vers recapitulatif.php

5. recapitulatif.php
   - Affiche les infos du candidat
   - Bouton « Télécharger PDF » → telecharger_pdf.php

6. telecharger_pdf.php
   - Génère un PDF dompdf avec :
     - Logo PSSFP en haut
     - Entête PSSFP (image entete.png)
     - Toutes les infos du candidat
     - QR code (TODO non implémenté complètement)
     - Filigrane "Copie Étudiant" ou "Copie Administration"
   - Stream PDF en download

7. Le candidat se rend physiquement au PSSFP avec :
   - Le PDF imprimé (signé)
   - Les pièces papier (CV, copies diplômes, photos, etc.)
   - Le paiement (espèces ou récépissé bancaire)

8. L'admin (BENOH) bascule le statut postulant → candidat dans dashboard.php
   → Le dossier est officiellement enregistré
```

**Particularité métier importante** : le système actuel **n'attend aucune pièce jointe en ligne** sauf la photo. Tout le dossier physique (diplômes, CV, lettre, pièce d'identité) est apporté en personne au PSSFP. C'est cohérent avec un public principalement camerounais où la confiance documentaire passe par le présentiel.

## 4. Dashboard admin

`dashboard.php` (admin requis via `requireAdmin()`) affiche :

- **3 compteurs en haut** : nombre postulants, nombre candidats, total inscrits
- **Liste paginée des candidats** avec colonnes : N° candidat, Nom complet, Email, Téléphone, Statut (dropdown modifiable), Actions
- **Actions par ligne** : Voir détails (modal AJAX), Télécharger PDF, Modifier, Supprimer
- **Modification statut inline** : dropdown postulant/candidat → AJAX vers update_status.php
- **Le compteur « dossiers physiques déposés »** était demandé dans le TODO mais marqué non fait

## 5. Fichier "A faire" — TODO du développeur initial

Liste partagée par le développeur avec coches de statut (✅ = fait) :

**Faits** :
- Interface, BDD, CRUD ✅
- Page admin avec édition + téléchargement fiche ✅
- Cascading indicatif pays / région / département ✅
- Dropdown statut employé (Etudiant/Fonctionnaire/Privé) ✅
- Suppression de la contrainte « diplôme requis » ✅
- Étape paiement après formulaire ✅
- Page intermédiaire d'explication (préinscription) ✅
- Dashboard avec compteurs ✅
- Liste candidats + nombre total ✅
- Téléchargement fiche PDF ✅
- Login = numero + téléphone ✅
- PDF avec filigrane Copie Étudiant + Copie Administration ✅
- Upload photo dans le formulaire ✅

**Non faits — à reprendre dans la nouvelle V1** :
- ❌ **Compteur dossiers physiques déposés** (la modification statut existe, mais pas l'affichage agrégé séparé)
- ❌ **QR code sur PDF qui renvoie vers la page récap du candidat** — la lib est présente mais pas utilisée
- ❌ **Envoi automatique du mail de confirmation** — code présent (envoyer_mail.php) avec PHPMailer mais SMTP placeholder, marqué "$$$$$$ en attente"

## 6. Sécurité du système actuel

**Faiblesses identifiées** :

- **Mot de passe = téléphone** : trivial à deviner par toute personne ayant le téléphone du candidat. À renforcer dans la V1.
- **PDO root sans password** : connexions BDD avec `'root', ''` — config de dev ! Si déployé en l'état, c'est une faille critique. À confirmer côté config production.
- **MyISAM** au lieu de InnoDB : pas de transactions, pas de FK strictes. Risque de désynchronisation candidat/user en cas de crash.
- **htmlspecialchars** sur les inputs : OK pour XSS mais incomplet (ne couvre pas les attributs).
- **Pas de rate limiting** sur login.
- **Pas de captcha** sur le formulaire d'inscription : risque de spam.
- **Pas d'antivirus** sur l'upload photo.
- **CSRF token** présent sur login.php uniquement — absent sur les autres formulaires d'admin.
- **Logs PII** : les fichiers `app_errors.log` et `db_errors.log` peuvent contenir des données candidat.

**Points positifs** :

- Bcrypt pour password (PASSWORD_DEFAULT = bcrypt actuel).
- session_regenerate_id à la connexion.
- Headers de sécurité (X-Frame-Options DENY, X-Content-Type-Options nosniff) sur login.
- Délai de 2s sur échec login (anti-bruteforce primitif).
- Validation MIME type sur upload photo (jpeg/png seulement).

## 7. Assets institutionnels récupérables

Trois assets utilisables tels quels pour le nouveau frontend :

- `logo.png` (180 KB) — logo PSSFP officiel.
- `entete.png` (110 KB) — bandeau institutionnel utilisé dans le PDF récépissé.
- `bg1.jpg`, `bg2.jpg`, `bg3.jpg` (~17 MB total) — photos de fond utilisées dans le formulaire (campus, bâtiment, étudiants).

Copie effectuée vers `pssfp/docs/migration-candidature/logo.png` et `entete.png` pour réutilisation. Les `bg*.jpg` sont volumineux et devront être compressés avant intégration au frontend (cibler ≤ 500 KB chacun en WebP).

## 8. Données réelles disponibles dans le dump

Le dump contient **1 seul candidat de test** (Kouedi Kouedi Gaitan Emmanuel, P13025-47). C'est probablement la base de dev — la production active a vraisemblablement plus de candidatures.

**Action** : si on doit récupérer les vrais candidats Promotion 13, il faut un dump frais de la BDD prod (différent du dump présent dans l'archive). À demander à l'hébergeur ou à exporter depuis phpMyAdmin si Anatole y a accès.

**Décision Anatole 5 mai** : bascule franche, pas de migration des candidatures Joomla en cours. Cette décision **s'étend** aussi aux candidatures du système PHP de la P13 ? À confirmer :

- Si la P13 est terminée et les candidats sélectionnés : pas besoin de migrer.
- Si la P14 (campagne 2026) est en cours sur l'ancien système : à migrer vers le nouveau OU à laisser sur l'ancien jusqu'à clôture.
- Préférence simplicité : démarrer P14 directement sur le nouveau formulaire `candidature.pssfp.net`.

## 9. Différences majeures avec ma spec module 5 actuelle

J'avais sur-conçu certains aspects par rapport au besoin réel. Voici l'écart à régler :

| Aspect | Ma spec actuelle | Système réel | Recommandation V1 |
|---|---|---|---|
| Vœux spécialité | 3 vœux ordonnés | 1 vœu unique | **1 vœu** (s'aligner sur le réel) |
| Statuts dossier | 7 (draft/submitted/under_review/complement_requested/accepted/rejected/withdrawn) | 2 (postulant/candidat) | **Hybride** : statuts simples côté candidat, 4 statuts admin (postulant, candidat, accepté, refusé) |
| Pièces jointes | 6 obligatoires en ligne | Photo seule, le reste en papier | **Photo seule + pièces optionnelles** (CV PDF en option) |
| Auth | Email + password fort + 2FA optionnel | Numéro candidat + téléphone | **Numéro + mot de passe choisi à l'inscription** + magic link de récupération |
| Profil candidat | Table dédiée séparée | Tout dans `candidats` | **Conserver le modèle plat** (plus simple) |
| Campagnes | Table `campagnes_candidature` séparée | Numéro hardcodé par promo | **Table simple `campagnes`** avec champ `prefix_numero` (P{N}, où N = numéro de promotion) |
| Projet professionnel | Textarea 500-1500 mots | Absent | **Optionnel** (champ texte court) |
| Motivation | Textarea 300-800 mots | Absent | **Optionnel** |
| Workflow validation | Submit → review → décision | Postulant → candidat → décision admin | **Conserver simple** : statuts métier réels |

## 10. Recommandations pour le nouveau module 5

### 10.1 Schéma BDD à ajuster

Une seule table `candidatures` (au lieu de `candidatures` + `candidate_profiles` séparés) avec les champs réels du système actuel. Plus simple à maintenir, plus aligné avec le besoin.

Champs à ajouter par rapport à ma spec actuelle :
- `epouse` TEXT NULL — nom de jeune fille
- `premiere_langue` TEXT NOT NULL CHECK IN ('fr', 'en')
- `type_etude` TEXT NOT NULL CHECK IN ('presentiel', 'distanciel')
- `engagement_nom` TEXT NOT NULL — signature à la soumission
- `moyen_connaissance` TEXT — marketing
- `mode_paiement` TEXT — saisi par admin
- `photo_path` TEXT — chemin MinIO
- `region`, `departement` TEXT — pour le Cameroun

Champs à abandonner :
- `voeu_2_specialite_id`, `voeu_3_specialite_id` (1 vœu suffit)
- Les 6 types de pièces jointes obligatoires (juste photo + CV optionnel)
- `projet_professionnel`, `motivation` longs (rendus optionnels en V1)

### 10.2 Tables référentielles à seeder

3 tables `pays`, `regions_cameroun`, `departements_cameroun` avec les données du dump existant — réutiliser tel quel.

### 10.3 Auth

Login = `numero_candidat` + `mot_de_passe` (le mot de passe est défini par le candidat à l'inscription, pas le téléphone). Le téléphone reste utilisé pour OTP en cas de mot de passe oublié (récupération).

Conserver la simplicité UX du numéro candidat comme login — c'est lisible et mémorisable pour des candidats peu à l'aise avec le numérique.

### 10.4 Génération du numéro

Format aligné sur le système actuel : `P{N}{YY}-{ID}` où N = numéro de promotion, YY = année à 2 chiffres, ID = auto-increment dans la campagne.

Pour la P14 / 2026 : `P14026-001`, `P14026-002`, etc.

### 10.5 Campagne configurable

Petite table `campagnes` :

```sql
campagnes (
  id, slug, prefix_numero (ex: 'P14026-'),
  promotion_id, opens_at, closes_at,
  results_at, status, max_voeux DEFAULT 1
)
```

Permet de gérer plusieurs campagnes au fil des promotions sans hardcode.

### 10.6 PDF récépissé

Conserver l'approche actuelle :

- **2 versions** générées en un seul PDF avec **page « Copie Étudiant »** et **page « Copie Administration »** filigranées.
- **QR code** au pied de chaque page pointant vers `https://candidature.pssfp.net/c/{numero}/qr` qui ouvre la fiche récap du candidat (pour vérification rapide par les agents PSSFP au dépôt physique).
- **Logo + entête PSSFP** réutilisés depuis les assets existants.
- **Hash SHA256** imprimé en bas pour traçabilité (signature numérique du PDF).

Implémentation Laravel : `barryvdh/laravel-dompdf` + `simple-qrcode` (ou `bacon/bacon-qr-code`).

### 10.7 Quotas régionaux dans le dashboard

Ajouter un widget Filament dans le dashboard `Candidatures` :

- Tableau des 11 régions avec : quota cible (%), nombre de candidats actuels, % réel, écart.
- Couleur verte si conforme, orange si écart 5-10%, rouge si > 10%.
- Permet au comité d'admission de visualiser la diversité régionale en temps réel.

### 10.8 Email de confirmation

À implémenter (TODO non fait dans le système actuel). Template Blade Laravel :
- Salutation personnalisée
- Numéro candidat
- Spécialité choisie
- Lien vers la page de suivi `https://candidature.pssfp.net/c/{numero}`
- Instructions pour le dépôt physique au PSSFP (adresse, horaires, frais 50 000 FCFA à CREMINCAM)
- Pièces à apporter
- BCC vers `admissions@pssfp.net`

## 11. Décisions à valider par Anatole (D1-D6)

| ID | Question | Recommandation |
|---|---|---|
| **D1** | Format numéro candidat : conserver `P{N}{YY}-{id}` du système actuel ou aligner sur ma spec `C-{year}-{xxxx}` ? | **Conserver `P{N}{YY}-{id}`** — déjà connu des bénéficiaires, plus parlant (rappelle le numéro de promotion) |
| **D2** | 1 seul vœu de spécialité (système actuel) ou 3 vœux ordonnés (ma spec) ? | **1 vœu** — aligné sur la réalité opérationnelle. Si flexibilité voulue, ajouter un champ texte « second choix éventuel » non obligatoire |
| **D3** | Auth simple (numéro + téléphone) ou robuste (email + mot de passe + 2FA) ? | **Hybride** : login = numéro candidat (UX simple), password = mot de passe défini à l'inscription (12 caractères min), récupération par OTP SMS sur le téléphone. Pas de 2FA pour les candidats (trop de friction) |
| **D4** | Statuts simples (postulant/candidat) ou riches (7 statuts) ? | **Statuts simples côté candidat** (3 valeurs : `postulant`, `candidat`, `decide`), enrichi côté admin avec `decision = accepted | rejected | pending` séparée |
| **D5** | Pièces jointes : photo seule (système actuel) ou 6 pièces obligatoires (ma spec) ? | **Photo obligatoire + CV PDF optionnel + diplôme PDF optionnel**. Le reste reste papier — c'est culturellement adapté au public camerounais |
| **D6** | Migrer les candidatures de la P13 actuelle ? | **Non, bascule franche P14** — démarrer la nouvelle plateforme directement avec la campagne 2026, sans migration des dossiers P13 |

## 12. Plan d'implémentation V1 ajusté

Avec ces décisions actées, l'implémentation du module 5 devient plus simple que ma spec initiale :

**Effort ajusté** : ~3-4 jours de dev backend + frontend pour la V1 fonctionnelle (vs ~6-7 jours pour ma spec sur-conçue avec 3 vœux et 6 pièces jointes).

**Découpage des tâches** :

1. Migration BDD `candidatures` (1 table simple) + tables référentielles seedées (pays, régions, départements).
2. Backend Laravel : Service `CandidatureService`, Controller API `/v1/applications/*`, FormRequest validation, génération numéro atomique.
3. Frontend Next.js : 1 page wizard 4 étapes avec auto-save, page récap, page suivi, login simple.
4. PDF récépissé Laravel avec filigrane et QR code.
5. Filament Resource `CandidatureResource` avec filtres, modification statut, dashboard quotas régionaux.
6. Email confirmation à la soumission.

## 13. Action items immédiats

- [ ] Anatole valide les 6 décisions D1-D6.
- [ ] Mise à jour de la spec `docs/specs/module-5-candidatures.md` avec ces décisions (en cours, fait dans la foulée).
- [ ] Mise à jour du `data-model.md` pour la table `candidatures` simplifiée.
- [ ] Préparer un seeder de référence avec les 200+ pays, 11 régions, 58 départements depuis le dump SQL existant.
- [ ] Confirmer si la **base de dev candidature_p13.sql** contient des candidats réels à migrer ou si c'est un dump de test (1 candidat fictif Kouedi visible).
- [ ] Compresser les assets `bg*.jpg` (~17 MB total → cible ~1.5 MB en WebP) pour réutilisation frontend.

## Annexe — Statistiques du code source

| Fichier | Lignes | Notes |
|---|---|---|
| formulaire.php | ~30 000 octets | Formulaire HTML + JS multi-étapes |
| recapitulatif.php | ~30 000 octets | Page récap candidat |
| dashboard.php | ~26 000 octets | Dashboard admin avec compteurs et liste |
| telecharger_pdf.php | ~11 000 octets | Génération PDF dompdf avec filigrane |
| login.php | ~10 000 octets | Auth + UI login |
| préinscription.php | ~11 000 octets | Page intermédiaire explicative |
| submit.php | ~5 500 octets | Insertion BDD + création user |
| update_candidate.php | ~4 200 octets | Édition admin |
| envoyer_mail.php | ~3 800 octets | Email confirmation (incomplet) |
| save_first_step.php | ~3 100 octets | Auto-save étape 1 (AJAX) |

Codes plutôt verbeux (HTML+PHP+JS mélangés) — typique d'une approche legacy non séparée. La réécriture Next.js + Laravel API séparera proprement présentation et logique.
