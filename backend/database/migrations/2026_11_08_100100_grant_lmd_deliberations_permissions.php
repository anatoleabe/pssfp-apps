<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Délibérations (lot 6), sans relancer RolePermissionSeeder en prod. Listes figées.
 * Nouveau rôle `membre_jury` : signature du PV des jurys dont il est membre.
 */
return new class extends Migration
{
    private const PREPARER = 'lmd.deliberation_preparer';

    private const SENSIBLES = ['lmd.deliberation_arreter', 'lmd.deliberation_publier'];

    private const SIGNER = 'lmd.jury_signer';

    private const ROLE_JURY = [
        'label' => 'Membre de jury',
        'description' => 'Consultation des délibérations de ses jurys et signature de leurs PV.',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([self::PREPARER, ...self::SENSIBLES, self::SIGNER] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('guard_name', 'web')->whereIn('name', ['super_admin', 'admin', 'scolarite_responsable'])->get()
            ->each(fn (Role $role) => $role->givePermissionTo([self::PREPARER, ...self::SENSIBLES, self::SIGNER]));
        Role::query()->where('guard_name', 'web')->where('name', 'scolarite_agent')->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PREPARER));
        $jury = Role::findOrCreate('membre_jury', 'web');
        if ($jury->wasRecentlyCreated) {
            $jury->forceFill(self::ROLE_JURY)->save();
        }
        $jury->givePermissionTo(self::SIGNER);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::query()->where('guard_name', 'web')->where('name', 'membre_jury')->where('description', self::ROLE_JURY['description'])->delete();
        Permission::query()->where('guard_name', 'web')->whereIn('name', [self::PREPARER, ...self::SENSIBLES, self::SIGNER])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
