<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;

uses()->group('applications');

it('returns the open campagne when one is open', function (): void {
    CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'nom' => 'Année académique 2026-2027',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $response = $this->getJson('/v1/applications/campaigns/current');

    $response->assertOk();
    $response->assertJsonPath('data.slug', 'p14-2026');
    $response->assertJsonPath('data.is_currently_open', true);
    $response->assertJsonMissingPath('data.prefix_numero');
});

it('returns data:null when no campagne is open', function (): void {
    CampagneCandidature::factory()->closed()->create();
    CampagneCandidature::factory()->draft()->create();

    $response = $this->getJson('/v1/applications/campaigns/current');

    $response->assertOk();
    $response->assertJsonPath('data', null);
});

it('does not expose draft campagnes', function (): void {
    CampagneCandidature::factory()->draft()->create([
        'slug' => 'p15-2027',
    ]);

    $response = $this->getJson('/v1/applications/campaigns/current');
    $response->assertOk();
    $response->assertJsonPath('data', null);
});

it('does not expose closed campagnes', function (): void {
    CampagneCandidature::factory()->closed()->create([
        'slug' => 'p13-2025',
    ]);

    $response = $this->getJson('/v1/applications/campaigns/current');
    $response->assertOk();
    $response->assertJsonPath('data', null);
});
