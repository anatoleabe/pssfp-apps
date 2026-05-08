<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Route;

uses()->group('auth', 'candidat', 'security');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    // Routes-leurres typiques de futurs endpoints admin / biblio restreint.
    // L'objectif est de prouver que `ability:admin.*` et `library:read:restricted`
    // refusent un token candidat — pas de tester ces endpoints réels (PR D / module 3).
    Route::middleware(['auth:sanctum', 'ability:admin.candidatures.read'])
        ->get('/v1/admin/decoy/candidatures', fn () => response()->json(['ok' => true]))
        ->name('test.admin.candidatures');

    Route::middleware(['auth:sanctum', 'ability:library:read:restricted'])
        ->get('/v1/library/decoy/{uuid}/download', fn () => response()->json(['ok' => true]))
        ->name('test.library.download');
});

it('candidat token cannot access admin endpoints (ability mismatch)', function (): void {
    $candidat = User::factory()->candidat()->create();
    $token = $candidat->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create', 'application:read',
    ])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/admin/decoy/candidatures');

    expect($response->status())->toBeIn([401, 403]);
});

it('candidat token cannot use library:read:restricted ability', function (): void {
    $candidat = User::factory()->candidat()->create();
    $token = $candidat->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create', 'application:read',
    ])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/v1/library/decoy/some-uuid/download');

    expect($response->status())->toBeIn([401, 403]);
});

it('candidat abilities are exactly the four scoped ones, no extras', function (): void {
    $candidat = User::factory()->candidat('472816')->create(['phone_e164' => '+237691111222']);

    $response = $this->postJson('/v1/auth/candidat/login', [
        'phone_e164' => '+237691111222',
        'pin' => '472816',
    ]);

    $abilities = $response->json('abilities');
    expect($abilities)->toEqualCanonicalizing([
        'profile:read',
        'profile:write',
        'application:create',
        'application:read',
    ]);
    expect($abilities)->not->toContain('admin.*');
    expect($abilities)->not->toContain('library:read:restricted');
});
