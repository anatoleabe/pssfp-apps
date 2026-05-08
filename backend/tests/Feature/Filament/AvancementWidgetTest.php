<?php

declare(strict_types=1);

use App\Filament\Widgets\AvancementCampagneWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'widget', 'avancement');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);
});

it('is visible for librarian (general overview)', function (): void {
    $u = User::factory()->create();
    $u->assignRole('librarian');
    $this->actingAs($u);

    expect(AvancementCampagneWidget::canView())->toBeTrue();
});

it('is hidden for candidat', function (): void {
    $u = User::factory()->candidat()->create();
    $this->actingAs($u);
    expect(AvancementCampagneWidget::canView())->toBeFalse();
});

it('renders successfully when no campagne is open', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');

    CampagneCandidature::factory()->draft()->create();

    $this->actingAs($u);
    $this->livewire(AvancementCampagneWidget::class)
        ->assertSuccessful();
});

it('renders successfully with the 4 stats when a campagne is open', function (): void {
    $u = User::factory()->create();
    $u->assignRole('admin');

    $campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addDays(20),
    ]);
    Candidature::factory()->forCampagne($campagne)->count(2)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'statut' => 'postulant', 'frais_paye' => false,
    ]);
    Candidature::factory()->forCampagne($campagne)->submitted()->count(3)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
        'frais_paye' => true,
    ]);

    $this->actingAs($u);
    $this->livewire(AvancementCampagneWidget::class)
        ->assertSuccessful()
        ->assertSee('Total dossiers')
        ->assertSee('Taux frais payés');
});
