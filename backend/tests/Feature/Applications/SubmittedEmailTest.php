<?php

declare(strict_types=1);

use App\Mail\CandidatureSubmittedMail;
use App\Models\CampagneCandidature;
use App\Models\User;
use App\Services\RecipisseService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses()->group('applications', 'submit', 'mail');

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

    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('generate')->andReturn([
            'path' => 'fake-uuid/recipisse.pdf',
            'hash' => str_repeat('a', 64),
        ]);
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/fake-uuid');
        $mock->shouldReceive('pathFor')->andReturn('fake-uuid/recipisse.pdf');
    });
});

/**
 * Crée un candidat authentifié avec un dossier complet prêt à soumettre.
 * Nom volontairement unique pour ne pas entrer en collision avec le helper
 * `authedFullCandidat` de SubmitTest.php (fonctions Pest = globales).
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: User, 1: string}
 */
function submittedEmailAuthedCandidat(array $extra = []): array
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

    $payload = array_merge([
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
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Économie',
        'annee_diplome' => 2024,
        'statut_actuel' => 'Etudiant',
        'engagement_nom' => 'Jean Dupont',
    ], $extra);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->putJson('/v1/applications/me', $payload)
        ->assertOk();

    return [$user, $token];
}

it('queues the submission confirmation email when the candidate has an email', function (): void {
    Mail::fake();
    [, $token] = submittedEmailAuthedCandidat(['email' => 'candidat@example.com']);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    Mail::assertQueued(
        CandidatureSubmittedMail::class,
        fn (CandidatureSubmittedMail $mail): bool => $mail->hasTo('candidat@example.com'),
    );
});

it('does not send any email when the candidate has no email', function (): void {
    Mail::fake();
    [, $token] = submittedEmailAuthedCandidat();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    Mail::assertNothingQueued();
});

it('does not re-queue the email on an idempotent replay', function (): void {
    Mail::fake();
    [, $token] = submittedEmailAuthedCandidat(['email' => 'candidat@example.com']);
    $key = 'idem-mail-key-1';

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Idempotency-Key', $key)
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Idempotency-Key', $key)
        ->postJson('/v1/applications/me/submit', ['confirmation_engagement' => true])
        ->assertOk();

    Mail::assertQueued(CandidatureSubmittedMail::class, 1);
});
