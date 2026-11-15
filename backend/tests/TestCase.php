<?php

namespace Tests;

use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Arr;
use Pest\Livewire\InteractsWithLivewire;
use Tests\Support\DonneesDeBaseSeeder;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithLivewire;

    /**
     * Données de référence communes à presque tous les tests (pays, régions,
     * départements, rôles et permissions). Les recharger avant chaque test
     * coûtait environ une seconde par test.
     *
     * Elles sont chargées une seule fois par base de test, avec la migration
     * initiale de RefreshDatabase, puis conservées : chaque test tourne dans une
     * transaction annulée à la fin, et une transaction rompue fait remigrer
     * (et recharger) toute la base.
     */
    public const SEEDERS_DE_BASE = [
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ];

    /** Lu par RefreshDatabase (migrate:fresh --seeder) : une fois par base. */
    protected $seeder = DonneesDeBaseSeeder::class;

    /**
     * Les données de base sont déjà là : seuls les autres seeders demandés
     * sont lancés.
     *
     * @param  list<string>|class-string<Seeder>|string  $class
     * @return $this
     */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        $autres = array_values(array_diff(Arr::wrap($class), self::SEEDERS_DE_BASE));

        return $autres === [] ? $this : parent::seed($autres);
    }

    /**
     * Relance vraiment un seeder, données de base comprises : pour les tests
     * qui vérifient le seeder lui-même (par exemple qu'il ne crée pas de
     * doublons quand on le rejoue).
     *
     * @param  list<string>|class-string<Seeder>|string  $class
     * @return $this
     */
    public function reensemencer($class)
    {
        return parent::seed($class);
    }
}
