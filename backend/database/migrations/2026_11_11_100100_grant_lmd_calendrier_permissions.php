<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Calendrier académique (lot 12a), sans relancer RolePermissionSeeder en prod. Listes figées.
 * La signature revient au chef de la structure (D21) : rôle autonome créé ici.
 */
return new class extends Migration
{
    private const PREPARER = 'lmd.calendrier_preparer';

    private const REAJUSTER = 'lmd.calendrier_reajuster';

    private const SIGNER = 'lmd.calendrier_signer';

    private const DESCRIPTION = 'Signature du calendrier académique préparé par la scolarité, ou renvoi en préparation.';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::PREPARER, self::REAJUSTER, self::SIGNER] as $p) {
            Permission::findOrCreate($p, 'web');
        }
        $roles = Role::query()->where('guard_name', 'web');
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable', 'scolarite_agent'])->get()->each(fn (Role $r) => $r->givePermissionTo(self::PREPARER));
        (clone $roles)->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable'])->get()->each(fn (Role $r) => $r->givePermissionTo(self::REAJUSTER));
        (clone $roles)->whereIn('name', ['super_admin', 'admin'])->get()->each(fn (Role $r) => $r->givePermissionTo(self::SIGNER));
        $chef = Role::findOrCreate('chef_structure', 'web');
        if ($chef->wasRecentlyCreated) {
            $chef->forceFill(['label' => 'Chef de la structure', 'description' => self::DESCRIPTION])->save();
        }
        $chef->givePermissionTo(self::SIGNER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'chef_structure')->where('description', self::DESCRIPTION)->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::PREPARER, self::REAJUSTER, self::SIGNER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
