<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('applications', 'qr');

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
        'prefix_numero' => 'P14026-',
    ]);
});

it('renders an HTML page without auth', function (): void {
    $user = User::factory()->candidat()->create();
    $cand = Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => '+237691234567',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'statut' => 'candidat',
        'submitted_at' => now(),
        'recipisse_hash_sha256' => str_repeat('c', 64),
    ]);

    $response = $this->get("/v1/c/{$cand->uuid}/qr");

    $response->assertOk();
    $response->assertSee($cand->numero_dossier);
    $response->assertSee('Jean');
    $response->assertSee('Dupont');
});

it('masks the phone (anti-PII)', function (): void {
    $user = User::factory()->candidat()->create();
    $cand = Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => '+237691234567',
    ]);

    $response = $this->get("/v1/c/{$cand->uuid}/qr");

    $response->assertOk();
    $response->assertSee('+237***4567');
    $response->assertDontSee('+237691234567');
});

it('returns 404 for unknown uuid', function (): void {
    $this->get('/v1/c/00000000-0000-0000-0000-000000000000/qr')->assertNotFound();
});

it('shows the SHA256 hash for verification when available', function (): void {
    $user = User::factory()->candidat()->create();
    $cand = Candidature::factory()->forCampagne($this->campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => '+237691234567',
        'recipisse_hash_sha256' => str_repeat('d', 64),
        'submitted_at' => now(),
        'statut' => 'candidat',
    ]);

    $response = $this->get("/v1/c/{$cand->uuid}/qr");
    $response->assertSee(str_repeat('d', 64));
});
