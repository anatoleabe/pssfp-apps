<?php

declare(strict_types=1);

use App\Events\CandidatureAccepted;
use App\Events\CandidatureRefused;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\RecipisseService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

uses()->group('filament', 'candidature');

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
        'prefix_numero' => 'P14026-',
    ]);

    $this->admissionCommittee = User::factory()->create();
    $this->admissionCommittee->assignRole('admission_committee');

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');

    $this->candidat = User::factory()->candidat()->create();
});

it('shows the list page for admission_committee', function (): void {
    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->assertSuccessful();
});

it('forbids the list page for candidat role', function (): void {
    $response = $this->actingAs($this->candidat)->get(CandidatureResource::getUrl('index'));
    expect($response->status())->toBeIn([302, 403, 404]);
});

it('disables creation entirely (canCreate=false)', function (): void {
    expect(CandidatureResource::canCreate())->toBeFalse();
});

it('lists candidatures with the expected columns', function (): void {
    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
        'specialite' => array_values((array) config('specialites'))[0],
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->assertCanSeeTableRecords([$cand])
        ->assertCanRenderTableColumn('numero_dossier')
        ->assertCanRenderTableColumn('statut')
        ->assertCanRenderTableColumn('frais_paye');
});

it('filters by campagne and statut', function (): void {
    $other = CampagneCandidature::factory()->create();
    $a = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
    ]);
    $b = Candidature::factory()->forCampagne($other)->create([
        'user_id' => User::factory()->candidat()->create()->id,
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->filterTable('campagne_id', $this->campagne->id)
        ->assertCanSeeTableRecords([$a])
        ->assertCanNotSeeTableRecords([$b]);
});

it('accepts a candidature and dispatches CandidatureAccepted event', function (): void {
    Event::fake([CandidatureAccepted::class]);
    Mail::fake();

    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
        'email' => 'jean@example.com',
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('accept', $cand, ['internal_comment' => 'Excellent dossier']);

    expect($cand->fresh()->statut)->toBe('accepte');
    expect($cand->fresh()->decided_at)->not->toBeNull();
    Event::assertDispatched(CandidatureAccepted::class, fn ($e) => $e->candidature->id === $cand->id);
});

it('refuses a candidature with a required motif', function (): void {
    Event::fake([CandidatureRefused::class]);

    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('refuse', $cand, ['motif' => 'Niveau de diplôme insuffisant pour cette campagne.']);

    expect($cand->fresh()->statut)->toBe('refuse');
    Event::assertDispatched(CandidatureRefused::class);
});

it('rejects refuse action when motif is too short', function (): void {
    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('refuse', $cand, ['motif' => 'Trop court'])
        ->assertHasTableActionErrors(['motif']);
});

it('marks frais payés with mode + reference + date and writes an activity entry', function (): void {
    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
        'frais_paye' => false,
    ]);

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('markPaid', $cand, [
            'mode_paiement' => 'cca_agence',
            'reference_paiement' => 'CCA-2026-0042',
            'date_paiement' => now()->toDateString(),
        ]);

    $cand->refresh();
    expect($cand->frais_paye)->toBeTrue();
    expect($cand->reference_paiement)->toBe('CCA-2026-0042');

    $log = Activity::latest('id')->first();
    expect($log->event)->toBe('frais_marked_paid');
});

it('logs an admin_download activity when admin views the recipisse', function (): void {
    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
        'recipisse_pdf_path' => 'fake-uuid/recipisse.pdf',
    ]);

    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/abc');
    });

    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->callTableAction('viewRecipisse', $cand);

    $log = Activity::query()
        ->where('event', 'recipisse_downloaded_by_admin')
        ->latest('id')->first();
    expect($log)->not->toBeNull();
    expect($log->properties->get('type'))->toBe('admin_download');
});

it('forbids accept action for librarian (no candidature.accept)', function (): void {
    $librarian = User::factory()->create();
    $librarian->assignRole('librarian');

    $cand = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'user_id' => $this->candidat->id,
        'phone_e164' => $this->candidat->phone_e164,
    ]);

    $this->actingAs($librarian);
    $this->livewire(ListCandidatures::class)
        ->assertTableActionHidden('accept', $cand);
});

it('hides bulk accept/refuse for non-super_admin', function (): void {
    $this->actingAs($this->admissionCommittee);
    $this->livewire(ListCandidatures::class)
        ->assertTableBulkActionHidden('acceptBulk')
        ->assertTableBulkActionHidden('refuseBulk');
});

it('logs the full numero list when bulk accepting (super_admin)', function (): void {
    Event::fake([CandidatureAccepted::class]);

    $cands = Candidature::factory()->forCampagne($this->campagne)->submitted()
        ->count(3)->create([
            'user_id' => fn () => User::factory()->candidat()->create()->id,
        ]);

    $this->actingAs($this->superAdmin);
    $this->livewire(ListCandidatures::class)
        ->callTableBulkAction('acceptBulk', $cands);

    $log = Activity::query()->where('event', 'candidature_accepted_bulk')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->properties->get('count'))->toBe(3);
    expect($log->properties->get('numeros'))->toHaveCount(3);
});
