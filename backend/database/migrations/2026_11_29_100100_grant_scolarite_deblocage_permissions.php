<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Accès anticipé (spec module 4, lot A1) : déblocage sur virement annoncé
 * (agent et responsable de scolarité) et déblocage exceptionnel, moratoire ou
 * dérogation (responsable), sans relancer RolePermissionSeeder en prod.
 * Listes figées.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.deblocage' => ['super_admin', 'admin', 'scolarite_agent', 'scolarite_responsable'],
        'scolarite.deblocage_exceptionnel' => ['super_admin', 'admin', 'scolarite_responsable'],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::DROITS as $permission => $roles) {
            Permission::findOrCreate($permission, 'web');
            Role::query()->where('guard_name', 'web')->whereIn('name', $roles)->get()->each(fn (Role $r) => $r->givePermissionTo($permission));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', array_keys(self::DROITS))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
