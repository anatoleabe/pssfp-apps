# Inventaire des assets importés (PR V — Sprint S5)

> **Source** : `assets-source/` (gitignored)
> **Cible** : MinIO buckets `pssfp-media` (public) et `pssfp-documents` (privé)
> **Commande** : `php artisan pssfp:import-assets`
> **Mise à jour** : 2026-05-10 (premier import)
> **Total importé** : 67 / 249 (cf. limitations §3)

## 1. Comment ré-importer / ajouter de nouveaux assets

### Workflow standard

1. Déposer les fichiers dans `assets-source/<categorie>/<sous-categorie>/`
   ```
   assets-source/
   ├── logos/
   │   ├── pssfp.svg
   │   ├── partenaires/
   │   └── institutions-coop/
   ├── photos/
   │   ├── campus/
   │   ├── amphis/
   │   ├── salles/
   │   ├── bibliotheque/
   │   ├── cours/
   │   ├── direction/
   │   ├── enseignants/
   │   ├── promotions/
   │   ├── soutenances/      ← peut contenir P{n}/ pour tag promo
   │   ├── seminaires/
   │   └── voyages/
   ├── conventions/          → document/convention
   ├── Catalogues/           → document/catalogue
   └── textes/Appel*/        → document/appel-candidature
   ```
2. `cd backend && php artisan pssfp:import-assets`
3. Vérifier le rapport (Importés / Ignorés / Erreurs).
4. Re-runs idempotents : les assets déjà présents (même `disk`+`path`) sont skippés.

### Mode dry-run (sans upload)

```bash
php artisan pssfp:import-assets --dry-run
```

Liste les fichiers détectés sans uploader.

### Source custom

```bash
php artisan pssfp:import-assets --source=/chemin/vers/dossier
```

## 2. Variantes images générées

Pour chaque JPG/PNG importé en catégorie `photo`, le service génère 3 variantes WebP qualité 85 :

| Variante | Largeur max | Usage typique |
|---|---|---|
| `thumb`  |  400 px | Vignettes, listes, grilles compactes |
| `medium` | 1200 px | Cards, hero secondaires, galeries |
| `full`   | 1920 px | Hero principal, fullscreen |

Les SVG, WebP et PDF sont uploadés tels quels (pas de re-encoding).

Les chemins des variantes sont stockés en JSON dans la colonne `assets.variants` :

```json
{ "thumb": "photos/direction/basahag-thumb.webp",
  "medium": "photos/direction/basahag-medium.webp",
  "full": "photos/direction/basahag-full.webp" }
```

Et exposés via `Asset::variantUrl('thumb')` côté backend.

## 3. Limitation connue : macOS quarantine bloque ~70 % des photos

### Symptôme

Lors du premier import, **180 fichiers / 249 ont échoué** avec l'erreur :

```
Operation not permitted (file_get_contents …)
```

### Cause

Les fichiers ont été extraits via Keka depuis une archive téléchargée. macOS leur applique l'attribut `com.apple.quarantine` qui bloque l'accès aux processus shell sandboxés (typiquement la Bash de Claude Code).

### Fix manuel (à exécuter depuis Terminal.app, pas depuis Claude Code)

```bash
cd /Users/anatole-savics/Documents/PS/03_PROJETS/SITE\ WEB\ PSSFP/pssfp
xattr -rd com.apple.quarantine assets-source/
```

Puis ré-importer :

```bash
cd backend && php artisan pssfp:import-assets
```

Le script est idempotent : les 67 déjà importés seront skippés, seuls les 180 manquants seront uploadés.

### Conséquence pour PR Y (hero showcase)

Les slides 1 (Campus Messa) et 3 (Centre Pasteur) ne pourront pas utiliser les photos prévues tant que la quarantine n'est pas levée. **Fallback à utiliser** :
- Slide 1 : photo `dsc-0538.webp` (sortie solennelle promo 6) ou un visuel composite généré.
- Slide 3 : photo `affiche.webp` (banderole P13) ou pictogramme.

Les sélections finales de slides seront ajustées dans la PR description PR Y et soumises à validation Anatole.

## 4. Assets importés (67)

### Logos (17)

| Fichier | Sous-catégorie | Tag | Disk | Path |
|---|---|---|---|---|
| pssfp.svg | pssfp | pssfp | minio_media | logos/pssfp.svg |
| pssfp.png | pssfp | pssfp | minio_media | logos/pssfp.png |
| logo-…-pssfp-removebg-preview-1.svg | pssfp | pssfp | minio_media | logos/logo-…-1.svg |
| logo-…-pssfp.jpg | pssfp | pssfp | minio_media | logos/logo-…-pssfp.jpg |
| minfi.svg / minfi.png / minfi-removebg-preview.png | institution-coop | minfi | minio_media | logos/institutions-coop/ |
| logo-minesup.jpg / logo-minesup-removebg-preview.png | institution-coop | minesup | minio_media | logos/institutions-coop/ |
| uy2.jpg / uy2-removebg-preview.png | institution-coop | uy2 | minio_media | logos/institutions-coop/ |
| expertise-france.png / expertise-france-removebg-preview.png | institution-coop | expertise-france | minio_media | logos/institutions-coop/ |
| fmi-logo.jpg / fmi-logo-removebg-preview.png | institution-coop | fmi | minio_media | logos/institutions-coop/ |
| mef-maroc.png | institution-coop | institut-finances-maroc | minio_media | logos/institutions-coop/ |
| assemble-nationale-cameroun.png | institution-coop | assemblee-nationale | minio_media | logos/institutions-coop/ |

### Documents (10)

| Fichier | Sous-catégorie | Tag | Path (minio_documents) |
|---|---|---|---|
| catalogue-pssfp-2026.pdf | catalogue | catalogue, formation-continue | documents/catalogue-pssfp-2026.pdf |
| convention-tripartite-2013.pdf | convention | convention | conventions/convention-tripartite-2013.pdf |
| convention-pssfp-minfi-minesup-en-francais.pdf | convention | convention | conventions/convention-pssfp-minfi-minesup-en-francais.pdf |
| appel-candidature-pssfp-2024-2025-fr.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| appel-candidature-pssfp-2024-2025-en.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| codes-acces-12promo12.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| codes-acces-promo12-complet.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| informations-dacces-aux-plateformes-de-formation-promo-12.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| liste-des-candidats-retenues-12eme-…-matriculexlsx-google-sheets.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |
| resultat-appel-a-candidatures-promo12-2024-2025.pdf | appel-candidature | appel-candidature, promo-12 | documents/appels-candidature/ |

### Photos (40)

| Sous-catégorie | Compte | Tags représentatifs |
|---|---|---|
| direction | 5 | (sans tag — Pr. BASAHAG, Louis Paul Motaze, Pr. Jacques Fame Ndongo) |
| evenements | 23 | sortie-solennelle+promo-6 (×7), 8-mars (×6), appel-candidature+promo-13 banderoles (×6), marche-sportive+promo-9 (×2), amicale (×2) |
| soutenances | 9 | promo-8 (×2), promo-10 (×3), promo-11 (×4) |
| autres | 3 | (à classifier) |

## 5. Assets MANQUANTS dans le DB (180, blocked par quarantine)

À débloquer via `xattr -rd com.apple.quarantine assets-source/` puis ré-import :

| Sous-catégorie attendue | Fichiers blockés | Impact |
|---|---|---|
| campus | 3 (Bâtiment PSSFP + 2 WhatsApp) | Slide 1 hero, hero accueil, page Campus Messa |
| amphis | ~3 | Page infrastructure |
| salles | ~3 | Page infrastructure |
| bibliotheque | ~5 | Page bibliothèque, illustration biblio virtuelle |
| cours | ~10 | Galerie vie académique |
| seminaires/Centre Pasteur | 7 | Slide 3 hero, article « Formation Centre Pasteur » (PR Z) |
| Soutenances (récentes) | ~50 | Galerie soutenances |
| voyages | ~15 | Slide 5 hero, article voyages |
| Sortie Solennelle/P7-8-9 | ~30 | Galerie diplômes |
| enseignants | ~10 | Page corps enseignant |

Total blocked ≈ 180 fichiers.

## 6. Browse / upload via Filament

Connect Filament admin sur `http://localhost:8000/admin` → groupe **Module 1 — Site institutionnel** → **Médias**.

Fonctionnalités :
- Liste avec aperçu thumbnail (variant `thumb` 400px), filtres par catégorie / sous-catégorie / tag.
- Bouton « Ouvrir » → ouvre l'asset dans un nouvel onglet (URL publique pour minio_media, URL signée 30 min pour minio_documents).
- Upload via formulaire : sélection catégorie → sous-catégorie → fichier (PDF, JPG, PNG, SVG, WebP, max 20 MB).
- Édition métadonnées (alt FR, description FR, tags) sans réuploader le fichier.

## 7. Modèle Eloquent `App\Models\Asset`

Scopes utiles :

```php
Asset::logos()->bySubcategory('partenaire')->get();
Asset::photos()->bySubcategory('direction')->get();
Asset::documents()->bySubcategory('catalogue')->first();
Asset::withTag('promo-12')->get();
```

URLs :

```php
$asset->publicUrl();             // null si disk privé
$asset->signedUrl(30);           // URL temporaire 30 min (pour docs privés)
$asset->variantUrl('thumb');     // 400px WebP
$asset->variantUrl('medium');    // 1200px WebP
$asset->variantUrl('full');      // 1920px WebP (ou taille originale si plus petite)
```
