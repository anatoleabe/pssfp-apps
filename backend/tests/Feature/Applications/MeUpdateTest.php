<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('applications');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);
    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

function authedCandidat(?string $dob = '1995-06-15'): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691111222',
        'phone_country' => 'CM',
        'date_naissance' => $dob,
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token];
}

it('returns 401 when unauthenticated', function (): void {
    $this->putJson('/v1/applications/me', [])->assertStatus(401);
});

it('returns 403 when token lacks application:create ability', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('limited', ['profile:read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', ['civilite' => 'M.']);

    expect($response->status())->toBeIn([401, 403]);
});

it('first PUT creates a postulant Candidature linked to user_id and current campagne', function (): void {
    [$user, $token] = authedCandidat();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', [
            'civilite' => 'M.',
            'lieu_naissance' => 'Yaoundé',
            'specialite' => config('specialites.fiscalite-finance-comptabilite-publique'),
        ]);

    $response->assertOk();
    $response->assertJsonPath('data.statut', 'postulant');
    $response->assertJsonPath('data.civilite', 'M.');
    $response->assertJsonPath('data.specialite', config('specialites.fiscalite-finance-comptabilite-publique'));

    $cand = Candidature::query()
        ->where('user_id', $user->id)
        ->where('campagne_id', $this->campagne->id)
        ->first();
    expect($cand)->not->toBeNull();
    expect($cand->numero_dossier)->toMatch('/^P\d+-\d{3}$/');
    expect((string) $cand->date_naissance->toDateString())->toBe('1995-06-15');
});

it('subsequent PUT updates partial fields without resetting others', function (): void {
    [$user, $token] = authedCandidat();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->putJson('/v1/applications/me', [
        'civilite' => 'M.',
        'genre' => 'M',
        'specialite' => array_values((array) config('specialites'))[0],
    ])->assertOk();

    $second = $this->withHeaders($headers)->putJson('/v1/applications/me', [
        'lieu_naissance' => 'Douala',
    ]);

    $second->assertOk();
    $second->assertJsonPath('data.lieu_naissance', 'Douala');
    $second->assertJsonPath('data.civilite', 'M.');
    $second->assertJsonPath('data.specialite', array_values((array) config('specialites'))[0]);
});

it('returns 422 on bad ISO-2 country code', function (): void {
    [$user, $token] = authedCandidat();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', [
            'pays_residence' => 'XX',
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['pays_residence']);
});

it('returns 409 when trying to update an already submitted candidature', function (): void {
    [$user, $token] = authedCandidat();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'M.']);
    Candidature::query()->where('user_id', $user->id)->update([
        'statut' => Candidature::STATUT_CANDIDAT,
        'submitted_at' => now(),
    ]);

    $second = $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'Mme']);
    $second->assertStatus(409);
});

it('cannot edit numero_dossier or campagne_id from the request body (P-min-4 ownership)', function (): void {
    [$user, $token] = authedCandidat();
    $other = CampagneCandidature::factory()->create();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', [
            'civilite' => 'M.',
            'numero_dossier' => 'HACKED-999',
            'campagne_id' => $other->id,
            'user_id' => 999,
            'statut' => 'accepte',
        ]);

    $response->assertOk();
    $cand = Candidature::query()->where('user_id', $user->id)->first();
    expect($cand->numero_dossier)->not->toBe('HACKED-999');
    expect($cand->campagne_id)->toBe($this->campagne->id);
    expect($cand->user_id)->toBe($user->id);
    expect($cand->statut)->toBe('postulant');
});

it('candidat A cannot read candidat B candidature (auth-scoped /me)', function (): void {
    [$userA, $tokenA] = authedCandidat();
    $userB = User::factory()->candidat()->create();
    Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $userB->id,
        'phone_e164' => $userB->phone_e164,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$tokenA}")
        ->getJson('/v1/applications/me');
    // userA n'a pas de Candidature -> 404
    $response->assertStatus(404);
});

it('GET /me returns 404 when no candidature yet', function (): void {
    [$user, $token] = authedCandidat();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/applications/me');

    $response->assertStatus(404);
});

it('GET /me returns the existing candidature', function (): void {
    [$user, $token] = authedCandidat();
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', ['civilite' => 'M.']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/applications/me');

    $response->assertOk();
    $response->assertJsonPath('data.statut', 'postulant');
    $response->assertJsonPath('data.civilite', 'M.');
});
