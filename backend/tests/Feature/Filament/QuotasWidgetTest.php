<?php

declare(strict_types=1);

use App\Filament\Widgets\QuotasRegionauxWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'widget', 'quotas');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    $this->cm = User::factory()->create();
    $this->cm->assignRole('admission_committee');
});

it('is visible for admission_committee', function (): void {
    $this->actingAs($this->cm);
    expect(QuotasRegionauxWidget::canView())->toBeTrue();
});

it('is hidden for librarian', function (): void {
    $u = User::factory()->create();
    $u->assignRole('librarian');
    $this->actingAs($u);
    expect(QuotasRegionauxWidget::canView())->toBeFalse();
});

it('returns no rows when no campagne is open', function (): void {
    $this->actingAs($this->cm);
    CampagneCandidature::factory()->closed()->create();

    $data = (new QuotasRegionauxWidget)->getViewData();
    expect($data['campagne'])->toBeNull();
    expect($data['total'])->toBe(0);
});

it('computes per-region count, percent and color tier', function (): void {
    $this->actingAs($this->cm);

    $campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    // CENTRE quota = 15%. On crée 10 candidats : 5 CENTRE (50% réel, écart 35) -> rouge.
    Candidature::factory()->forCampagne($campagne)->count(5)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
    ]);
    Candidature::factory()->forCampagne($campagne)->count(5)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'region' => 'EXTREME-NORD',
        'departement' => 'Diamaré',
    ]);

    $data = (new QuotasRegionauxWidget)->getViewData();
    $rows = $data['rows']->keyBy('code');

    expect($rows['CENTRE']['count'])->toBe(5);
    expect($rows['CENTRE']['real_percent'])->toBe(50.0);
    expect($rows['CENTRE']['target_percent'])->toBe(15.0);
    expect($rows['CENTRE']['gap_points'])->toBe(35.0);
    expect($rows['CENTRE']['color'])->toBe('danger');

    expect($rows['EXTREME-NORD']['count'])->toBe(5);
    expect($rows['EXTREME-NORD']['gap_points'])->toBeGreaterThan(7); // 50% réel vs 18% cible = 32 pts
    expect($rows['EXTREME-NORD']['color'])->toBe('danger');
});

it('returns success tier when gap < 3 points', function (): void {
    $this->actingAs($this->cm);

    $campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    // 100 candidats répartis quasi-conformément
    // CENTRE quota 15% — on en met 14 (14% réel, gap 1 pt) -> vert
    Candidature::factory()->forCampagne($campagne)->count(14)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
    ]);
    // OUEST quota 13% — 14 candidats en plus mais sur région différente
    Candidature::factory()->forCampagne($campagne)->count(86)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'region' => 'OUEST',
        'departement' => 'Mifi',
    ]);

    $data = (new QuotasRegionauxWidget)->getViewData();
    $rows = $data['rows']->keyBy('code');
    expect($rows['CENTRE']['color'])->toBe('success');
});
