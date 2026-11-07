<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Requêtes (lot 8), sans relancer RolePermissionSeeder en prod. Listes figées.
 * Nouveau rôle `chef_commission_requetes` : validation définitive des PV de requêtes (D28).
 */
return new class extends Migration
{
    private const TRAITER = 'lmd.requetes_traiter';

    private const RESPONSABLE = ['lmd.requetes_coter', 'lmd.requetes_jury'];

    private const VALIDER = 'lmd.requetes_valider';

    private const ROLE_CHEF = [
        'label' => 'Chef de la commission des requêtes',
        'description' => 'Validation définitive des PV du jury des requêtes : les notes changent à ce moment.',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::TRAITER, ...self::RESPONSABLE, self::VALIDER] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo([self::TRAITER, ...self::RESPONSABLE, self::VALIDER]));
        Role::query()->where('guard_name', 'web')->where('name', 'scolarite_responsable')->get()
            ->each(fn (Role $role) => $role->givePermissionTo([self::TRAITER, ...self::RESPONSABLE]));
        Role::query()->where('guard_name', 'web')->where('name', 'scolarite_agent')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::TRAITER));
        $chef = Role::findOrCreate('chef_commission_requetes', 'web');
        if ($chef->wasRecentlyCreated) {
            $chef->forceFill(self::ROLE_CHEF)->save();
        }
        $chef->givePermissionTo(self::VALIDER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'chef_commission_requetes')->where('description', self::ROLE_CHEF['description'])->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::TRAITER, ...self::RESPONSABLE, self::VALIDER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
