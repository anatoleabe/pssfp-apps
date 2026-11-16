<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Espace Finances, sous-lot F1 (spec module-4-scolarite-espace-financier §9, décisions du 16/11/2026, Q7) :
 * `finances.consulter` pour le service financier, le responsable de scolarité et les administrateurs ;
 * `scolarite.export` en plus pour le service financier (exports nominatifs de l'espace). Sans relancer
 * RolePermissionSeeder en prod. Rejouable ; `down()` retire ce que `up()` a donné.
 */
return new class extends Migration
{
    private const CONSULTER = 'finances.consulter';

    private const EXPORT = 'scolarite.export';

    private const ROLES = ['super_admin', 'admin', 'scolarite_responsable', 'service_financier'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::CONSULTER, 'web');
        Permission::findOrCreate(self::EXPORT, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::CONSULTER));
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::EXPORT));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->get()
            ->filter(fn (Role $r): bool => $r->hasPermissionTo(self::EXPORT))
            ->each(fn (Role $r) => $r->revokePermissionTo(self::EXPORT));
        Permission::query()->where('guard_name', 'web')->where('name', self::CONSULTER)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
