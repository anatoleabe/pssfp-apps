<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\CreateCampagneCandidature;
use App\Filament\Resources\CampagneCandidatureResource\Pages\ListCampagneCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'campagne');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('lets super_admin create a campagne', function (): void {
    $this->actingAs($this->superAdmin);
    $this->livewire(CreateCampagneCandidature::class)
        ->fillForm([
            'slug' => 'p15-2027',
            'nom' => 'Promotion 15 — 2027',
            'promotion_numero' => 15,
            'prefix_numero' => 'P15027-',
            'status' => 'draft',
            'opens_at' => now()->addMonth()->toDateTimeString(),
            'closes_at' => now()->addMonths(4)->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CampagneCandidature::where('slug', 'p15-2027')->exists())->toBeTrue();
});

it('forbids editor from creating a campagne (no permission)', function (): void {
    expect($this->editor->can('create_campagne::candidature'))->toBeFalse();
});

it('counts candidatures per campagne in the list', function (): void {
    $c = CampagneCandidature::factory()->create();
    Candidature::factory()->forCampagne($c)->count(2)->create([
        'user_id' => fn () => User::factory()->candidat()->create()->id,
    ]);

    $this->actingAs($this->superAdmin);
    $this->livewire(ListCampagneCandidatures::class)
        ->assertCanSeeTableRecords([$c]);
});
