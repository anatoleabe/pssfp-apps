<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('applications', 'partial-update');

/*
 * Couverture détaillée du PUT /v1/applications/me en mode partial update
 * (cf. spec module 5 §M6 + PR C arbitrage B). Tests dédiés à la fiabilité
 * de l'auto-save côté frontend (PR H — DossierEditionForm).
 */

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

function authedCandidatPartial(): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
        'phone_country' => 'CM',
        'date_naissance' => '1995-06-15',
    ]);
    $token = $user->createToken('candidat', [
        'profile:read', 'profile:write', 'application:create',
        'application:read', 'application:submit',
    ])->plainTextToken;

    return [$user, $token];
}

it('accepts a single field PUT and creates the draft on first call', function (): void {
    [$user, $token] = authedCandidatPartial();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', ['lieu_naissance' => 'Yaoundé']);

    $response->assertOk();
    $response->assertJsonPath('data.lieu_naissance', 'Yaoundé');

    $cand = Candidature::query()->where('user_id', $user->id)->first();
    expect($cand)->not->toBeNull();
    expect($cand->lieu_naissance)->toBe('Yaoundé');
    expect($cand->statut)->toBe('postulant');
});

it('accumulates many sequential single-field PUTs without losing previous values (auto-save fidelity)', function (): void {
    [$user, $token] = authedCandidatPartial();
    $headers = ['Authorization' => "Bearer {$token}"];

    // 6 PUTs, un seul champ chacun, simule l'auto-save sur 6 frappes successives.
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'Mme'])->assertOk();
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['lieu_naissance' => 'Yaoundé'])->assertOk();
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['adresse' => 'Mvog-Ada'])->assertOk();
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['ville_residence' => 'Yaoundé'])->assertOk();
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['institut' => 'UY2'])->assertOk();
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['annee_diplome' => 2024])->assertOk();

    $cand = Candidature::query()->where('user_id', $user->id)->first()->fresh();
    expect($cand->civilite)->toBe('Mme');
    expect($cand->lieu_naissance)->toBe('Yaoundé');
    expect($cand->adresse)->toBe('Mvog-Ada');
    expect($cand->ville_residence)->toBe('Yaoundé');
    expect($cand->institut)->toBe('UY2');
    expect($cand->annee_diplome)->toBe(2024);
});

it('does not reset previously set fields when re-PUTing the same field with a new value', function (): void {
    [$user, $token] = authedCandidatPartial();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->putJson('/v1/applications/me', [
        'civilite' => 'M.', 'genre' => 'M', 'lieu_naissance' => 'Yaoundé',
    ])->assertOk();

    // Re-PUT seulement civilité (changement).
    $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'Mme'])->assertOk();

    $cand = Candidature::query()->where('user_id', $user->id)->first()->fresh();
    expect($cand->civilite)->toBe('Mme');
    expect($cand->genre)->toBe('M');
    expect($cand->lieu_naissance)->toBe('Yaoundé');
});

it('clears an optional nullable field via explicit null in PUT', function (): void {
    [$user, $token] = authedCandidatPartial();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->putJson('/v1/applications/me', [
        'epouse' => 'Dupond', 'second_choix' => 'Audit',
    ])->assertOk();

    $this->withHeaders($headers)->putJson('/v1/applications/me', [
        'epouse' => null, 'second_choix' => null,
    ])->assertOk();

    $cand = Candidature::query()->where('user_id', $user->id)->first()->fresh();
    expect($cand->epouse)->toBeNull();
    expect($cand->second_choix)->toBeNull();
});

it('forbids overwriting system fields even if injected in the PUT body', function (): void {
    [$user, $token] = authedCandidatPartial();

    // Premier PUT pour créer la candidature.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', ['civilite' => 'M.'])->assertOk();

    $cand = Candidature::query()->where('user_id', $user->id)->first();
    $originalUuid = $cand->uuid;
    $originalNumero = $cand->numero_dossier;

    // Tentative d'injection : on essaie de changer uuid/numero/statut/frais_paye.
    $this->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', [
            'civilite' => 'Mme',
            'uuid' => '00000000-0000-0000-0000-000000000000',
            'numero_dossier' => 'P99999-666',
            'statut' => 'accepte',
            'frais_paye' => true,
            'submitted_at' => '2020-01-01 00:00:00',
        ])->assertOk();

    $cand->refresh();
    expect($cand->civilite)->toBe('Mme');
    expect($cand->uuid)->toBe($originalUuid);
    expect($cand->numero_dossier)->toBe($originalNumero);
    expect($cand->statut)->toBe('postulant');
    expect((bool) $cand->frais_paye)->toBeFalse();
    expect($cand->submitted_at)->toBeNull();
});

it('refuses partial updates with 409 once the candidature is submitted', function (): void {
    [$user, $token] = authedCandidatPartial();
    $headers = ['Authorization' => "Bearer {$token}"];

    $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'M.'])->assertOk();
    Candidature::query()->where('user_id', $user->id)->update([
        'statut' => 'candidat',
        'submitted_at' => now(),
    ]);

    $response = $this->withHeaders($headers)->putJson('/v1/applications/me', ['civilite' => 'Mme']);

    $response->assertStatus(409);
});

it('handles concurrent partial PUTs from the same user (last writer wins, no exception)', function (): void {
    [$user, $token] = authedCandidatPartial();
    $headers = ['Authorization' => "Bearer {$token}"];

    // Trois PUTs successifs très proches simulent du concurrent sur l'auto-save
    // (dans le même thread Pest, mais l'idée est de vérifier que le service est
    // robuste à des updates rapides — pas d'exception, dernière valeur appliquée).
    $r1 = $this->withHeaders($headers)->putJson('/v1/applications/me', ['adresse' => 'A1']);
    $r2 = $this->withHeaders($headers)->putJson('/v1/applications/me', ['adresse' => 'A2']);
    $r3 = $this->withHeaders($headers)->putJson('/v1/applications/me', ['adresse' => 'A3']);

    $r1->assertOk();
    $r2->assertOk();
    $r3->assertOk();

    $cand = Candidature::query()->where('user_id', $user->id)->first()->fresh();
    expect($cand->adresse)->toBe('A3');
});
