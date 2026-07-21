<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureDocument;
use App\Models\User;
use App\Services\TestCandidaturePurgeService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;

uses()->group('applications', 'purge');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);
    Storage::fake('minio_candidatures');

    $this->campaign = CampagneCandidature::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->assignRole('super_admin');
    $this->actingAs($this->admin);
});

it('soft deletes an unsubmitted test dossier and purges its account, token and files', function (): void {
    $candidate = User::factory()->candidat()->create();
    $token = $candidate->createToken('recette')->accessToken;
    $candidature = Candidature::factory()->forCampagne($this->campaign)->create([
        'user_id' => $candidate->id,
        'phone_e164' => $candidate->phone_e164,
        'photo_path' => 'test-candidate/photo.jpg',
        'recipisse_pdf_path' => 'test-candidate/recipisse.pdf',
    ]);
    $document = CandidatureDocument::create([
        'candidature_id' => $candidature->id,
        'type' => CandidatureDocument::TYPE_CV,
        'original_filename' => 'cv.pdf',
        'mime' => 'application/pdf',
        'size' => 1234,
        'path' => 'test-candidate/cv.pdf',
    ]);
    Storage::disk('minio_candidatures')->put($candidature->photo_path, 'photo');
    Storage::disk('minio_candidatures')->put($candidature->recipisse_pdf_path, 'pdf');
    Storage::disk('minio_candidatures')->put($document->path, 'document');

    app(TestCandidaturePurgeService::class)->purge($candidature);

    expect(Candidature::withTrashed()->find($candidature->id)?->trashed())->toBeTrue()
        ->and(CandidatureDocument::find($document->id))->toBeNull()
        ->and(User::find($candidate->id))->toBeNull()
        ->and($token->fresh())->toBeNull();
    Storage::disk('minio_candidatures')->assertMissing([
        'test-candidate/photo.jpg',
        'test-candidate/recipisse.pdf',
        'test-candidate/cv.pdf',
    ]);
});

it('refuses to purge a submitted candidature', function (): void {
    $candidate = User::factory()->candidat()->create();
    $candidature = Candidature::factory()->forCampagne($this->campaign)->submitted()->create([
        'user_id' => $candidate->id,
        'phone_e164' => $candidate->phone_e164,
    ]);

    expect(fn () => app(TestCandidaturePurgeService::class)->purge($candidature))
        ->toThrow(RuntimeException::class, 'Postulant jamais soumis');

    expect(Candidature::find($candidature->id))->not->toBeNull()
        ->and(User::find($candidate->id))->not->toBeNull();
});
