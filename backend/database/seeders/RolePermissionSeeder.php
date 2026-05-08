<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /**
     * Crée les rôles principaux du projet et attache les permissions de base.
     *
     * Les permissions fines des Resources Filament sont générées par
     * filament-shield au fur et à mesure (cf. modules 3, 5, 6).
     *
     * Pour le rôle `candidat` (PR B M5) : permissions explicites sur les
     * scopes Sanctum candidat (profile + application).
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $roles = [
            'super_admin',
            'admin',
            'editor',
            'librarian',
            'admission_committee',
            'teacher',
            'auditor',
            'candidat',
        ];

        foreach ($roles as $role) {
            Role::findOrCreate($role, 'web');
        }

        $candidatPermissions = [
            'profile.read',
            'profile.write',
            'application.create',
            'application.read',
            // application.submit séparée de application.create : Phase II pourra
            // donner create/update à un assistant USI tout en réservant submit
            // au candidat lui-même (signature engagement). En V1 le candidat a les deux.
            'application.submit',
        ];

        foreach ($candidatPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        Role::findOrCreate('candidat', 'web')->syncPermissions($candidatPermissions);

        /*
         * PR D — Permissions Filament admin pour Module 5.
         *
         * Hybride filament-shield + permissions métier :
         * - CRUD de base : convention shield `{action}_{resource}` sans
         *   passer par la commande `shield:generate` (qu'on appellera quand
         *   les autres modules arriveront — pour V1 on définit explicitement).
         * - Permissions métier dédiées pour les actions Filament sensibles.
         *
         * Le seeder utilise Permission::findOrCreate qui est idempotent — re-runs
         * sûrs en prod (cf. précision PR D arbitrage C).
         */
        $filamentPermissions = [
            // CRUD Filament-Shield style sur Candidature.
            'view_any_candidature',
            'view_candidature',
            'update_candidature',
            // `delete` volontairement absent — pas de delete via panel
            // (RGPD : passer par CLI artisan dédiée).

            // CRUD Filament-Shield style sur CampagneCandidature.
            'view_any_campagne::candidature',
            'view_campagne::candidature',
            'create_campagne::candidature',
            'update_campagne::candidature',

            // Permissions métier (actions Filament dédiées).
            'candidature.update_status',  // postulant <-> candidat (rétrogradation incluse)
            'candidature.accept',
            'candidature.refuse',
            'candidature.mark_paid',
            'candidature.export_csv',
            'candidature.withdraw',       // retrait administratif super_admin
            'candidature.bulk_decision',  // accept/refuse en bulk — super_admin only
        ];

        foreach ($filamentPermissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Matrice rôle -> permissions Filament (V1).
        $matrix = [
            'super_admin' => $filamentPermissions, // toutes
            'admin' => [
                'view_any_candidature', 'view_candidature', 'update_candidature',
                'view_any_campagne::candidature', 'view_campagne::candidature',
                'create_campagne::candidature', 'update_campagne::candidature',
                'candidature.export_csv',
            ],
            'admission_committee' => [
                'view_any_candidature', 'view_candidature', 'update_candidature',
                'view_any_campagne::candidature', 'view_campagne::candidature',
                'candidature.accept', 'candidature.refuse',
                'candidature.mark_paid', 'candidature.export_csv',
            ],
            'librarian' => [
                'view_any_candidature', 'view_candidature',
                'candidature.mark_paid',
            ],
        ];

        foreach ($matrix as $roleName => $perms) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($perms);
        }
    }
}
