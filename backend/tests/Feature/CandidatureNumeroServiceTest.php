<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Services\CandidatureNumeroService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
    ]);
});

it('generates incremental numero per campagne', function (): void {
    $campagne = CampagneCandidature::factory()->create([
        'prefix_numero' => 'P14026-',
    ]);

    $first = Candidature::factory()->forCampagne($campagne)->create();
    $second = Candidature::factory()->forCampagne($campagne)->create();
    $third = Candidature::factory()->forCampagne($campagne)->create();

    expect($first->numero_dossier)->toBe('P14026-001');
    expect($second->numero_dossier)->toBe('P14026-002');
    expect($third->numero_dossier)->toBe('P14026-003');
});

it('pads suffix to 3 digits even for high counts', function (): void {
    $campagne = CampagneCandidature::factory()->create(['prefix_numero' => 'P14026-']);

    // Insère un dossier déjà numéroté manuellement à 99.
    Candidature::factory()->forCampagne($campagne)->create([
        'numero_dossier' => 'P14026-099',
    ]);

    $next = Candidature::factory()->forCampagne($campagne)->create();
    expect($next->numero_dossier)->toBe('P14026-100');
});

it('isolates numbering between campagnes', function (): void {
    $p14 = CampagneCandidature::factory()->create(['prefix_numero' => 'P14026-']);
    $p15 = CampagneCandidature::factory()->create(['prefix_numero' => 'P15027-']);

    Candidature::factory()->forCampagne($p14)->create();
    Candidature::factory()->forCampagne($p14)->create();
    $firstP15 = Candidature::factory()->forCampagne($p15)->create();

    expect($firstP15->numero_dossier)->toBe('P15027-001');
});

it('handles soft-deleted records correctly (no numero collision)', function (): void {
    $campagne = CampagneCandidature::factory()->create(['prefix_numero' => 'P14026-']);

    $first = Candidature::factory()->forCampagne($campagne)->create();
    $second = Candidature::factory()->forCampagne($campagne)->create();
    $second->delete(); // soft delete

    $third = Candidature::factory()->forCampagne($campagne)->create();

    // Le suffixe doit être 003, pas 002 — sinon collision avec le soft-deleted.
    expect($third->numero_dossier)->toEndWith('-003');
});

it('acquires advisory lock during generation', function (): void {
    $campagne = CampagneCandidature::factory()->create(['prefix_numero' => 'P14026-']);
    $service = app(CandidatureNumeroService::class);

    $lockObserved = false;
    DB::transaction(function () use ($service, $campagne, &$lockObserved): void {
        $numero = $service->generate($campagne);
        expect($numero)->toMatch('/^P14026-\d{3}$/');

        // Pendant la transaction, le advisory lock acquis par pg_advisory_xact_lock(42, ?)
        // doit être visible dans pg_locks.
        $locks = DB::select(
            "SELECT count(*) AS n FROM pg_locks WHERE locktype = 'advisory' AND classid = 42"
        );
        $lockObserved = ((int) $locks[0]->n) > 0;
    });

    expect($lockObserved)->toBeTrue();
});

it('respects UNIQUE (campagne_id, phone_e164) but allows same phone across campagnes', function (): void {
    $p14 = CampagneCandidature::factory()->create(['prefix_numero' => 'P14026-']);
    $p15 = CampagneCandidature::factory()->create(['prefix_numero' => 'P15027-']);

    $phone = '+237691234567';
    Candidature::factory()->forCampagne($p14)->create(['phone_e164' => $phone]);

    // Même téléphone dans une autre campagne : autorisé.
    $second = Candidature::factory()->forCampagne($p15)->create(['phone_e164' => $phone]);
    expect($second->id)->not->toBeNull();

    // Même téléphone dans la même campagne : refusé.
    expect(fn () => Candidature::factory()->forCampagne($p14)->create(['phone_e164' => $phone]))
        ->toThrow(UniqueConstraintViolationException::class);
});
