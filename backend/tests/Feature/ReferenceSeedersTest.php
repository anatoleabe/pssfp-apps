<?php

declare(strict_types=1);

use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;

it('seeds 200+ pays including Cameroon', function (): void {
    $this->seed(PaysSeeder::class);

    expect(Pays::count())->toBeGreaterThanOrEqual(190);
    expect(Pays::find('CM')?->nom)->toBe('Cameroun');
    expect(Pays::find('CM')?->indicatif)->toBe('+237');
});

it('seeds 11 regions including Z AUTRES', function (): void {
    $this->seed(RegionsCamerounSeeder::class);

    expect(RegionCameroun::count())->toBe(11);
    expect(RegionCameroun::find('CENTRE')?->nom)->toBe('Centre');
    expect((float) RegionCameroun::find('EXTREME-NORD')->quota_admission)->toBe(0.18);
    expect(RegionCameroun::find('Z AUTRES'))->not->toBeNull();
    expect(RegionCameroun::find('Z AUTRES')->quota_admission)->toBeNull();
});

it('seeds 58 real departements + AUTRES with valid region FK', function (): void {
    $this->seed(RegionsCamerounSeeder::class);
    $this->seed(DepartementsCamerounSeeder::class);

    // 58 départements réels du Cameroun + 1 entrée AUTRES (étranger / hors classification).
    expect(DepartementCameroun::count())->toBe(59);
    expect(DepartementCameroun::where('code', '!=', 'AUTRES')->count())->toBe(58);

    expect(DepartementCameroun::find('Mfoundi')?->region_code)->toBe('CENTRE');
    expect(DepartementCameroun::find('Wouri')?->chef_lieu)->toBe('Douala');
    expect(DepartementCameroun::find('AUTRES')?->region_code)->toBe('Z AUTRES');
});

it('is idempotent — running seeders twice does not duplicate rows', function (): void {
    // Les données de base sont déjà chargées une fois par base de test (Tests\TestCase) :
    // reensemencer() rejoue vraiment les seeders par-dessus.
    $this->reensemencer([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class]);
    $comptes = [Pays::count(), RegionCameroun::count(), DepartementCameroun::count()];

    $this->reensemencer([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class]);
    expect([Pays::count(), RegionCameroun::count(), DepartementCameroun::count()])->toBe($comptes);
});
