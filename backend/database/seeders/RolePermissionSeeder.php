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
            // Agent du bureau de la scolarité (Yaoundé-Messa, porte 231) : ne
            // fait que constater la réception des dossiers papier. Aucun droit
            // de décision d'admission ni d'édition du dossier.
            'receptionniste',
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
            'candidature.delete_test', // uniquement postulant jamais soumis, super_admin

            // CRUD Filament-Shield style sur CampagneCandidature.
            'view_any_campagne::candidature',
            'view_campagne::candidature',
            'create_campagne::candidature',
            'update_campagne::candidature',

            // CRUD Filament-Shield style sur User (gestion des comptes admin).
            'view_any_user',
            'view_user',
            'create_user',
            'update_user',
            'delete_user',

            // Permissions métier (actions Filament dédiées).
            'candidature.update_status',  // postulant <-> candidat (rétrogradation incluse)
            'candidature.accept',
            'candidature.refuse',
            'candidature.mark_paid',
            'candidature.export_csv',
            'candidature.withdraw',       // retrait administratif super_admin
            'candidature.bulk_decision',  // accept/refuse en bulk — super_admin only
            'candidature.mark_depot_physique', // réception du dossier papier au guichet

            // Réglages applicatifs (destinataires en copie des notifications…).
            'settings.manage',
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
                'view_any_user', 'view_user', 'create_user', 'update_user',
                'candidature.export_csv',
                'candidature.mark_depot_physique',
                'settings.manage',
            ],
            'admission_committee' => [
                'view_any_candidature', 'view_candidature', 'update_candidature',
                'view_any_campagne::candidature', 'view_campagne::candidature',
                'candidature.accept', 'candidature.refuse',
                'candidature.mark_paid', 'candidature.export_csv',
                'candidature.mark_depot_physique',
            ],
            // Guichet : consulter pour identifier le dossier, cocher la
            // réception. Rien d'autre — ni décision, ni paiement, ni export.
            'receptionniste' => [
                'view_any_candidature', 'view_candidature',
                'candidature.mark_depot_physique',
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
