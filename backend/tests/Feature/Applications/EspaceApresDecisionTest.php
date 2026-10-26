<?php

declare(strict_types=1);

use App\Mail\CandidatureAcceptedMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Services\RecipisseService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('applications');

/*
 * Une fois la sélection faite, la campagne est close : le candidat doit
 * pourtant retrouver sa décision, ses consignes et les messages reçus.
 */

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);
    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'closed',
        'opens_at' => now()->subMonths(3),
        'closes_at' => now()->subMonth(),
        'consignes_retenus' => 'Inscription administrative du 3 au 14 novembre.',
    ]);
});

/** @return array{0: Candidature, 1: string} */
function candidatDecide(CampagneCandidature $campagne, string $statut, array $attributs = []): array
{
    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691'.fake()->numerify('######'),
    ]);

    $candidature = Candidature::factory()->forCampagne($campagne)->create(array_merge([
        'user_id' => $user->id,
        'phone_e164' => $user->phone_e164,
        'statut' => $statut,
        'submitted_at' => now()->subMonths(2),
        'decided_at' => in_array($statut, [Candidature::STATUT_ACCEPTE, Candidature::STATUT_REFUSE], true) ? now()->subDay() : null,
    ], $attributs));

    $token = $user->createToken('candidat', ['profile:read', 'application:create', 'application:read'])->plainTextToken;

    return [$candidature, $token];
}

it('montre au retenu son dossier, sa décision et les consignes de la campagne close', function (): void {
    [$candidature, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE, [
        'decision_commentaire' => 'Félicitations pour votre parcours.',
    ]);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $candidature->numero_dossier)
        ->assertJsonPath('data.decision.resultat', 'retenu')
        ->assertJsonPath('data.decision.commentaire', 'Félicitations pour votre parcours.')
        ->assertJsonPath('data.decision.consignes', 'Inscription administrative du 3 au 14 novembre.')
        ->assertJsonMissingPath('data.decision.motif')
        ->assertJsonPath('data.campagne.is_currently_open', false);
});

it('montre au non-retenu le motif, sans les consignes destinées aux retenus', function (): void {
    [, $token] = candidatDecide($this->campagne, Candidature::STATUT_REFUSE, [
        'decision_motif' => 'Dossier incomplet au regard des conditions d\'admission.',
    ]);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.decision.resultat', 'non_retenu')
        ->assertJsonPath('data.decision.motif', 'Dossier incomplet au regard des conditions d\'admission.')
        ->assertJsonMissingPath('data.decision.consignes');
});

it('ne renvoie aucune décision tant que le dossier est en examen', function (): void {
    [, $token] = candidatDecide($this->campagne, Candidature::STATUT_CANDIDAT);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.statut', 'candidat')
        ->assertJsonPath('data.decision', null);
});

it('garde campaign_closed pour un compte qui n\'a jamais postulé', function (): void {
    $user = User::factory()->candidat()->create();
    $token = $user->createToken('candidat', ['application:read'])->plainTextToken;

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertNotFound()
        ->assertJsonPath('code', 'campaign_closed');
});

it('sert le dossier de la campagne ouverte plutôt qu\'un ancien dossier', function (): void {
    [$ancien, $token] = candidatDecide($this->campagne, Candidature::STATUT_REFUSE);
    $ouverte = CampagneCandidature::factory()->create([
        'slug' => 'p15-2027',
        'status' => 'open',
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addMonth(),
    ]);

    // Nouvelle campagne sans dossier : le candidat doit l'initialiser.
    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertNotFound()
        ->assertJsonPath('code', 'candidature_missing');

    $nouveau = Candidature::factory()->forCampagne($ouverte)->create([
        'user_id' => $ancien->user_id,
        'phone_e164' => $ancien->phone_e164,
    ]);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $nouveau->numero_dossier);
});

it('liste les messages envoyés depuis l\'admin, une fois par envoi SMS + e-mail', function (): void {
    [$candidature, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);
    $texte = 'Bonjour, présentez-vous le 3 novembre pour votre inscription.';

    $trace = fn (array $champs) => CandidatureRelance::create(array_merge([
        'candidature_id' => $candidature->id,
        'cause' => CandidatureRelance::CAUSE_MANUELLE,
        'statut' => CandidatureRelance::STATUT_ENVOYE,
        'sent_at' => now()->subHour(),
    ], $champs));

    $trace(['canal' => 'sms', 'message' => $texte]);
    $trace(['canal' => 'email', 'message' => $texte, 'sujet' => 'Convocation']);
    // Ni les échecs ni les relances automatiques ne sont des messages reçus.
    $trace(['canal' => 'email', 'message' => 'Jamais parti', 'statut' => CandidatureRelance::STATUT_ECHEC]);
    $trace(['canal' => 'sms', 'cause' => 'ready', 'message' => 'Relance automatique']);

    $response = $this->withToken($token)->getJson('/v1/applications/me')->assertOk();

    expect($response->json('data.messages'))->toHaveCount(1)
        ->and($response->json('data.messages.0.sujet'))->toBe('Convocation')
        ->and($response->json('data.messages.0.message'))->toBe($texte);
});

it('ne montre jamais les messages d\'un autre candidat', function (): void {
    [$autre] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);
    CandidatureRelance::create([
        'candidature_id' => $autre->id,
        'cause' => CandidatureRelance::CAUSE_MANUELLE,
        'canal' => 'email',
        'statut' => CandidatureRelance::STATUT_ENVOYE,
        'message' => 'Message personnel',
        'sent_at' => now(),
    ]);
    [, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonCount(0, 'data.messages');
});

it('laisse télécharger le récépissé après la clôture', function (): void {
    $this->mock(RecipisseService::class, function ($mock): void {
        $mock->shouldReceive('signedUrl')->andReturn('https://signed.example/recu');
    });
    [, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE, [
        'recipisse_pdf_path' => 'recipisses/test.pdf',
    ]);

    $this->withToken($token)->get('/v1/applications/me/recipisse')
        ->assertRedirect('https://signed.example/recu');
});

it('refuse toujours la modification d\'un dossier hors campagne ouverte', function (): void {
    [, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);

    $this->withToken($token)->putJson('/v1/applications/me', ['civilite' => 'M.'])
        ->assertStatus(409);
});

it('présente la page QR avec le nom du programme et un statut lisible', function (): void {
    [$candidature] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);

    $this->get("/v1/c/{$candidature->uuid}/qr")
        ->assertOk()
        ->assertSee('Programme Supérieur de Spécialisation en Finances Publiques')
        ->assertSee('images/pssfp-logo.png', false)
        ->assertSee('Retenu(e)')
        ->assertSee('Spécialité retenue')
        ->assertDontSee('Programme PSSFP')
        ->assertDontSee('ACCEPTE');
});

it('renvoie l\'e-mail d\'admission vers l\'espace candidat, pas vers la page QR', function (): void {
    [$candidature] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);

    $html = (new CandidatureAcceptedMail($candidature))->render();

    expect($html)->toContain(config('pssfp.candidature_app_url').'/dossier')
        ->and($html)->not->toContain('/qr');
});

it('laisse un retenu consulter son admission même après l\'ouverture d\'une nouvelle campagne', function (): void {
    [$retenu, $token] = candidatDecide($this->campagne, Candidature::STATUT_ACCEPTE);
    CampagneCandidature::factory()->create([
        'slug' => 'p15-2027',
        'status' => 'open',
        'opens_at' => now()->subDay(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->withToken($token)->getJson('/v1/applications/me')
        ->assertOk()
        ->assertJsonPath('data.numero_dossier', $retenu->numero_dossier)
        ->assertJsonPath('data.decision.resultat', 'retenu');
});
