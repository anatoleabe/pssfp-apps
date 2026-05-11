<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Référentiels (idempotents).
            PaysSeeder::class,
            RegionsCamerounSeeder::class,
            DepartementsCamerounSeeder::class,
            // Rôles + admin.
            RolePermissionSeeder::class,
            AdminUserSeeder::class,
            // Contenu éditorial du site institutionnel (idempotent par slug).
            AProposPagesSeeder::class,
            FormationsPagesSeeder::class,
            VieAcademiquePagesSeeder::class,
            ArticlesSeeder::class,
            // Données de démo (env local/dev/testing uniquement).
            DemoCampagneSeeder::class,
        ]);
    }
}
