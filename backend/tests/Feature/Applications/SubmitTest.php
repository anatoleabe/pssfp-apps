<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\RecipisseService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

uses()->group('applications', 'submit');

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
        'prefix_numero' => 'P14026-',
    ]);

    Storage::fake('minio_candidatures');

    // Mock le RecipisseService pour ne pas faire tourner dompdf à chaque test.
    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('generate')->andReturn([
            'path' => 'fake-uuid/recipisse.pdf',
            'hash' => str_repeat('a', 64),
        ]);
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/fake-uuid');
        $mock->shouldReceive('pathFor')->andReturn('fake-uuid/recipisse.pdf');
    });
});

function fullProfilePayload(): array
{
    $first = array_values((array) config('specialites'))[0];

    return [
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
        'telephone1' => '691111222',
        'specialite' => $first,
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Économie',
        'annee_diplome' => 2024,
        'statut_actuel' => 'Etudiant',
        'engagement_nom' => 'Jean Dupont',
    ];
}

function authedFullCandidat(CampagneCandidature $campagne): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691111222',
        'phone_country' => 'CM',
        'date_naissance' => '1995-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    test()->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', fullProfilePayload())
        ->assertOk();

    return [$user, $token];
}

it('submits a complete candidature and transitions to candidat', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertOk();
    $response->assertJsonPath('data.statut', 'candidat');
    $response->assertJsonPath('recipisse_url', 'https://signed.example/fake-uuid');

    $cand = Candidature::where('user_id', $user->id)->first();
    expect($cand->statut)->toBe('candidat');
    expect($cand->submitted_at)->not->toBeNull();
    expect($cand->recipisse_pdf_path)->toBe('fake-uuid/recipisse.pdf');
    expect($cand->recipisse_hash_sha256)->toBe(str_repeat('a', 64));
});

it('returns 422 with field list when profile is incomplete', function (): void {
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691111223',
        'date_naissance' => '1995-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'application:create', 'application:submit', 'profile:write', 'application:read',
    ])->plainTextToken;

    // Crée la Candidature avec très peu de champs
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', ['civilite' => 'M.']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('specialite');
    expect($response->json('errors'))->toHaveKey('engagement_nom');
});

it('returns 422 when confirmation_engagement is missing', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', []);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['confirmation_engagement']);
});

it('returns 409 when already submitted (idempotency-key absent)', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $second->assertStatus(409);
});

it('replays the same response when X-Idempotency-Key matches within 5 min', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);
    $key = 'idem-test-key-1';

    $first = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Idempotency-Key', $key)
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);
    $first->assertOk();

    $second = $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Idempotency-Key', $key)
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $second->assertOk();
    expect($second->json('recipisse_url'))->toBe($first->json('recipisse_url'));
});

it('rejects submit when annee_diplome is in the future', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);

    Candidature::where('user_id', $user->id)->update(['annee_diplome' => now()->year + 1]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('annee_diplome');
});

it('rejects submit when CM resident has no region or departement', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);
    Candidature::where('user_id', $user->id)->update([
        'pays_residence' => 'CM',
        'region' => null,
        'departement' => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('region');
    expect($response->json('errors'))->toHaveKey('departement');
});

it('rejects submit when specialite is not in the whitelist', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);
    Candidature::where('user_id', $user->id)->update(['specialite' => 'Inventée']);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toHaveKey('specialite');
});

it('writes an activity_log entry on successful submit', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    $log = Activity::query()
        ->where('log_name', 'candidatures')
        ->where('event', 'candidature_submitted')
        ->latest()
        ->first();

    expect($log)->not->toBeNull();
    expect($log->causer_id)->toBe($user->id);
    expect($log->subject_type)->toBe(Candidature::class);
    expect($log->properties->get('pdf_path'))->toBe('fake-uuid/recipisse.pdf');
    expect($log->properties->get('pdf_hash'))->toBe(str_repeat('a', 64));
});

it('returns 422 when campagne closes between PUT and submit', function (): void {
    [$user, $token] = authedFullCandidat($this->campagne);
    $this->campagne->update(['status' => 'closed', 'closes_at' => now()->subDay()]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    $response->assertStatus(422);
});

it('rejects submit when token lacks application:submit ability', function (): void {
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691111224',
        'date_naissance' => '1995-06-15',
    ]);
    // Token sans application:submit
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create', 'application:read',
    ])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', fullProfilePayload());

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true]);

    expect($response->status())->toBeIn([401, 403]);
});
