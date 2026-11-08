<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** États de paie des enseignants (lot 13b-1), sans relancer RolePermissionSeeder en prod. Listes figées. */
return new class extends Migration
{
    private const PERMISSIONS = [
        'lmd.paie_preparer' => ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'],
        'lmd.paie_verifier' => ['super_admin', 'admin', 'scolarite_responsable'],
        'lmd.paie_viser' => ['super_admin'],
        'lmd.paie_payer' => ['super_admin', 'service_financier'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');
            Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get()->each(fn (Role $r) => $r->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', array_keys(self::PERMISSIONS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
