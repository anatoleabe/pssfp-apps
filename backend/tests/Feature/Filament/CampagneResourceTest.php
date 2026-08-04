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
            // `nom` est traduisible depuis 2026_08_02 : le formulaire expose
            // `nom.fr` et `nom.en` (ADR-0006).
            'nom.fr' => 'Promotion 15 — 2027',
            'nom.en' => 'Intake 15 — 2027',
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

/*
 * Règle métier : une campagne pilote l'ouverture des candidatures, la
 * numérotation des dossiers et le communiqué publié. Tous les rôles
 * back-office la consultent ; seuls `admin` et `super_admin` l'écrivent.
 */
it('opens campaigns in read-only to every back-office role', function (string $role): void {
    $agent = User::factory()->create(['email' => $role.'-campagne@pssfp.local']);
    $agent->assignRole($role);
    $campagne = CampagneCandidature::factory()->create();

    expect($agent->can('viewAny', CampagneCandidature::class))->toBeTrue()
        ->and($agent->can('view', $campagne))->toBeTrue();
})->with(['admission_committee', 'receptionniste', 'librarian']);

it('reserves campaign writing to admin and super_admin', function (string $role, bool $canWrite): void {
    $agent = User::factory()->create(['email' => $role.'-write@pssfp.local']);
    $agent->assignRole($role);
    $campagne = CampagneCandidature::factory()->create();

    expect($agent->can('create', CampagneCandidature::class))->toBe($canWrite)
        ->and($agent->can('update', $campagne))->toBe($canWrite);
})->with([
    ['super_admin', true],
    ['admin', true],
    ['admission_committee', false],
    ['receptionniste', false],
    ['librarian', false],
]);

it('keeps campaigns read-only even if the write permission is granted by mistake', function (): void {
    $agent = User::factory()->create(['email' => 'comite-permission@pssfp.local']);
    $agent->assignRole('admission_committee');
    $agent->givePermissionTo(['create_campagne::candidature', 'update_campagne::candidature']);
    $campagne = CampagneCandidature::factory()->create();

    // Le verrou par rôle tient : la permission seule ne suffit pas.
    expect($agent->fresh()->can('create', CampagneCandidature::class))->toBeFalse()
        ->and($agent->fresh()->can('update', $campagne))->toBeFalse();
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
