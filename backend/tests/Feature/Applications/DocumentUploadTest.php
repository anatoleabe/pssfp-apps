<?php

declare(strict_types=1);

use App\Jobs\ScanUploadedDocumentJob;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureDocument;
use App\Models\User;
use App\Services\RecipisseService;
use App\Services\Scanner\PhotoScannerInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses()->group('applications', 'documents');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    Storage::fake('minio_candidatures');

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

function authedCandidatDoc(CampagneCandidature $campagne, string $statut = 'postulant'): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
    ]);

    $candidature = Candidature::factory()->forCampagne($campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => $user->phone_e164,
        'statut' => $statut,
        'submitted_at' => $statut === 'postulant' ? null : now(),
    ]);

    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token, $candidature];
}

it('returns 401 when uploading a document unauthenticated', function (): void {
    $this->postJson('/v1/applications/me/documents')->assertStatus(401);
});

it('uploads a diplome PDF, persists the record, dispatches scan job and writes activity log', function (): void {
    Bus::fake();
    [$user, $token, $cand] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('diplome.pdf', 200, 'application/pdf');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'diplome', 'fichier' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(201);
    $response->assertJsonStructure(['data' => ['uuid', 'type', 'original_filename', 'size', 'url', 'uploaded_at']]);
    expect($response->json('data.type'))->toBe('diplome');

    $document = CandidatureDocument::query()->where('candidature_id', $cand->id)->first();
    expect($document)->not->toBeNull();
    expect($document->path)->toStartWith("candidat-documents/{$cand->uuid}/");
    expect($document->path)->toEndWith('.pdf');
    Storage::disk('minio_candidatures')->assertExists($document->path);

    Bus::assertDispatched(ScanUploadedDocumentJob::class, function ($job) use ($document): bool {
        return $job->documentUuid === $document->uuid && $job->path === $document->path;
    });

    $log = Activity::query()->where('event', 'candidate_document_uploaded')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($user->id);
});

it('allows uploading several documents of the same type (ex: relevés L1/L2/L3)', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatDoc($this->campagne);

    foreach (['l1.pdf', 'l2.pdf', 'l3.pdf'] as $name) {
        $file = UploadedFile::fake()->create($name, 100, 'application/pdf');
        $this->withHeader('Authorization', "Bearer {$token}")
            ->post('/v1/applications/me/documents', ['type' => 'releves_notes', 'fichier' => $file], ['Accept' => 'application/json'])
            ->assertStatus(201);
    }

    expect(CandidatureDocument::query()->where('candidature_id', $cand->id)->where('type', 'releves_notes')->count())->toBe(3);
});

it('rejects an unknown document type with 422', function (): void {
    [, $token] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('x.pdf', 100, 'application/pdf');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'passeport', 'fichier' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['type']);
});

it('rejects a file larger than 5 MB with 422', function (): void {
    [, $token] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('big.pdf', 6144, 'application/pdf'); // 6 MB

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['fichier']);
});

it('rejects an unsupported MIME type with 422', function (): void {
    [, $token] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('archive.zip', 100, 'application/zip');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['fichier']);
});

it('returns 409 when uploading a document after submission (statut != postulant)', function (): void {
    [, $token] = authedCandidatDoc($this->campagne, 'candidat');
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(409);
});

it('GET /me includes the documents array', function (): void {
    Bus::fake();
    [, $token] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/v1/applications/me');

    $response->assertStatus(200);
    expect($response->json('data.documents'))->toHaveCount(1);
    expect($response->json('data.documents.0.type'))->toBe('cv');
});

it('DELETE /me/documents/{uuid} removes the file and the record in postulant status', function (): void {
    Bus::fake();
    [, $token] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $upload = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);

    $uuid = $upload->json('data.uuid');
    $document = CandidatureDocument::query()->where('uuid', $uuid)->firstOrFail();
    Storage::disk('minio_candidatures')->assertExists($document->path);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/v1/applications/me/documents/{$uuid}");

    $response->assertStatus(204);
    expect(CandidatureDocument::query()->where('uuid', $uuid)->exists())->toBeFalse();
    Storage::disk('minio_candidatures')->assertMissing($document->path);
});

it('DELETE /me/documents/{uuid} refuses with 409 once the dossier is locked (statut candidat)', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatDoc($this->campagne);
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $upload = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);
    $uuid = $upload->json('data.uuid');

    $cand->refresh()->update(['statut' => 'candidat', 'submitted_at' => now()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/v1/applications/me/documents/{$uuid}");

    $response->assertStatus(409);
});

it('does not allow candidat A to delete a document belonging to candidat B (ownership / anti-IDOR)', function (): void {
    Bus::fake();
    [, $tokenA] = authedCandidatDoc($this->campagne);
    [, $tokenB] = authedCandidatDoc($this->campagne);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $upload = $this->withHeader('Authorization', "Bearer {$tokenB}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json']);
    $uuidB = $upload->json('data.uuid');

    // Laravel met en cache le guard Sanctum résolu (Illuminate\Auth\RequestGuard
    // mémorise le user après la 1ère résolution) — sans ce reset, la 2e requête
    // du même test (avec tokenA) hériterait à tort de l'utilisateur B résolu
    // par la requête précédente. Sans impact en production : chaque requête
    // HTTP réelle boote un process/container neuf, cette mise en cache ne
    // survit jamais entre deux utilisateurs différents.
    $this->app['auth']->forgetGuards();

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->deleteJson("/v1/applications/me/documents/{$uuidB}");

    $response->assertStatus(404);
    expect(CandidatureDocument::query()->where('uuid', $uuidB)->exists())->toBeTrue();
});

it('quarantines an EICAR document via ScanUploadedDocumentJob (Noop scanner detects EICAR)', function (): void {
    [, $token] = authedCandidatDoc($this->campagne);
    Bus::fake([ScanUploadedDocumentJob::class]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $upload = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/documents', ['type' => 'cv', 'fichier' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $uuid = $upload->json('data.uuid');
    $document = CandidatureDocument::query()->where('uuid', $uuid)->firstOrFail();

    Storage::disk('minio_candidatures')->put(
        $document->path,
        'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
    );

    Bus::assertDispatched(ScanUploadedDocumentJob::class);

    (new ScanUploadedDocumentJob($document->uuid, $document->path))->handle(app(PhotoScannerInterface::class));

    expect(CandidatureDocument::query()->where('uuid', $uuid)->exists())->toBeFalse();
    Storage::disk('minio_candidatures')->assertMissing($document->path);

    $log = Activity::query()->where('event', 'candidate_document_quarantined')->latest()->first();
    expect($log)->not->toBeNull();
});

it('does not block submission when no documents were uploaded (non-blocking by design)', function (): void {
    // Payload complet auto-suffisant (ne dépend pas des helpers d'un autre
    // fichier de test — sous --parallel, chaque fichier tourne dans un
    // process ParaTest distinct et les fonctions ne sont pas partagées).
    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('generate')->andReturn([
            'path' => 'fake-uuid/recipisse.pdf',
            'hash' => str_repeat('a', 64),
        ]);
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/fake-uuid');
        $mock->shouldReceive('pathFor')->andReturn('fake-uuid/recipisse.pdf');
    });

    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691222333',
        'phone_country' => 'CM',
        'date_naissance' => '1995-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    $profile = [
        'civilite' => 'M.',
        'lieu_naissance' => 'Yaoundé',
        'genre' => 'M',
        'statut_matrimonial' => 'Célibataire',
        'nationalite' => 'CM',
        'pays_origine' => 'CM',
        'pays_residence' => 'CM',
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
        'adresse' => 'BP 1234 Yaoundé',
        'ville_residence' => 'Yaoundé',
        'indicatif1' => '+237',
        'telephone1' => '691222333',
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Économie',
        'annee_diplome' => 2024,
        'statut_actuel' => 'Etudiant',
        'engagement_nom' => 'Jean Dupont',
    ];

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', $profile)
        ->assertOk();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertOk();
    $response->assertJsonPath('data.statut', 'candidat');

    $cand = Candidature::where('user_id', $user->id)->first();
    expect($cand->statut)->toBe('candidat');
    expect(CandidatureDocument::query()->where('candidature_id', $cand->id)->count())->toBe(0);
});
