<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Actes du calendrier (lot 12b), sans relancer RolePermissionSeeder en prod. Listes figées.
 * Le rôle chef_structure existe depuis le lot 12a.
 */
return new class extends Migration
{
    private const PRODUIRE = 'lmd.actes_produire';

    private const SIGNER = 'lmd.actes_signer';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::PRODUIRE, self::SIGNER] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $roles = Role::query()->where('guard_name', 'web');
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->get()->each(fn (Role $r) => $r->givePermissionTo(self::PRODUIRE));
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable', 'chef_structure'])->get()->each(fn (Role $r) => $r->givePermissionTo(self::SIGNER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::PRODUIRE, self::SIGNER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
