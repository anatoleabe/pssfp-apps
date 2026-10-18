<?php

declare(strict_types=1);

use App\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses()->group('module-3', 'library');

/** Rejoue la migration additive sur des rôles réglés à la main (situation de prod). */
it('accorde les droits bibliothèque sans retirer les droits existants des rôles', function (): void {
    $existing = Permission::findOrCreate('view_any_candidature', 'web');
    $roles = collect(['super_admin', 'admin', 'librarian'])->map(function (string $name) use ($existing): Role {
        $role = Role::findOrCreate($name, 'web');
        $role->syncPermissions([$existing]);

        return $role;
    });
    $editor = Role::findOrCreate('editor', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_10_17_110000_grant_library_permissions.php');
    $migration->up();

    foreach ($roles as $role) {
        expect($role->fresh()->permissions->pluck('name'))
            ->toContain('view_any_candidature')
            ->toContain('view_any_document')
            ->toContain('update_mot::cle')
            ->toContain('document.publish');
    }
    expect($editor->fresh()->permissions->pluck('name'))->not->toContain('view_any_document');

    // Rejouer est sans effet de bord.
    $migration->up();
    expect(Role::findByName('librarian', 'web')->permissions)->toHaveCount(1 + 10 + 1);
});
