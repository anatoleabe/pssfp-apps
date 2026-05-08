# Spec — Module 6 — CMS Administration (Filament 3)

> **Référence** : Sprint Specs A8
> **Statut** : v1.0 — proposé pour validation Chef USI
> **Date** : 2026-05-05
> **Source CDC** : §8.1 Module 6 du CDC v5 et CDC bibliothèque v2.0 §E.3

Ce document spécifie l'interface d'administration Laravel Filament 3 — point d'entrée unique pour la gestion autonome du contenu par l'équipe USI sans intervention développeur (CDC §15.2). Le CMS est l'arme principale de l'autonomie opérationnelle du PSSFP — sans lui, chaque modification de contenu nécessite un développeur et le projet échoue son objectif d'autonomie.

## 1. Architecture

URL : `https://api.pssfp.net/admin`. Sessions navigateur Laravel avec 2FA TOTP obligatoire pour les rôles admin/editor/librarian/admission_committee (cf. ADR-0005).

Filament panel unique nommé `admin`. Configuration dans `backend/app/Providers/Filament/AdminPanelProvider.php`. Authentification via `Filament\Pages\Auth\Login` étendu pour exiger 2FA.

Branding : logo PSSFP en haut, palette CDC (violet `#6B2FA0` primary), favicon. Brand name « PSSFP CMS ».

Navigation organisée en groupes : `Tableau de bord`, `Contenus`, `Catalogue Formations`, `Vie Académique`, `Bibliothèque`, `Candidatures`, `Utilisateurs`, `Paramètres`.

## 2. Rôles et permissions (Spatie Permission)

Six rôles applicatifs définis (cf. ADR-0004 et data-model.md §3) :

### `admin`

Super-admin technique. Réservé au Chef USI et à un binôme de secours. Accès total : toutes les Resources, gestion users + rôles, paramètres applicatifs, logs d'activité, accès aux tables de configuration sensibles. Habilité à exporter et purger les données.

### `editor`

Gestionnaire éditorial. Accès aux Resources Pages, Articles, Categories, Tags, Media, Evenements, Promotions, Enseignants, Partenaires. Peut publier sans validation. Ne touche ni aux candidatures ni à la biblio (sauf consultation lecture seule).

### `librarian`

Bibliothécaire. Accès complet à Documents, Mots-clés, Auteurs documents, Téléchargements (analytics). Lecture seule sur les pages, articles, formations. Pas d'accès aux candidatures.

### `admission_committee`

Membre du comité d'admission. Accès lecture/écriture sur Candidatures (review, comment, accept/reject), Campagnes (consultation), Candidate Profiles (consultation). Pas d'accès aux pages, articles, biblio (sauf consultation lecture seule pour valider l'éligibilité d'un candidat).

### `teacher`

Enseignant. Pas d'accès Filament direct en V1 (les enseignants utilisent l'API publique pour déposer des ressources biblio). Le rôle est créé pour Phase II quand l'espace enseignant émergera.

### `auditor`

Auditeur. Pas d'accès Filament. Existence du rôle pour les API Sanctum.

### Permissions atomiques

Chaque Resource Filament génère 5 permissions Spatie via `Filament Shield` ou équivalent : `viewAny`, `view`, `create`, `update`, `delete`. Plus des permissions d'action métier : `articles.publish`, `documents.publish`, `candidatures.review`, `candidatures.accept`, `candidatures.reject`, `users.manage_roles`, `settings.update`, etc.

Matrice de permissions seedée via `RolePermissionSeeder` au bootstrap. Modifiable ensuite via Filament Resource « Rôles » accessible aux admins seulement.

## 3. Liste des Filament Resources

Une Resource Filament = un modèle Eloquent + ses pages liste/création/édition/visualisation.

### 3.1 Domaine Contenus

**`PageResource`** — gestion des pages institutionnelles.

- Form : champs titre (Markdown éditeur), excerpt, body (Markdown ou Rich Text Editor TinyMCE configurable), parent (Select avec arborescence), slug (auto-généré, modifiable), meta_title, meta_description, og_image (Spatie Media), status, published_at, order, is_in_menu, menu_label.
- Table : title, slug, parent, status badge, published_at, updated_at. Filtres : status, parent, is_in_menu. Action « Publier ».
- Translatable : champs avec onglets FR/EN/AR via plugin filament-translatable-fields (FR obligatoire, autres optionnels).

**`ArticleResource`** — articles d'actualités.

- Form : title, excerpt, body (RTE), featured_image (Media), category (Select), tags (multi-select avec création inline), author (Select restreint aux users avec rôle editor), status, published_at, is_pinned, meta_*.
- Table : title, category badge, author, status, published_at, views_count. Filtres : category, author, tag, status, période. Action « Publier maintenant », « Programmer publication ».
- Bulk action : publier ou archiver une sélection.

**`CategoryResource`** — taxonomie articles.

- Form simple : name, slug, description, color (color picker), icon (Lucide picker), parent (auto-référence), order.
- Table : name, slug, articles_count, color preview. Tri par order.

**`TagResource`** — tags articles.

- Form minimaliste : name, slug.
- Table : name, slug, articles_count, créé. Action « fusionner deux tags » (déplace tous les articles du tag B vers A puis supprime B).

**`MediaResource`** — médiathèque centralisée. En réalité Filament expose les médias par Resource (chaque Resource a son `SpatieMediaLibraryFileUpload`). Une page « Médiathèque » globale permet de naviguer/rechercher/réutiliser des médias existants. Plugin `filament-spatie-media-library-plugin`.

### 3.2 Domaine Catalogue Formations

**`SpecialiteResource`** — les 5 spécialités du Master.

- Form : code, slug, nom (TR), nom_court (TR), description_courte (TR), description_longue (TR Markdown), debouches (Repeater de strings TR), color, icon, illustration (Media), order, is_active, meta_*.
- Table : nom, code, slug, ue_count (calculé), enseignants_count, is_active, order.

**`UniteEnseignementResource`** — UE par spécialité ou tronc commun.

- Form : specialite (Select, nullable = tronc commun), semestre (1-4), code, intitulé (TR), description (TR), volume_horaire, credits_ects, order.
- Table : code, intitulé, specialité (ou « Tronc commun »), semestre, volume, credits.
- Filtre : par spécialité, par semestre.

**`FormationContinueResource`** — 10 modules de formation continue.

- Form : numero, slug, intitulé, description, cibles (Repeater), objectifs (Repeater), programme (Markdown), duree_jours, tarifs (administration / individu / auditeur), illustration, is_active, order.
- Table : numero, intitulé, durée, tarifs, is_active.

**`CertificationResource`** — certifications + voyages d'étude.

- Form : type (cert/voyage), nom, partenaire (Select), description, modalité, durée, illustration, is_active.
- Table : nom, type, partenaire, modalité.

### 3.3 Domaine Vie Académique

**`PromotionResource`** — 13 promotions.

- Form : numero (unique), annee_debut, annee_fin, status, nombre_etudiants, description, photo_groupe.
- Table : numero, années, status, nombre_etudiants, photo_thumb.
- Action : « Lier les thèses/mémoires de cette promotion » (raccourci vers la liste filtrée biblio).

**`EnseignantResource`** — corps enseignant.

- Form : civilite, prenom, nom, slug, grade, qualifications (TR), domaines (Repeater TR), bio (Markdown TR), email, phone, photo, chef_de_departement_specialite, ordre_affichage, is_visible. Relation belongsToMany Specialites via RelationManager (avec champ `role` dans le pivot).
- Table : photo, nom complet, grade, spécialités (badges), is_visible. Filtres : par spécialité, chef département.

**`EvenementResource`** — calendrier.

- Form : titre, slug, description (Markdown TR), type, starts_at, ends_at, all_day (toggle), location, lat, lng, illustration, is_public.
- Table : titre, type badge, dates, lieu, is_public.
- Action : exporter agenda en .ics.

### 3.4 Domaine Partenaires

**`PartenaireResource`** — liste partenaires.

- Form : slug, nom, type, description (Markdown TR), logo (Media), site_url, convention_pdf (Media), order, is_featured, is_active.
- Table : logo thumb, nom, type, is_featured, is_active.

### 3.5 Domaine Bibliothèque

**`DocumentResource`** — cœur de la biblio. La Resource la plus riche en fonctionnalités.

- Form : type (Select avec icônes), title (TR), subtitle (TR), abstract (TR), keywords (multi-select avec création inline), year, language, promotion (Select), specialite (Select nullable), journal, doi, isbn, pages_count, file (Spatie Media PDF, max 50 MB), thumbnail, access_level, status. Authors via RelationManager — un Repeater avec choix entre `enseignant_id`, `user_id`, ou `author_name + author_affiliation`, avec `order`.
- Table : type icon, title, year, specialite, promotion, access_level badge (couleur), status, downloads_count, views_count.
- Filtres : type, specialite, promotion, access_level, status, année (range), language.
- Actions : « Publier », « Archiver », « Régénérer index Meilisearch », « Lancer OCR » (si scanné), « Vérifier intégrité MD5 », « Générer thumbnail première page ».
- Bulk : publier, archiver, exporter CSV avec métadonnées.

**`MotCleResource`** — thésaurus.

- Form : slug, label (TR), parent (auto-référence pour hiérarchie thésaurus).
- Table : label, slug, documents_count.

**`DocumentDownloadResource`** — analytics téléchargements (lecture seule).

- Table : document, user (ou anonyme), ip, downloaded_at. Filtres : document, période. Action : « Exporter CSV ».
- Vue dashboard : top 20 documents les plus téléchargés (chart Recharts).

### 3.6 Domaine Candidatures

**`CampagneCandidatureResource`** — campagnes annuelles.

- Form : slug, nom, description, promotion, opens_at, closes_at, results_at, status, frais_candidature, max_voeux, pieces_requises (Repeater).
- Table : nom, période, status badge, candidatures_count, accepted_count.
- Action : « Ouvrir », « Clore », « Publier résultats », « Exporter dossiers CSV ».

**`CandidatureResource`** — dossiers de candidature.

- Form (lecture seule pour le comité, complet pour admin) : numero, candidat (lien vers candidate_profile), campagne, vœux (3 selects spécialités), projet professionnel, motivation, status, frais_paiement_*, documents (RelationManager visualisation), commentaires (RelationManager textarea), historique status.
- Table : numero, candidat (nom + email), campagne, vœu 1, status, submitted_at, frais_paye, documents_count. Tri : submitted_at desc par défaut.
- Filtres : campagne, status, vœu 1, frais_paye, période submitted_at, comité reviewer.
- Actions : « Examiner » (passe en under_review), « Demander complément », « Accepter » (avec confirmation et notification), « Refuser » (avec motif obligatoire), « Marquer frais payés » (formulaire avec mode/référence/date), « Télécharger récépissé », « Télécharger toutes les pièces ZIP ».
- Bulk : exporter sélection en CSV pour le comité.
- Vue dashboard : nombre de dossiers par status pour la campagne courante.

**`CandidateProfileResource`** — profils candidats (lecture, édition admin uniquement pour corrections exceptionnelles).

- Table : nom complet, email, candidatures_count, completeness%.

### 3.7 Domaine Utilisateurs

**`UserResource`** — comptes utilisateurs.

- Form : email, first_name, last_name, phone, avatar, roles (multi-select restreint), is_active, two_factor_enabled (lecture seule, info), email_verified (toggle pour forcer si nécessaire). Pas de saisie de mot de passe directe — action « Envoyer lien de réinitialisation ».
- Table : email, nom, roles (badges), email_verified, last_login_at, is_active.
- Actions : « Bloquer compte », « Forcer déconnexion (révoquer tous les tokens) », « Envoyer lien réinitialisation ».
- Restreint au rôle `admin` uniquement.

**`RoleResource`** — gestion des rôles Spatie.

- Form : name, guard, permissions (multi-select).
- Réservé `admin`. Modification rare — la matrice initiale est seedée et ne change qu'en cas de besoin réel.

### 3.8 Domaine Misc

**`ContactMessageResource`** — messages reçus formulaire.

- Table : name, email, subject, message preview, handled_at. Action « Marquer traité ».

**`SettingsResource`** — paramètres applicatifs.

- Pages dédiées Filament Pages (pas Resources) groupées par catégorie : « Site », « Contact », « SEO », « Réseaux sociaux ». Formulaire avec les clés/valeurs `settings` détaillées dans api-contract.md §11.

**`RedirectResource`** — redirections 301.

- Form : from_path, to_path, status_code, host.
- Table : from, to, status, hits_count, last_hit_at.

## 4. Dashboard d'accueil Filament

Page d'accueil après login présente des **widgets** par rôle :

**Pour `admin`** :

- Compteurs : pages publiées, articles publiés (30j), documents biblio, utilisateurs actifs, candidatures soumises (30j).
- Chart Recharts : trafic articles 30j (vues / publications).
- Liste : 5 derniers messages contact non traités.
- Liste : 5 dernières actions Activity Log significatives.
- Healthcheck simplifié : status BDD, Redis, Meilisearch, MinIO, dernier backup.

**Pour `editor`** :

- Mes articles brouillon, en relecture, publiés (30j).
- Articles à relire (de mes pairs).
- Statistiques de mes articles : vues totales, articles populaires.

**Pour `librarian`** :

- Documents en attente de validation.
- Top 10 documents les plus téléchargés ce mois.
- Recherches sans résultats (pour enrichir le thésaurus).
- Documents avec OCR à lancer.

**Pour `admission_committee`** :

- Dossiers de la campagne en cours par status.
- Mes dossiers à examiner (assignés ou non assignés au comité).
- Statistiques de la campagne : taux soumission, taux complétude.

## 5. Activity Log et audit

Chaque création / modification / suppression sur les Resources sensibles est tracée dans `activity_log` via `spatie/laravel-activitylog`. Page Filament dédiée `Audit` accessible aux admins, montre :

- Auteur de l'action.
- Type d'action (created, updated, deleted, restored).
- Modèle et ID concerné.
- Diff des changements (champs modifiés avec ancienne/nouvelle valeur).
- Date.
- IP source.

Filtres : auteur, type, modèle, période. Export CSV pour audit externe.

## 6. Filament et i18n

Plugin `filament-translatable-fields` pour les champs JSONB. Chaque champ traduisible affiche des onglets FR/EN/AR. FR obligatoire à la sauvegarde, EN/AR optionnels.

Interface CMS reste **en français** quoi qu'il arrive — l'équipe USI travaille en français. Pas de traduction de l'UI Filament en V1.

## 7. Manuel utilisateur USI

Livrable obligatoire au lancement (CDC §12.4) : un PDF illustré « Manuel d'administration du site PSSFP » à destination de l'équipe USI. Couvre :

- Connexion et 2FA.
- Création d'un article (étape par étape, captures d'écran).
- Publication d'une page institutionnelle.
- Ajout d'un document à la bibliothèque (avec OCR si scanné).
- Examen d'une candidature et processus de décision.
- Gestion des médias (upload, recadrage, formats acceptés).
- Bonnes pratiques SEO (meta_title, meta_description, alt texts).
- Erreurs fréquentes et comment les éviter.

À rédiger en Phase 8 (Juillet S1) lors de la formation USI 2 jours. Format : Word puis export PDF, ~30 pages.

## 8. Critères d'acceptation Module 6

- 2FA TOTP obligatoire pour admin/editor/librarian/admission_committee — testé avec une app authenticator.
- Toutes les Resources listées en §3 fonctionnelles.
- Workflow complet article (draft → in_review → published) testé avec 2 comptes (editor + admin).
- Workflow complet candidature (submitted → under_review → accepted) testé avec un dossier réel.
- Activity Log capture toutes les actions mutantes — vérification en revue.
- Dashboard d'accueil affiche correctement les widgets par rôle.
- Manuel utilisateur livré en PDF, validé par M. ABE ETOUMOU.
- Formation USI 2 jours réalisée et signée.
- Tests Pest sur les Filament Resources : `tests/Feature/Filament/...Test.php` — au moins le smoke test « la page liste de la Resource s'affiche pour un user avec rôle X et n'affiche pas pour un user avec rôle Y ».

## 9. Plugins Filament à installer

- `filament/spatie-laravel-media-library-plugin` — médiathèque.
- `filament/spatie-laravel-translatable-plugin` — champs traduisibles.
- `filament/spatie-laravel-activitylog-plugin` — audit log.
- `bezhansalleh/filament-shield` — gestion des permissions Spatie via interface.
- `awcodes/filament-tiptap-editor` — éditeur Markdown/RTE alternative.
- `awcodes/filament-quick-create` — bouton de création rapide en haut.
- `pxlrbt/filament-excel` — exports Excel.

À compléter au fil du dev selon besoins. Tous open source, gratuits, maintenus.

## Annexe — Exemple de Resource Filament squelette

Pour `SpecialiteResource`, structure du fichier `backend/app/Filament/Resources/SpecialiteResource.php` :

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SpecialiteResource\Pages;
use App\Models\Specialite;
use Filament\Resources\Resource;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput as Text;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class SpecialiteResource extends Resource
{
    protected static ?string $model = Specialite::class;
    protected static ?string $navigationGroup = 'Catalogue Formations';
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('code')->required()->unique(ignoreRecord: true),
            TextInput::make('nom')->required()->translatable(['fr', 'en', 'ar']),
            TextInput::make('nom_court')->required()->translatable(['fr', 'en', 'ar']),
            Textarea::make('description_courte')->translatable(),
            // Markdown editor pour description_longue
            ColorPicker::make('color'),
            // ... etc
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('nom')->searchable(),
            TextColumn::make('code'),
            TextColumn::make('unitesEnseignement_count')->counts('unitesEnseignement')->label('UE'),
            IconColumn::make('is_active')->boolean(),
        ])->defaultSort('order');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpecialites::route('/'),
            'create' => Pages\CreateSpecialite::route('/create'),
            'edit' => Pages\EditSpecialite::route('/{record}/edit'),
        ];
    }
}
```

Code donné à titre indicatif — Claude Code l'enrichira lors de la phase de dev.
