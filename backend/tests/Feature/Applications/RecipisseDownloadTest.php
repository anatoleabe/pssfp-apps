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

uses()->group('applications', 'recipisse');

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
    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/abc');
    });
});

it('redirects 302 to a signed URL when recipisse exists', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('candidat', ['application:read'])->plainTextToken;

    Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => $user->phone_e164,
        'recipisse_pdf_path' => 'some/path.pdf',
        'recipisse_hash_sha256' => str_repeat('b', 64),
        'statut' => 'candidat',
        'submitted_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/applications/me/recipisse');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toBe('https://signed.example/abc');
});

it('returns 404 when no recipisse yet (not submitted)', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('candidat', ['application:read'])->plainTextToken;

    Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => $user->phone_e164,
        'recipisse_pdf_path' => null,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/applications/me/recipisse');

    $response->assertStatus(404);
});

it('returns 401 when unauthenticated', function (): void {
    $this->getJson('/v1/applications/me/recipisse')->assertStatus(401);
});
