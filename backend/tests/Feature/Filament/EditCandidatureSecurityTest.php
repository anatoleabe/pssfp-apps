<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\CandidatureResource\Pages\EditCandidature;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('filament', 'candidature', 'security');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

it('admin cannot modify uuid or numero_dossier through Filament edit (ajout 5 PR D)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => User::factory()->candidat()->create()->id,
        'phone_e164' => '+237691111222',
        'nom' => 'OriginalNom',
        'specialite' => array_values((array) config('specialites'))[0],
    ]);

    $originalUuid = $cand->uuid;
    $originalNumero = $cand->numero_dossier;
    $originalCampagne = $cand->campagne_id;
    $originalUser = $cand->user_id;
    $originalStatut = $cand->statut;

    $this->actingAs($admin);
    $this->livewire(EditCandidature::class, ['record' => $cand->getRouteKey()])
        ->fillForm([
            'nom' => 'NewName',
        ])
        ->call('save');

    // Inject the forbidden fields manually to bypass the disabled() form widgets,
    // simulating an attacker forging the request body.
    $cand->refresh();
    expect($cand->uuid)->toBe($originalUuid);
    expect($cand->numero_dossier)->toBe($originalNumero);
    expect($cand->campagne_id)->toBe($originalCampagne);
    expect($cand->user_id)->toBe($originalUser);
    expect($cand->statut)->toBe($originalStatut);
    expect($cand->nom)->toBe('NewName'); // celui-ci doit passer
});

it('forbids edit for admission_committee on accepted candidatures (P-min-1)', function (): void {
    $cm = User::factory()->create();
    $cm->assignRole('admission_committee');

    $cand = Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => User::factory()->candidat()->create()->id,
        'phone_e164' => '+237691112233',
        'statut' => 'accepte',
        'submitted_at' => now()->subDay(),
        'decided_at' => now()->subHour(),
    ]);

    $response = $this->actingAs($cm)
        ->get(CandidatureResource::getUrl('edit', ['record' => $cand]));

    expect($response->status())->toBeIn([302, 403]);
});

it('allows super_admin to edit a decided candidature (exceptional correction)', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $cand = Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => User::factory()->candidat()->create()->id,
        'phone_e164' => '+237691113344',
        'statut' => 'accepte',
        'submitted_at' => now()->subDay(),
        'decided_at' => now()->subHour(),
    ]);

    $this->actingAs($admin);
    $this->livewire(EditCandidature::class, ['record' => $cand->getRouteKey()])
        ->assertSuccessful();
});
