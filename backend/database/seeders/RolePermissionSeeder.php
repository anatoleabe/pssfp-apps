<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Support\ModulePermissionMap;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

final class RolePermissionSeeder extends Seeder
{
    /**
     * Crée les rôles du projet et leur périmètre initial.
     *
     * Le périmètre s'exprime dans le même vocabulaire que l'écran de gestion
     * des rôles — module + niveau (aucun / lecture / gestion) + actions
     * sensibles — et passe par ModulePermissionMap, source unique de vérité.
     * Un rôle réglé ici peut donc être relu et ajusté depuis l'admin sans
     * divergence entre les deux chemins.
     *
     * Idempotent : `findOrCreate` + `syncPermissions`, re-runs sûrs en prod.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ModulePermissionMap::allPermissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Scopes Sanctum du portail candidat — hors périmètre back-office,
        // donc hors ModulePermissionMap.
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

        $none = ModulePermissionMap::LEVEL_NONE;
        $read = ModulePermissionMap::LEVEL_READ;
        $manage = ModulePermissionMap::LEVEL_MANAGE;

        /*
         * Périmètre initial de chaque rôle.
         *
         * Règle transverse : les campagnes sont lisibles par tous les rôles
         * back-office mais ne se modifient que par `admin` / `super_admin`
         * (verrou porté par CampagneCandidaturePolicy).
         */
        $roles = [
            'super_admin' => [
                'label' => 'Super administrateur',
                'description' => 'Accès total, y compris réglages, rôles et suppressions définitives.',
                'modules' => ['site' => $manage, 'admissions' => $manage, 'administration' => $manage],
                'sensitive' => array_keys(ModulePermissionMap::sensitiveActions()),
            ],
            'admin' => [
                'label' => 'Administrateur',
                'description' => 'Comptes, rôles, campagnes, réglages et exports.',
                'modules' => ['site' => $manage, 'admissions' => $manage, 'administration' => $manage],
                'sensitive' => [
                    'candidature.export_csv',
                    'candidature.mark_depot_physique',
                    'candidature.regenerate_recipisse',
                    // Écrire à un groupe de candidats engage l'institution :
                    // réservé à admin et super_admin (arbitrage 3 sept. 2026).
                    'candidature.notify',
                ],
            ],
            'editor' => [
                'label' => 'Éditeur',
                'description' => 'Contenus du site institutionnel : pages, actualités, médiathèque.',
                'modules' => ['site' => $manage, 'admissions' => $none, 'administration' => $none],
                'sensitive' => [],
            ],
            'admission_committee' => [
                'label' => 'Comité d\'admission',
                'description' => 'Étude des dossiers de candidature et décisions d\'admission.',
                'modules' => ['site' => $none, 'admissions' => $manage, 'administration' => $none],
                'sensitive' => [
                    'candidature.accept',
                    'candidature.refuse',
                    'candidature.mark_paid',
                    'candidature.export_csv',
                    'candidature.mark_depot_physique',
                    'candidature.regenerate_recipisse',
                ],
            ],
            // Guichet de la scolarité (Yaoundé-Messa, porte 231) : consulter
            // pour identifier le dossier, pointer sa réception. Rien d'autre —
            // ni décision, ni paiement, ni export.
            'receptionniste' => [
                'label' => 'Réception scolarité',
                'description' => 'Pointage des dossiers papier déposés au guichet.',
                'modules' => ['site' => $none, 'admissions' => $read, 'administration' => $none],
                'sensitive' => ['candidature.mark_depot_physique'],
            ],
            'librarian' => [
                'label' => 'Bibliothécaire',
                'description' => 'Bibliothèque virtuelle et encaissement des frais de dossier.',
                'modules' => ['site' => $none, 'admissions' => $read, 'administration' => $none],
                'sensitive' => ['candidature.mark_paid'],
            ],
            // `teacher` et `auditor` n'ouvrent pas le back-office (cf.
            // User::NON_PANEL_ROLES) : ce sont des rôles d'API et de futurs
            // espaces dédiés. Ils existent, sans périmètre admin.
            'teacher' => [
                'label' => 'Enseignant',
                'description' => 'Rôle d\'API — espace enseignant prévu en Phase II. Aucun accès back-office.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none],
                'sensitive' => [],
            ],
            'auditor' => [
                'label' => 'Auditeur',
                'description' => 'Rôle d\'API — espace auditeur prévu en Phase II. Aucun accès back-office.',
                'modules' => ['site' => $none, 'admissions' => $none, 'administration' => $none],
                'sensitive' => [],
            ],
        ];

        foreach ($roles as $name => $definition) {
            $role = Role::findOrCreate($name, 'web');
            $role->forceFill([
                'label' => $definition['label'],
                'description' => $definition['description'],
            ])->save();

            $role->syncPermissions(ModulePermissionMap::toPermissions(
                $definition['modules'],
                $definition['sensitive'],
            ));
        }

        $candidat = Role::findOrCreate('candidat', 'web');
        $candidat->forceFill([
            'label' => 'Candidat',
            'description' => 'Compte du portail de candidature — jamais un accès back-office.',
        ])->save();
        $candidat->syncPermissions($candidatPermissions);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
