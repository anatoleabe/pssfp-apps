<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\DepotPhysiqueService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;

uses()->group('filament', 'depot-physique');

beforeEach(function (): void {
    // La factory Candidature référence pays / région / département par FK.
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);
    config()->set('pssfp.filament.require_2fa', false);

    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->agent = User::factory()->create(['email' => 'guichet@pssfp.local']);
    $this->agent->assignRole('receptionniste');

    $this->service = app(DepotPhysiqueService::class);
});

function candidatureSoumise(array $extra = []): Candidature
{
    return Candidature::factory()->create(array_merge([
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now()->subDays(3),
    ], $extra));
}

it('marks a submitted candidature as physically received', function (): void {
    $candidature = candidatureSoumise(['campagne_id' => $this->campagne->id]);

    $this->service->marquer($candidature, now()->subDay(), $this->agent, 'Enveloppe non timbrée');

    $candidature->refresh();
    expect($candidature->depotPhysiqueRecu())->toBeTrue()
        ->and($candidature->depot_physique_by)->toBe($this->agent->id)
        ->and($candidature->depot_physique_observation)->toBe('Enveloppe non timbrée')
        ->and($candidature->depot_physique_at->isYesterday())->toBeTrue();
});

it('accepts a back-dated reception (agent pointing yesterday\'s pile today)', function (): void {
    $candidature = candidatureSoumise(['campagne_id' => $this->campagne->id]);
    $recuLe = now()->subDays(5)->startOfDay();

    $this->service->marquer($candidature, $recuLe, $this->agent);

    expect($candidature->refresh()->depot_physique_at->toDateString())
        ->toBe($recuLe->toDateString());
});

it('refuses a reception date in the future', function (): void {
    $candidature = candidatureSoumise(['campagne_id' => $this->campagne->id]);

    expect(fn () => $this->service->marquer($candidature, now()->addDay(), $this->agent))
        ->toThrow(ValidationException::class);

    expect($candidature->refresh()->depot_physique_at)->toBeNull();
});

it('refuses to mark a candidature never submitted online', function (): void {
    $candidature = Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'statut' => Candidature::STATUT_POSTULANT,
        'submitted_at' => null,
    ]);

    expect(fn () => $this->service->marquer($candidature, now(), $this->agent))
        ->toThrow(ValidationException::class);
});

it('cancels a reception and clears the agent trail', function (): void {
    $candidature = candidatureSoumise(['campagne_id' => $this->campagne->id]);
    $this->service->marquer($candidature, now(), $this->agent);

    $this->service->annuler($candidature, $this->agent, 'Erreur de dossier');

    $candidature->refresh();
    expect($candidature->depot_physique_at)->toBeNull()
        ->and($candidature->depot_physique_by)->toBeNull()
        ->and($candidature->depot_physique_observation)->toBeNull();
});

it('logs the reception in the activity log', function (): void {
    $candidature = candidatureSoumise(['campagne_id' => $this->campagne->id]);

    $this->service->marquer($candidature, now(), $this->agent);

    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'candidatures',
        'event' => 'depot_physique_marked',
        'causer_id' => $this->agent->id,
    ]);
});

it('scopes separate online submissions from physical receptions', function (): void {
    // 3 soumis en ligne, dont 1 dossier papier reçu ; 1 brouillon jamais soumis.
    candidatureSoumise(['campagne_id' => $this->campagne->id, 'depot_physique_at' => now()]);
    candidatureSoumise(['campagne_id' => $this->campagne->id]);
    candidatureSoumise(['campagne_id' => $this->campagne->id]);
    Candidature::factory()->create([
        'campagne_id' => $this->campagne->id,
        'statut' => Candidature::STATUT_POSTULANT,
        'submitted_at' => null,
    ]);

    $base = fn () => Candidature::query()->forCampagne($this->campagne->id);

    expect($base()->soumises()->count())->toBe(3)
        ->and($base()->deposePhysiquement()->count())->toBe(1)
        ->and($base()->enAttenteDepotPhysique()->count())->toBe(2);
});

it('grants the mark permission to receptionniste but no decision power', function (): void {
    expect($this->agent->can('candidature.mark_depot_physique'))->toBeTrue()
        ->and($this->agent->can('view_any_candidature'))->toBeTrue()
        ->and($this->agent->can('candidature.accept'))->toBeFalse()
        ->and($this->agent->can('candidature.refuse'))->toBeFalse()
        ->and($this->agent->can('update_candidature'))->toBeFalse()
        ->and($this->agent->can('candidature.export_csv'))->toBeFalse();
});

it('lets a receptionniste reach the admin panel', function (): void {
    expect($this->agent->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();
});
