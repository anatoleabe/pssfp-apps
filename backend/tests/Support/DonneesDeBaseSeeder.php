<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Seeder;
use Tests\TestCase;

/**
 * Données de référence chargées une seule fois par base de test, juste après
 * la migration initiale de RefreshDatabase (voir Tests\TestCase::$seeder).
 * Mêmes seeders que ceux appelés par les tests : seules leurs répétitions
 * disparaissent.
 */
final class DonneesDeBaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TestCase::SEEDERS_DE_BASE);
    }
}
