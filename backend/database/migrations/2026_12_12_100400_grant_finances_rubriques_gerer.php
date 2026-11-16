<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rubriques de frais et comptes de scolarité des promotions (reçu A4, R2) : modifiables par l'administrateur seulement,
 * lisibles par l'espace Finances (`finances.consulter`). Sans relancer RolePermissionSeeder en prod. Rejouable ; `down()`
 * retire la permission.
 */
return new class extends Migration
{
    private const GERER = 'finances.rubriques_gerer';

    private const ROLES = ['super_admin', 'admin'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(self::GERER, 'web');
        Role::query()->where('guard_name', 'web')->whereIn('name', self::ROLES)->get()
            ->each(fn (Role $r) => $r->givePermissionTo(self::GERER));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->where('name', self::GERER)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
