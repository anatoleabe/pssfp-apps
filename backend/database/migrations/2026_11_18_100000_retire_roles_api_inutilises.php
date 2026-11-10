<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Retire `teacher` et `auditor` (chantier « Socle », étape 4).
 *
 * Rôles « d'API » créés au lancement pour des espaces enseignant et auditeur
 * qui ont été construits autrement : l'enseignant a le rôle `enseignant`,
 * l'auditeur un compte d'espace sans rôle. Aucun titulaire en prod (vérifié
 * le 10/11/2026) et aucune permission. `teacher` portait en outre le même
 * libellé « Enseignant » que `enseignant`.
 *
 * La migration refuse de s'exécuter si un compte porte encore l'un des deux
 * rôles : retirer un rôle détenu se décide à la main, pas en déploiement.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string}> */
    private const ROLES = [
        'teacher' => [
            'label' => 'Enseignant',
            'description' => 'Rôle d\'API — espace enseignant prévu en Phase II. Aucun accès back-office.',
        ],
        'auditor' => [
            'label' => 'Auditeur',
            'description' => 'Rôle d\'API — espace auditeur prévu en Phase II. Aucun accès back-office.',
        ],
    ];

    public function up(): void
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', array_keys(self::ROLES))
            ->withCount('users')
            ->get();

        $detenus = $roles->filter(fn (Role $role): bool => $role->users_count > 0);
        if ($detenus->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Rôles encore attribués, retrait annulé : %s.',
                $detenus->map(fn (Role $role): string => "{$role->name} ({$role->users_count})")->implode(', '),
            ));
        }

        $roles->each(fn (Role $role) => $role->delete());
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::ROLES as $name => $definition) {
            Role::findOrCreate($name, 'web')->forceFill($definition)->save();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
