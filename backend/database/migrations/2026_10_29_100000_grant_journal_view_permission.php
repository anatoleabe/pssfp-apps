<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Consultation du journal d'activité (qui a fait quoi, quand, dans tout le
 * back-office) : administration seule. Accordée à super_admin et admin.
 *
 * Migration plutôt que nouveau passage du RolePermissionSeeder en production :
 * le seeder resynchroniserait toutes les permissions des rôles.
 */
return new class extends Migration
{
    private const PERMISSION = 'journal.view';

    private const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permission = Permission::findOrCreate(self::PERMISSION, 'web');

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::ROLES)
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
