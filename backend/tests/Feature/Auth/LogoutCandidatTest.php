<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

uses()->group('auth', 'candidat');

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

it('revokes the current Sanctum token on logout', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('candidat', ['profile:read'])->plainTextToken;

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/auth/candidat/logout');

    $response->assertStatus(204);
    expect($user->tokens()->count())->toBe(0);
});

it('returns 401 when called without a token', function (): void {
    $response = $this->postJson('/v1/auth/candidat/logout');
    $response->assertStatus(401);
});
