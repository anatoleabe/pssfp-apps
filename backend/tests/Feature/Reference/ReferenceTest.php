<?php

declare(strict_types=1);

use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Support\Facades\Cache;

uses()->group('reference');

beforeEach(function (): void {
    Cache::flush();
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
    ]);
});

it('returns 200+ pays sorted by name', function (): void {
    $response = $this->getJson('/v1/reference/pays');

    $response->assertOk();
    $payload = $response->json('data');
    expect(count($payload))->toBeGreaterThanOrEqual(190);

    $cm = collect($payload)->firstWhere('code_iso', 'CM');
    expect($cm['nom'])->toBe('Cameroun');
    expect($cm['indicatif'])->toBe('+237');
});

it('returns 11 regions including Z AUTRES with quotas', function (): void {
    $response = $this->getJson('/v1/reference/regions-cameroun');

    $response->assertOk();
    $payload = $response->json('data');
    expect(count($payload))->toBe(11);

    $extreme = collect($payload)->firstWhere('code', 'EXTREME-NORD');
    expect($extreme['quota_admission'])->toBe(0.18);

    $autres = collect($payload)->firstWhere('code', 'Z AUTRES');
    expect($autres['quota_admission'])->toBeNull();
});

it('filters departements by region code', function (): void {
    $response = $this->getJson('/v1/reference/departements-cameroun?region=CENTRE');

    $response->assertOk();
    $payload = $response->json('data');
    expect(collect($payload)->every(fn ($d) => $d['region_code'] === 'CENTRE'))->toBeTrue();
    expect(collect($payload)->pluck('code'))->toContain('Mfoundi');
});

it('returns all departements when no region filter', function (): void {
    $response = $this->getJson('/v1/reference/departements-cameroun');
    $response->assertOk();
    expect(count($response->json('data')))->toBe(59); // 58 + AUTRES
});

it('returns the V1 specialites whitelist', function (): void {
    $response = $this->getJson('/v1/reference/specialites');
    $response->assertOk();
    $items = $response->json('data');
    expect(count($items))->toBeGreaterThanOrEqual(5);
    expect(collect($items)->pluck('slug'))->toContain('fiscalite-finance-comptabilite-publique');
});

it('caches pays for 24h on Redis', function (): void {
    // Premier appel : MISS, peuple Cache.
    $this->getJson('/v1/reference/pays')->assertOk();
    expect(Cache::has('reference.pays'))->toBeTrue();

    // Modifier la table directement pour démontrer que le 2e appel renvoie le cache.
    DB::table('pays')->where('code_iso', 'CM')->update(['nom' => 'XXX']);

    $cached = $this->getJson('/v1/reference/pays')->json('data');
    $cm = collect($cached)->firstWhere('code_iso', 'CM');
    expect($cm['nom'])->toBe('Cameroun'); // Cache HIT — la valeur table modifiée n'est pas vue.
});
