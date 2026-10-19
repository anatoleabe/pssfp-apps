# Ajouter un module au back-office

Le back-office Filament (`/admin`) est cloisonné par module : un compte n'ouvre que les écrans des modules sur lesquels l'un de ses rôles porte au moins une permission. Un rôle de la bibliothèque seul ne voit donc ni le site, ni les admissions, ni l'administration ; cumuler deux rôles additionne leurs modules.

Trois pièces portent ce cloisonnement :

- `App\Support\ModulePermissionMap::modules()` : la carte des modules (permissions de lecture, de gestion, actions sensibles, actions autonomes). C'est la seule source de vérité, partagée par l'écran Rôles et par `RolePermissionSeeder`.
- `#[BelongsToModule('…')]` (`App\Support\BelongsToModule`) : chaque écran du panneau (Resource, Page, Widget) déclare son module, ou `core` pour le socle commun (tableau de bord, profil).
- `App\Support\ModuleAccess` : le point de contrôle unique. Un écran s'ouvre si l'utilisateur porte au moins une permission du module (lecture, gestion ou action sensible, autonomes comprises). Ce contrôle s'ajoute aux Policies, il ne les remplace pas.

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

`tests/Feature/Filament/ModuleIsolationTest.php` parcourt tous les écrans enregistrés dans le panneau. Il échoue si un écran ne déclare pas de module, déclare un module inconnu, n'utilise pas le trait, ou reste ouvert à un compte sans droit sur son module. Un écran fourni par Filament lui-même (qu'on ne peut pas annoter) se rattache au socle dans `ModuleAccess::FRAMEWORK_SCREENS`, et uniquement au socle.

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
