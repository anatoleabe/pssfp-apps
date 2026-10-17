<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions du Module 3, ajoutées sans relancer RolePermissionSeeder en prod
 * (le seeder resynchronise et écraserait les réglages faits depuis l'écran Rôles).
 *
 * Liste figée à la date de la migration : une évolution ultérieure de
 * ModulePermissionMap ne doit pas changer ce que cette migration a accordé.
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'admin', 'librarian'];

    private const PERMISSIONS = [
        'view_any_document', 'view_document',
        'view_any_mot::cle', 'view_mot::cle',
        'create_document', 'update_document', 'delete_document',
        'create_mot::cle', 'update_mot::cle', 'delete_mot::cle',
        'document.publish',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', self::PERMISSIONS)
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
