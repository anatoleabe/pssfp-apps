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
            // Données de démo (env local/dev/testing uniquement).
            DemoCampagneSeeder::class,
        ]);
    }
}
