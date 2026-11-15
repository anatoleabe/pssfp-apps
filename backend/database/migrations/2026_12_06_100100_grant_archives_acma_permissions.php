<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Lot 15d (spec du lot 15, §7.10) : module « Archives ACMA ». Consultation par la scolarité et l'administration ;
 * compléments et arbitrages réservés au responsable de scolarité et à l'administration. Sans relancer
 * RolePermissionSeeder en prod. Listes figées.
 */
return new class extends Migration
{
    private const DROITS = [
        'archives.consulter' => ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'],
        'archives.completer' => ['super_admin', 'admin', 'scolarite_responsable'],
        'archives.rapprocher' => ['super_admin', 'admin', 'scolarite_responsable'],
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
