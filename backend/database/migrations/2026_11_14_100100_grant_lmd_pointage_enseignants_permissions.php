<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Pointage des enseignants et dossier de paie (lot 13a), sans relancer RolePermissionSeeder en prod. Listes figées.
 * Le NIU et le compte bancaire sont réservés au service financier (D23) : rôle autonome créé ici.
 */
return new class extends Migration
{
    private const GESTION = 'lmd.pointage_enseignants';

    private const EMARGER = 'lmd.pointage_emarger';

    private const DOSSIER = 'lmd.dossier_paie';

    private const DESCRIPTION = 'Dossiers de paie des enseignants : NIU, banque et compte de virement.';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::GESTION, self::EMARGER, self::DOSSIER] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $roles = fn (array $noms) => Role::query()->where('guard_name', 'web')->whereIn('name', $noms)->get();
        $roles(['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->each(fn (Role $r) => $r->givePermissionTo(self::GESTION));
        $roles(['super_admin', 'enseignant'])->each(fn (Role $r) => $r->givePermissionTo(self::EMARGER));
        $roles(['super_admin'])->each(fn (Role $r) => $r->givePermissionTo(self::DOSSIER));
        $financier = Role::findOrCreate('service_financier', 'web');
        if ($financier->wasRecentlyCreated) {
            $financier->forceFill(['label' => 'Service administratif et financier', 'description' => self::DESCRIPTION])->save();
        }
        $financier->givePermissionTo(self::DOSSIER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'service_financier')->where('description', self::DESCRIPTION)->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::GESTION, self::EMARGER, self::DOSSIER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
