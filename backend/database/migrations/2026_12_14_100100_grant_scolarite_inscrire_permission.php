<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lot 22 (décision du 23/11/2026) : réinscrire un auditeur et lancer le passage en groupe, réservés à
 * l'administration et à la scolarité. Sans relancer RolePermissionSeeder en prod. Liste figée.
 */
return new class extends Migration
{
    private const DROITS = [
        'scolarite.inscrire' => ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'],
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
