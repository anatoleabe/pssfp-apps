<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\DepartementCameroun;
use App\Models\Pays;
use App\Models\RegionCameroun;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
    ]);
});

it('assigns a uuid on candidature creation', function (): void {
    $candidature = Candidature::factory()->create();

    expect($candidature->uuid)->toBeString();
    expect($candidature->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

it('uses uuid as the public route key', function (): void {
    $candidature = Candidature::factory()->create();

    expect($candidature->getRouteKeyName())->toBe('uuid');
});

it('keeps id as bigint internal primary key', function (): void {
    $candidature = Candidature::factory()->create();

    expect($candidature->id)->toBeInt();
    expect($candidature->id)->toBeGreaterThan(0);
});

it('resolves campagne, pays, region and departement relations', function (): void {
    $candidature = Candidature::factory()->create([
        'nationalite' => 'CM',
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
    ]);

    expect($candidature->campagne)->toBeInstanceOf(CampagneCandidature::class);
    expect($candidature->paysNationalite)->toBeInstanceOf(Pays::class);
    expect($candidature->paysNationalite->nom)->toBe('Cameroun');
    expect($candidature->regionRel)->toBeInstanceOf(RegionCameroun::class);
    expect($candidature->regionRel->nom)->toBe('Centre');
    expect($candidature->departementRel)->toBeInstanceOf(DepartementCameroun::class);
});

it('scopes candidatures by campagne and statut', function (): void {
    $campagne = CampagneCandidature::factory()->create();
    Candidature::factory()->forCampagne($campagne)->create(['statut' => 'postulant']);
    Candidature::factory()->forCampagne($campagne)->submitted()->create();
    Candidature::factory()->forCampagne($campagne)->create(['statut' => 'accepte']);

    expect(Candidature::forCampagne($campagne->id)->count())->toBe(3);
    expect(Candidature::byStatut('postulant')->count())->toBeGreaterThanOrEqual(1);
    expect(Candidature::forCampagne($campagne->id)->byStatut('accepte')->count())->toBe(1);
});

it('soft-deletes candidatures (deleted_at populated, row preserved)', function (): void {
    $candidature = Candidature::factory()->create();
    $id = $candidature->id;

    $candidature->delete();

    expect(Candidature::query()->find($id))->toBeNull();
    expect(Candidature::withTrashed()->find($id))->not->toBeNull();
    expect(Candidature::withTrashed()->find($id)->deleted_at)->not->toBeNull();
});

it('exposes the currentlyOpen scope on campagnes', function (): void {
    CampagneCandidature::factory()->create(['status' => 'open']);
    CampagneCandidature::factory()->closed()->create();
    CampagneCandidature::factory()->draft()->create();

    expect(CampagneCandidature::currentlyOpen()->count())->toBe(1);
});

it('rejects invalid statut values via CHECK constraint', function (): void {
    $campagne = CampagneCandidature::factory()->create();

    expect(fn () => Candidature::factory()
        ->forCampagne($campagne)
        ->create(['statut' => 'pending']))
        ->toThrow(QueryException::class);
});

it('rejects invalid type_etude values via CHECK constraint', function (): void {
    $campagne = CampagneCandidature::factory()->create();

    expect(fn () => Candidature::factory()
        ->forCampagne($campagne)
        ->create(['type_etude' => 'mixte']))
        ->toThrow(QueryException::class);
});
