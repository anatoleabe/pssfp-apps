<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\ModulePermissionMap;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Matrice rôles × droits, lue en base (chantier « Socle », étape 4).
 *
 * Lit les rôles tels qu'ils sont réglés — y compris depuis l'écran Rôles —
 * et non le seeder : lancée en prod, elle décrit les droits réels. Le
 * vocabulaire est celui de l'écran Rôles (ModulePermissionMap).
 *
 * Usage : `php artisan roles:matrice > ../docs/ops/roles.md`
 */
final class MatriceRoles extends Command
{
    protected $signature = 'roles:matrice';

    protected $description = 'Écrit en Markdown la matrice des rôles du back-office et de leurs droits.';

    private const NIVEAUX = [
        ModulePermissionMap::LEVEL_NONE => '—',
        ModulePermissionMap::LEVEL_READ => 'Lecture',
        ModulePermissionMap::LEVEL_MANAGE => 'Gestion',
    ];

    public function handle(): int
    {
        $modules = ModulePermissionMap::modules();
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', User::NON_PANEL_ROLES)
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get();

        $this->line('# Matrice des rôles du back-office');
        $this->newLine();
        $this->line(sprintf('Générée le %s par `php artisan roles:matrice`. Ne pas modifier à la main.', now()->format('d/m/Y')));
        $this->newLine();
        $this->line('Niveaux : « Gestion » inclut la lecture ; « — » : aucun accès au module.');
        $this->newLine();
        $this->tableau($roles, $modules);
        $this->newLine();
        $this->line('## Actions sensibles');
        $this->newLine();
        $this->actionsSensibles($roles);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  array<string, array{label: string}>  $modules
     */
    private function tableau(Collection $roles, array $modules): void
    {
        $entete = ['Rôle', 'Comptes', ...array_column($modules, 'label')];
        $this->line('| '.implode(' | ', $entete).' |');
        $this->line('|'.str_repeat('---|', count($entete)));

        foreach ($roles as $role) {
            $niveaux = ModulePermissionMap::toLevels($role->permissions->pluck('name')->all());
            $cellules = array_map(fn (string $cle): string => self::NIVEAUX[$niveaux[$cle] ?? ModulePermissionMap::LEVEL_NONE], array_keys($modules));
            $this->line('| '.implode(' | ', [$this->nom($role), (string) $role->users_count, ...$cellules]).' |');
        }
    }

    /** @param  Collection<int, Role>  $roles */
    private function actionsSensibles(Collection $roles): void
    {
        $libelles = ModulePermissionMap::sensitiveActions();

        foreach ($roles as $role) {
            $actions = $role->permissions->pluck('name')
                ->filter(fn (string $permission): bool => isset($libelles[$permission]))
                ->map(fn (string $permission): string => $libelles[$permission])
                ->sort()
                ->values();

            $this->line(sprintf('- **%s** : %s', $this->nom($role), $actions->isEmpty() ? 'aucune' : $actions->implode(' ; ')));
        }
    }

    private function nom(Role $role): string
    {
        return sprintf('%s (`%s`)', $role->label ?: $role->name, $role->name);
    }
}
