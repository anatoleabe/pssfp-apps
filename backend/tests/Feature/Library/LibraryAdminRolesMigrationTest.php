<?php

declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses()->group('module-3', 'library');

/** @return list<string> */
function libraryAdminNewPermissions(): array
{
    return ['library.thesaurus', 'library.redirects', 'library.stats_export', 'document.contribute'];
}

/** @return list<string> */
function libraryAdminNewRoles(): array
{
    return ['library_manager', 'library_contributor', 'library_viewer', 'caisse'];
}

function libraryAdminRolesMigration(): object
{
    return require database_path('migrations/2026_10_19_200000_library_admin_roles.php');
}

/** @return list<string> */
function libraryAdminBiblioRead(): array
{
    return ['view_any_document', 'view_document', 'view_any_mot::cle', 'view_mot::cle'];
}

/** @return list<string> */
function libraryAdminBiblioManage(): array
{
    return [
        ...libraryAdminBiblioRead(),
        'create_document', 'update_document', 'delete_document',
        'create_mot::cle', 'update_mot::cle', 'delete_mot::cle',
    ];
}

/** @return list<string> */
function libraryAdminAdmissionsRead(): array
{
    return ['view_any_candidature', 'view_candidature', 'view_any_campagne::candidature', 'view_campagne::candidature'];
}

/**
 * État de prod avant la migration : rôles réglés à la main, un bibliothécaire
 * qui encaisse les frais de dossier. La migration a déjà tourné sur la base de
 * test vide : on la défait d'abord.
 */
function libraryAdminLegacyState(object $migration): User
{
    $migration->down();

    $custom = Permission::findOrCreate('view_any_user', 'web');
    foreach (['super_admin', 'admin'] as $name) {
        Role::findOrCreate($name, 'web')->syncPermissions([$custom, 'document.publish']);
    }
    Role::findOrCreate('editor', 'web')->syncPermissions([Permission::findOrCreate('view_any_page', 'web')]);

    $librarian = Role::findOrCreate('librarian', 'web');
    $librarian->forceFill(['label' => 'Bibliothécaire', 'description' => 'Bibliothèque virtuelle et encaissement des frais de dossier.'])->save();
    $librarian->syncPermissions([...libraryAdminBiblioManage(), 'document.publish', ...libraryAdminAdmissionsRead(), 'candidature.mark_paid']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $agent = User::factory()->create(['email' => 'bibliothecaire-legacy@pssfp.local']);
    $agent->assignRole('librarian');

    return $agent;
}

/** @return list<string> */
function libraryAdminPermissionsOf(string $role): array
{
    return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

/** @return array<string, array{label: ?string, description: ?string, permissions: list<string>}> */
function libraryAdminSnapshot(): array
{
    return collect(['super_admin', 'admin', 'librarian', ...libraryAdminNewRoles()])
        ->mapWithKeys(function (string $name): array {
            $role = Role::findByName($name, 'web');

            return [$name => [
                'label' => $role->label,
                'description' => $role->description,
                'permissions' => libraryAdminPermissionsOf($name),
            ]];
        })
        ->all();
}

/** @param  list<string>  $permissions */
function libraryAdminSorted(array $permissions): array
{
    return collect($permissions)->sort()->values()->all();
}

it('crée les permissions et les rôles de la bibliothèque', function (): void {
    $migration = libraryAdminRolesMigration();
    libraryAdminLegacyState($migration);

    $migration->up();

    foreach (libraryAdminNewPermissions() as $permission) {
        expect(Permission::query()->where('name', $permission)->where('guard_name', 'web')->count())->toBe(1);
    }

    $manager = Role::findByName('library_manager', 'web');
    expect($manager->label)->toBe('Responsable de la bibliothèque')
        ->and($manager->description)->not->toBeEmpty()
        ->and(libraryAdminPermissionsOf('library_manager'))->toBe(libraryAdminSorted([
            ...libraryAdminBiblioManage(), 'document.publish', 'library.thesaurus', 'library.redirects', 'library.stats_export',
        ]))
        ->and(Role::findByName('library_contributor', 'web')->label)->toBe('Contributeur bibliothèque')
        ->and(libraryAdminPermissionsOf('library_contributor'))->toBe(['document.contribute'])
        ->and(Role::findByName('library_viewer', 'web')->label)->toBe('Consultation bibliothèque')
        ->and(libraryAdminPermissionsOf('library_viewer'))->toBe(libraryAdminSorted([...libraryAdminBiblioRead(), 'library.stats_export']))
        ->and(Role::findByName('caisse', 'web')->label)->toBe('Caisse — frais de dossier')
        ->and(libraryAdminPermissionsOf('caisse'))->toBe(libraryAdminSorted([...libraryAdminAdmissionsRead(), 'candidature.mark_paid']));
});

it('donne la caisse aux bibliothécaires avant de retirer l\'encaissement de leur rôle', function (): void {
    $migration = libraryAdminRolesMigration();
    $agent = libraryAdminLegacyState($migration);
    expect($agent->can('candidature.mark_paid'))->toBeTrue();

    $migration->up();

    $agent = $agent->fresh();
    expect($agent->hasRole('caisse'))->toBeTrue()
        ->and($agent->hasRole('librarian'))->toBeTrue()
        ->and($agent->can('candidature.mark_paid'))->toBeTrue()
        ->and($agent->can('view_any_candidature'))->toBeTrue();

    $librarian = libraryAdminPermissionsOf('librarian');
    expect($librarian)->not->toContain('candidature.mark_paid')
        ->and(array_intersect($librarian, libraryAdminAdmissionsRead()))->toBe([])
        ->and($librarian)->toBe(libraryAdminSorted([...libraryAdminBiblioManage(), 'document.publish']))
        ->and(Role::findByName('librarian', 'web')->description)->not->toContain('encaissement');
});

it('accorde les nouvelles permissions à super_admin et admin sans toucher au reste', function (): void {
    $migration = libraryAdminRolesMigration();
    libraryAdminLegacyState($migration);

    $migration->up();

    foreach (['super_admin', 'admin'] as $name) {
        expect(libraryAdminPermissionsOf($name))->toBe(libraryAdminSorted([
            'view_any_user', 'document.publish', ...libraryAdminNewPermissions(),
        ]));
    }
    expect(libraryAdminPermissionsOf('editor'))->toBe(['view_any_page']);
});

it('ne duplique rien quand on la rejoue', function (): void {
    $migration = libraryAdminRolesMigration();
    $agent = libraryAdminLegacyState($migration);

    $migration->up();
    $once = libraryAdminSnapshot();
    $migration->up();

    expect(libraryAdminSnapshot())->toBe($once)
        ->and(Role::query()->whereIn('name', libraryAdminNewRoles())->count())->toBe(4)
        ->and(Permission::query()->whereIn('name', libraryAdminNewPermissions())->count())->toBe(4)
        ->and($agent->fresh()->roles->pluck('name')->sort()->values()->all())->toBe(['caisse', 'librarian']);
});

it('se défait en rendant l\'encaissement au rôle bibliothécaire', function (): void {
    $migration = libraryAdminRolesMigration();
    $agent = libraryAdminLegacyState($migration);
    $before = libraryAdminPermissionsOf('librarian');
    $description = Role::findByName('librarian', 'web')->description;

    $migration->up();
    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::query()->whereIn('name', libraryAdminNewRoles())->count())->toBe(0)
        ->and(Permission::query()->whereIn('name', libraryAdminNewPermissions())->count())->toBe(0)
        ->and(libraryAdminPermissionsOf('librarian'))->toBe($before)
        ->and(Role::findByName('librarian', 'web')->description)->toBe($description)
        ->and($agent->fresh()->can('candidature.mark_paid'))->toBeTrue();
});

it('laisse en place un rôle caisse qui existait avant elle', function (): void {
    $migration = libraryAdminRolesMigration();
    libraryAdminLegacyState($migration);
    $caisse = Role::findOrCreate('caisse', 'web');
    $caisse->forceFill(['label' => 'Caisse centrale', 'description' => 'Rôle créé à la main depuis l\'écran Rôles.'])->save();

    $migration->up();
    expect($caisse->fresh()->label)->toBe('Caisse centrale');

    $migration->down();
    expect(Role::query()->where('name', 'caisse')->exists())->toBeTrue();
});

it('aboutit au même état que le seeder d\'une installation neuve', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $seeded = libraryAdminSnapshot();

    $migration = libraryAdminRolesMigration();
    $migration->down();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(libraryAdminPermissionsOf('librarian'))->toContain('candidature.mark_paid');

    $migration->up();

    expect(libraryAdminSnapshot())->toBe($seeded);
});
