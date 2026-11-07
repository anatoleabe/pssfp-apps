<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Module « Parcours LMD » (lot 4), sans relancer RolePermissionSeeder en prod.
 * Listes figées à la date de la migration ; les rôles existants sont complétés,
 * jamais synchronisés.
 */
return new class extends Migration
{
    private const AGENT = ['lmd.consulter', 'lmd.referentiel', 'lmd.inscrire'];

    private const SENSIBLES = ['lmd.maquette_valider', 'lmd.regles', 'lmd.parcours_changer'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $tout = [...self::AGENT, ...self::SENSIBLES];
        foreach ($tout as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo($tout));
        Role::query()->where('guard_name', 'web')->where('name', 'scolarite_agent')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::AGENT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', [...self::AGENT, ...self::SENSIBLES])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
