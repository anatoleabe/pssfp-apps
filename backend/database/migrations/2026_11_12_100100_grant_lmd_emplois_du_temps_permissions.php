<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Emplois du temps (lot 12c), sans relancer RolePermissionSeeder en prod. Liste figée. */
return new class extends Migration
{
    private const PERMISSION = 'lmd.emplois_du_temps';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::PERMISSION, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::PERMISSION));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
