<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Activitylog\Models\Activity;

uses()->group('applications', 'withdraw');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

function authedCandidatWithDossier(CampagneCandidature $campagne, string $statut = 'postulant'): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
    ]);

    Candidature::factory()->forCampagne($campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => $user->phone_e164,
        'statut' => $statut,
        'submitted_at' => $statut === 'postulant' ? null : now(),
    ]);

    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token];
}

it('returns 401 when unauthenticated', function (): void {
    $this->postJson('/v1/applications/me/withdraw', ['confirmation' => true])
        ->assertStatus(401);
});

it('returns 403 when token lacks application:create ability', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('reduced', ['profile:read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    expect($response->status())->toBeIn([401, 403]);
});

it('returns 422 when confirmation is missing', function (): void {
    [, $token] = authedCandidatWithDossier($this->campagne);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['confirmation']);
});

it('returns 404 when no candidature exists for the user', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('candidat', ['application:create'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertStatus(404);
});

it('withdraws an active candidature in postulant status', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'postulant');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertOk();
    $cand = Candidature::where('user_id', $user->id)->first();
    expect($cand->withdrawn_at)->not->toBeNull();
});

it('withdraws an active candidature in candidat status', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'candidat');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertOk();
    expect(Candidature::where('user_id', $user->id)->first()->withdrawn_at)->not->toBeNull();
});

it('returns 409 when already withdrawn', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'postulant');
    Candidature::where('user_id', $user->id)->update(['withdrawn_at' => now()->subDay()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertStatus(409);
    expect($response->json('kind'))->toBe('already_withdrawn');
});

it('returns 409 when already accepted (admin decision)', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'accepte');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertStatus(409);
    expect($response->json('kind'))->toBe('already_decided');
});

it('returns 409 when already refused (admin decision)', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'refuse');

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $response->assertStatus(409);
});

it('writes a candidature_withdrawn_self activity log entry', function (): void {
    [$user, $token] = authedCandidatWithDossier($this->campagne, 'candidat');

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/withdraw', ['confirmation' => true]);

    $log = Activity::query()
        ->where('event', 'candidature_withdrawn_self')
        ->latest()
        ->first();
    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($user->id);
    expect($log->properties->get('previous_statut'))->toBe('candidat');
});
