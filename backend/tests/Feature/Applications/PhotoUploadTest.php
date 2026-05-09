<?php

declare(strict_types=1);

use App\Jobs\ScanUploadedPhotoJob;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses()->group('applications', 'photo');

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

function authedCandidatPhoto(CampagneCandidature $campagne, string $statut = 'postulant'): array
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

it('returns 401 when uploading photo unauthenticated', function (): void {
    $this->postJson('/v1/applications/me/photo')->assertStatus(401);
});

it('returns 403 when token lacks application:create ability', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('reduced', ['profile:read'])->plainTextToken;
    $file = UploadedFile::fake()->image('photo.jpg', 400, 400);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    expect($response->status())->toBeIn([401, 403]);
});

it('uploads a JPG, persists path, dispatches scan job and writes activity log', function (): void {
    Bus::fake();
    [$user, $token, $cand] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->image('me.jpg', 400, 400);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(201);
    $response->assertJsonStructure(['data' => ['photo_path', 'photo_url']]);

    $cand = $cand->refresh();
    expect($cand->photo_path)->not->toBeNull();
    expect($cand->photo_path)->toStartWith("candidat-photos/{$cand->uuid}/");
    expect($cand->photo_path)->toEndWith('.jpg');
    Storage::disk('minio_candidatures')->assertExists($cand->photo_path);

    Bus::assertDispatched(ScanUploadedPhotoJob::class, function ($job) use ($cand): bool {
        return $job->candidatureUuid === $cand->uuid && $job->path === $cand->photo_path;
    });

    $log = Activity::query()->where('event', 'candidate_photo_uploaded')->latest()->first();
    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($user->id);
});

it('rejects a file larger than 2 MB with 422', function (): void {
    [, $token] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->image('big.jpg', 400, 400)->size(3072); // 3 MB

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['photo']);
});

it('rejects a non-image MIME (PDF) with 422', function (): void {
    [, $token] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['photo']);
});

it('rejects an image smaller than 200x200 pixels with 422', function (): void {
    [, $token] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->image('tiny.jpg', 100, 100);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['photo']);
});

it('returns 409 when uploading after submission (statut != postulant)', function (): void {
    [, $token] = authedCandidatPhoto($this->campagne, 'candidat');
    $file = UploadedFile::fake()->image('me.jpg', 400, 400);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

    $response->assertStatus(409);
});

it('replaces an existing photo and deletes the previous file', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatPhoto($this->campagne);

    $first = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $first], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $previousPath = $cand->refresh()->photo_path;
    expect($previousPath)->not->toBeNull();
    Storage::disk('minio_candidatures')->assertExists($previousPath);

    $second = UploadedFile::fake()->image('me-2.png', 500, 500);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $second], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $newPath = $cand->refresh()->photo_path;
    expect($newPath)->not->toBe($previousPath);
    Storage::disk('minio_candidatures')->assertExists($newPath);
    Storage::disk('minio_candidatures')->assertMissing($previousPath);
});

it('GET /me/photo returns 302 redirect to a signed URL', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->get('/v1/applications/me/photo');

    expect($response->status())->toBeIn([302, 301]);
    expect($response->headers->get('Location'))->toContain($cand->refresh()->uuid);
});

it('GET /me/photo returns 404 when no photo uploaded yet', function (): void {
    [, $token] = authedCandidatPhoto($this->campagne);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/applications/me/photo');

    $response->assertStatus(404);
});

it('DELETE /me/photo removes the file in postulant status', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatPhoto($this->campagne);
    $file = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $path = $cand->refresh()->photo_path;
    Storage::disk('minio_candidatures')->assertExists($path);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/v1/applications/me/photo');

    $response->assertStatus(204);
    expect($cand->refresh()->photo_path)->toBeNull();
    Storage::disk('minio_candidatures')->assertMissing($path);
});

it('DELETE /me/photo refuses with 409 once the dossier is locked (statut candidat)', function (): void {
    Bus::fake();
    [, $token, $cand] = authedCandidatPhoto($this->campagne);

    // upload une photo en postulant, puis bascule statut
    $file = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $cand->refresh()->update(['statut' => 'candidat', 'submitted_at' => now()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->deleteJson('/v1/applications/me/photo');

    $response->assertStatus(409);
});

it('quarantines an EICAR file via ScanUploadedPhotoJob (Noop scanner detects EICAR)', function (): void {
    [, $token, $cand] = authedCandidatPhoto($this->campagne);

    // On crée un fichier image valide en validation (200x200) puis on
    // remplace son contenu par la signature EICAR via le disk MinIO fake.
    Bus::fake([\App\Jobs\ScanUploadedPhotoJob::class]);

    $file = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$token}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    $path = $cand->refresh()->photo_path;
    Storage::disk('minio_candidatures')->put(
        $path,
        'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
    );

    Bus::assertDispatched(ScanUploadedPhotoJob::class);

    // Run the job manually for the test
    (new ScanUploadedPhotoJob($cand->uuid, $path))->handle(app(\App\Services\Scanner\PhotoScannerInterface::class));

    expect($cand->refresh()->photo_path)->toBeNull();
    Storage::disk('minio_candidatures')->assertMissing($path);

    $log = Activity::query()->where('event', 'candidate_photo_quarantined')->latest()->first();
    expect($log)->not->toBeNull();
});

it('does not allow a candidat A to upload a photo for candidat B (ownership)', function (): void {
    Bus::fake();
    // Two distinct candidats
    [$userA, $tokenA, $candA] = authedCandidatPhoto($this->campagne);
    [, , $candB] = authedCandidatPhoto($this->campagne);

    $file = UploadedFile::fake()->image('me.jpg', 400, 400);
    $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->post('/v1/applications/me/photo', ['photo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(201);

    // candA est upserted; candB doit rester vierge
    expect($candA->refresh()->photo_path)->not->toBeNull();
    expect($candB->refresh()->photo_path)->toBeNull();
});
