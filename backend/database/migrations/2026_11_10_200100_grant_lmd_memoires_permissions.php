<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Mémoires (lot 11), sans relancer RolePermissionSeeder en prod. Listes figées. */
return new class extends Migration
{
    private const SUIVRE = 'lmd.memoires_suivre';

    private const ATTESTER = 'lmd.memoires_attester';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::SUIVRE, 'web');
        Permission::findOrCreate(self::ATTESTER, 'web');
        $roles = Role::query()->where('guard_name', 'web');
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::SUIVRE));
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::ATTESTER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::SUIVRE, self::ATTESTER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
