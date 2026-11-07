<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Anonymat (lot 9), sans relancer RolePermissionSeeder en prod. Deux rôles séparés (RG-047) :
 * le gestionnaire des anonymats ne saisit pas, l'opérateur ne voit que des numéros.
 */
return new class extends Migration
{
    private const ROLES = [
        'gestionnaire_anonymats' => [
            'permission' => 'lmd.anonymat_gerer',
            'label' => 'Gestionnaire des anonymats',
            'description' => 'Épreuves anonymes : numéros, étiquettes, constats, verrouillage et levée avec la clé. Ne saisit pas de notes.',
        ],
        'operateur_saisie' => [
            'permission' => 'lmd.anonymat_saisir',
            'label' => 'Opérateur de saisie',
            'description' => 'Saisie des notes d\'examen sur les numéros d\'anonymat, sans accès à la correspondance.',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::ROLES as $nom => $r) {
            Permission::findOrCreate($r['permission'], 'web');
            $role = Role::findOrCreate($nom, 'web');
            if ($role->wasRecentlyCreated) {
                $role->forceFill(['label' => $r['label'], 'description' => $r['description']])->save();
            }
            $role->givePermissionTo($r['permission']);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $nom => $r) {
            Role::query()->where('guard_name', 'web')->where('name', $nom)->where('description', $r['description'])->delete();
            Permission::query()->where('guard_name', 'web')->where('name', $r['permission'])->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
