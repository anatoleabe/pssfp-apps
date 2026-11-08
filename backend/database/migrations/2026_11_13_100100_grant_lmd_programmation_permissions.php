<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/** Programmation des enseignants (lot 12d), sans relancer RolePermissionSeeder en prod. Listes figées. */
return new class extends Migration
{
    private const GESTION = 'lmd.programmation';

    private const VALIDER = 'lmd.programmation_valider';

    private const REPONDRE = 'lmd.programmation_repondre';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::GESTION, self::VALIDER, self::REPONDRE] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $roles = fn (array $noms) => Role::query()->where('guard_name', 'web')->whereIn('name', $noms)->get();
        $roles(['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->each(fn (Role $r) => $r->givePermissionTo(self::GESTION));
        $roles(['super_admin', 'admin', 'scolarite_responsable'])->each(fn (Role $r) => $r->givePermissionTo(self::VALIDER));
        $roles(['super_admin', 'enseignant'])->each(fn (Role $r) => $r->givePermissionTo(self::REPONDRE));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::GESTION, self::VALIDER, self::REPONDRE])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
