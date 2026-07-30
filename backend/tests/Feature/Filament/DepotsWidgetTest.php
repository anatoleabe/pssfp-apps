<?php

declare(strict_types=1);

use App\Filament\Widgets\DepotsCampagneWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'widget', 'depot-physique');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

function widgetUser(string $role): User
{
    $user = User::factory()->create(['email' => $role.'-depots@pssfp.local']);
    $user->assignRole($role);

    return $user;
}

it('is visible for the receptionniste who feeds it', function (): void {
    $this->actingAs(widgetUser('receptionniste'));

    expect(DepotsCampagneWidget::canView())->toBeTrue();
});

it('is hidden for a candidat', function (): void {
    $this->actingAs(User::factory()->candidat()->create());

    expect(DepotsCampagneWidget::canView())->toBeFalse();
});

it('renders the online and physical deposit counts', function (): void {
    // 4 soumis en ligne : 2 dossiers papier reçus, 2 en attente.
    Candidature::factory()->count(2)->create([
        'campagne_id' => $this->campagne->id,
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now()->subDays(2),
        'depot_physique_at' => now()->subDay(),
    ]);
    Candidature::factory()->count(2)->create([
        'campagne_id' => $this->campagne->id,
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now()->subDays(2),
    ]);
    // Brouillon jamais soumis : ne doit compter nulle part.
    Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'statut' => Candidature::STATUT_POSTULANT,
        'submitted_at' => null,
    ]);

    $this->actingAs(widgetUser('admin'));

    $this->livewire(DepotsCampagneWidget::class)
        ->assertSuccessful()
        ->assertSee('Candidatures déposées en ligne')
        ->assertSee('Dossiers déposés physiquement')
        ->assertSee('En attente de dépôt physique')
        ->assertSee('50%');
});

it('renders without a campagne', function (): void {
    CampagneCandidature::query()->delete();

    $this->actingAs(widgetUser('admin'));

    $this->livewire(DepotsCampagneWidget::class)
        ->assertSuccessful()
        ->assertSee('Aucune campagne');
});
