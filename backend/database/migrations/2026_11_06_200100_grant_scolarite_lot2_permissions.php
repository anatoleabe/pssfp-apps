<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions du lot 2 (réglages de promotion, statut, messages), sans relancer
 * RolePermissionSeeder en prod. Listes figées à la date de la migration.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['scolarite.promotion', 'scolarite.statut', 'scolarite.messages'];

    private const ROLES = ['super_admin', 'admin', 'scolarite_responsable'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
