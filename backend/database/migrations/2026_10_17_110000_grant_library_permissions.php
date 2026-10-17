<?php

declare(strict_types=1);

use App\Models\Role;
use App\Support\ModulePermissionMap;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions du Module 3, ajoutées sans relancer RolePermissionSeeder en prod
 * (le seeder resynchronise et écraserait les réglages faits depuis l'écran Rôles).
 */
return new class extends Migration
{
    private const ROLES = ['super_admin', 'admin', 'librarian'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $module = ModulePermissionMap::modules()['bibliotheque'];
        $names = [...$module['read'], ...$module['manage'], 'document.publish'];
        $permissions = collect($names)->map(fn (string $name) => Permission::findOrCreate($name, 'web'));

        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $module = ModulePermissionMap::modules()['bibliotheque'];
        Permission::query()->where('guard_name', 'web')
            ->whereIn('name', [...$module['read'], ...$module['manage'], 'document.publish'])
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
