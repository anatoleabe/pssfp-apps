# Ajouter un module au back-office

Le back-office Filament (`/admin`) est cloisonné par module : un compte n'ouvre que les écrans des modules sur lesquels l'un de ses rôles porte au moins une permission. Un rôle de la bibliothèque seul ne voit donc ni le site, ni les admissions, ni l'administration ; cumuler deux rôles additionne leurs modules.

Trois pièces portent ce cloisonnement :

- `App\Support\ModulePermissionMap::modules()` : la carte des modules (permissions de lecture, de gestion, actions sensibles, actions autonomes). C'est la seule source de vérité, partagée par l'écran Rôles et par `RolePermissionSeeder`.
- `#[BelongsToModule('…')]` (`App\Support\BelongsToModule`) : chaque écran du panneau (Resource, Page, Widget, gestionnaire de relation, widget d'en-tête ou de pied de page) déclare son module, ou `core` pour le socle commun (tableau de bord, profil).
- `App\Support\ModuleAccess` : le point de contrôle unique. Un écran s'ouvre si l'utilisateur porte au moins une permission qui ouvre le module : lecture, gestion ou action autonome (`ModulePermissionMap::accessPermissions()`). Une action sensible ordinaire seule (ex. `candidature.export_csv` posée à la main) n'ouvre rien. Ce contrôle s'ajoute aux Policies, il ne les remplace pas.

Exception : `super_admin` ouvre tous les modules sans condition. En prod il doit quand même recevoir explicitement chaque nouvelle permission (les Policies, elles, n'ont pas de contournement) ; le raccourci évite seulement qu'une permission oubliée lui ferme un module entier.

## Étapes

### 1. Déclarer le module dans la carte

Ajouter une entrée dans `ModulePermissionMap::modules()` :

```php
'scolarite' => [
    'label' => 'Module 7 — Scolarité',
    'description' => 'Inscriptions administratives et attestations.',
    'read' => ['view_any_inscription', 'view_inscription'],
    'manage' => ['create_inscription', 'update_inscription', 'delete_inscription'],
    'sensitive_description' => 'À cocher explicitement.',
    'sensitive' => [
        'inscription.sign' => 'Signer une attestation',
    ],
    // Facultatif : actions valables même au niveau « Aucun accès »
    // (ex. un compte qui ne fait que déposer).
    'standalone' => [],
],
```

L'écran Rôles affiche aussitôt le nouveau module (niveau aucun / lecture / gestion, actions sensibles). La clé (`scolarite`) est aussi le nom de module que déclarent les écrans.

### 2. Déclarer le module de chaque écran

Resource ou Page :

```php
use App\Filament\Concerns\RestrictedToModule;
use App\Support\BelongsToModule;

#[BelongsToModule('scolarite')]
class InscriptionResource extends Resource
{
    use RestrictedToModule; // canAccess() = module ouvert ET Policy

    // …
}
```

Si la Page définit déjà son propre `canAccess()`, utiliser `ChecksModuleAccess` et écrire `return static::moduleAllows() && …;`.

Widget :

```php
use App\Filament\Concerns\ChecksModuleAccess;
use App\Support\BelongsToModule;

#[BelongsToModule('scolarite')]
class InscriptionsWidget extends StatsOverviewWidget
{
    use ChecksModuleAccess;

    public static function canView(): bool
    {
        return static::moduleAllows() && (auth()->user()?->can('view_any_inscription') ?? false);
    }
}
```

La visibilité d'un widget se fonde sur des **permissions** (`can('…')`), jamais sur des noms de rôles (`hasRole()` / `hasAnyRole()`) : un rôle créé depuis l'écran Rôles doit voir les widgets que ses droits justifient. Choisir la permission du métier du widget (ex. `candidature.mark_depot_physique` pour le suivi des dépôts au guichet, `update_candidature` pour les quotas régionaux). `ModuleIsolationTest` échoue si un widget enregistré teste un nom de rôle.

Gestionnaire de relation (`RelationManager`) : il déclare le **module de sa Resource**, utilise `ChecksModuleAccess` et redéfinit `canViewForRecord()` en appelant `static::moduleAllows()` :

```php
#[BelongsToModule('scolarite')]
class AttestationsRelationManager extends RelationManager
{
    use ChecksModuleAccess;

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return static::moduleAllows() && parent::canViewForRecord($ownerRecord, $pageClass);
    }
}
```

Les widgets affichés en en-tête ou en pied d'une page de Resource (`getHeaderWidgets()` / `getFooterWidgets()`) ne sont pas enregistrés dans le panneau et échappent donc au test ci-dessous : ils doivent eux aussi déclarer leur module, passer par `ChecksModuleAccess` dans `canView()` et se fonder sur des permissions.

`tests/Feature/Filament/ModuleIsolationTest.php` parcourt les Resources, Pages et Widgets enregistrés dans le panneau (`Filament::getPanel('admin')`). Il échoue si l'un d'eux :

- ne déclare pas de module, ou déclare un module inconnu ;
- n'utilise pas `ChecksModuleAccess` (directement ou via `RestrictedToModule`) ;
- a un `canAccess()` (Resource, Page) ou un `canView()` (Widget) qui n'est ni celui du trait, ni une redéfinition appelant `moduleAllows()` ;
- (gestionnaire de relation d'une Resource enregistrée) ne déclare pas le module de sa Resource, ou son `canViewForRecord()` n'appelle pas `moduleAllows()` ;
- (widget) teste un nom de rôle ;
- reste ouvert à un compte `admin` privé de la lecture, de la gestion et des actions autonomes de son module.

Un écran fourni par Filament lui-même (qu'on ne peut pas annoter) se rattache au socle dans `ModuleAccess::FRAMEWORK_SCREENS`, et uniquement au socle.

### 3. Livrer les permissions et les rôles par migration additive

Ne jamais relancer `RolePermissionSeeder` en prod : `syncPermissions` écraserait les réglages faits depuis l'écran Rôles. Écrire une migration sur le modèle de `2026_10_19_200000_library_admin_roles.php` :

- listes de permissions et de rôles **figées** dans la migration (pas de lecture de `ModulePermissionMap`) ;
- `app(PermissionRegistrar::class)->forgetCachedPermissions()` avant et après ;
- `Permission::findOrCreate()`, puis `givePermissionTo()` (jamais `syncPermissions()`) ;
- accorder explicitement les nouvelles permissions à `super_admin` (il n'a pas de contournement de Gate) et, au besoin, à `admin` ;
- si un droit passe d'un rôle à un autre, donner d'abord le nouveau rôle aux titulaires, retirer ensuite : personne ne perd un droit qu'il exerce ;
- `down()` réversible, et testé.

Mettre à jour `RolePermissionSeeder` en parallèle pour les installations neuves, avec les mêmes libellés et descriptions. Un test compare l'état produit par la migration et par le seeder (cf. `tests/Feature/Library/LibraryAdminRolesMigrationTest.php`).

### 4. Compléter les tests

- Ajouter les nouveaux rôles à `isolationSeededRoles()` dans `ModuleIsolationTest` (un test vérifie que la liste suit le seeder).
- Policies et écrans du module : tests Pest habituels, un utilisateur par rôle via `User::factory()->create()->assignRole(...)`.

### 5. Déployer

Après `php artisan migrate --force` en prod :

1. `php artisan permission:cache-reset` — **obligatoire**. La migration vide le cache Spatie avant et après ses écritures, mais une requête servie pendant qu'elle tourne peut le remplir à nouveau avec l'ancien état avant que sa transaction soit validée : sans ce vidage, des comptes gardent des droits périmés jusqu'à l'expiration du cache (24 h).
2. `php artisan optimize` — reconstruit les caches de configuration et de routes, et lance `filament:optimize` (cache des composants et des icônes Filament) : sans lui, un écran ou un widget ajouté n'apparaît pas, ou un écran supprimé reste référencé.
3. Redémarrer le worker de file (`php artisan queue:restart` ; le service systemd `pssfp-queue` le relance) : il garde en mémoire l'ancien code.

**Changement de signature d'un job** (propriétés, paramètres du constructeur) : vider la file avant de déployer (laisser le worker traiter les jobs en attente, `php artisan queue:monitor` / longueur de la file Redis à zéro), car un job sérialisé par l'ancien code est désérialisé par le nouveau. À défaut, toute nouvelle propriété doit être une propriété classique avec valeur par défaut, jamais une propriété promue `readonly` sans défaut (elle resterait non initialisée ; cf. `ProcessDocumentFile::$forceCover`).
