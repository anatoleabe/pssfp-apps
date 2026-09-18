<?php

declare(strict_types=1);

use App\Jobs\SendThrottledMail;
use App\Mail\CandidatureSubmittedMail;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

uses()->group('applications', 'emails');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class, RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class, RolePermissionSeeder::class,
    ]);

    $this->campagne = CampagneCandidature::factory()->create([
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);
});

it('déclare un limiteur « emails » sous les plafonds de l\'hébergement', function (): void {
    // L'hébergement plafonne à 150/heure et 1500/jour : dépasser coûte un
    // « 550 sending too fast » et un message définitivement perdu.
    $limites = RateLimiter::limiter('emails')(request());

    expect($limites)->toBeArray()->toHaveCount(2);

    $parDecay = [];
    foreach ($limites as $limite) {
        expect($limite)->toBeInstanceOf(Limit::class);
        $parDecay[$limite->decaySeconds] = $limite->maxAttempts;
    }

    expect($parDecay[3600])->toBeLessThan(150)
        ->and($parDecay[86400])->toBeLessThan(1500);
});

it('porte le limiteur sur le job d\'envoi', function (): void {
    $job = new SendThrottledMail(['x@example.test'], new CandidatureSubmittedMail(
        candidature: Candidature::factory()->forCampagne($this->campagne)->create(),
    ));

    $middlewares = $job->middleware();

    expect($middlewares)->toHaveCount(1)
        ->and($middlewares[0])->toBeInstanceOf(RateLimited::class);
});

it('bascule la ligne du journal en échec quand l\'envoi ne part jamais', function (): void {
    $candidature = Candidature::factory()->forCampagne($this->campagne)->create();
    $auteur = User::factory()->create();

    $ligne = CandidatureRelance::create([
        'candidature_id' => $candidature->id,
        'cause' => CandidatureRelance::CAUSE_MANUELLE,
        'canal' => CandidatureRelance::CANAL_EMAIL,
        'statut' => CandidatureRelance::STATUT_ENVOYE,
        'envoye_par' => $auteur->id,
        'sent_at' => now(),
    ]);

    $job = new SendThrottledMail(
        ['x@example.test'],
        new CandidatureSubmittedMail(candidature: $candidature),
        [],
        $ligne->id,
    );

    $job->failed(new RuntimeException('550 Stop! You are sending too fast'));

    expect($ligne->refresh()->statut)->toBe(CandidatureRelance::STATUT_ECHEC)
        ->and($ligne->erreur)->toContain('too fast');
});

it('n\'expose aucun secret dans l\'erreur consignée au journal', function (): void {
    $candidature = Candidature::factory()->forCampagne($this->campagne)->create();

    $ligne = CandidatureRelance::create([
        'candidature_id' => $candidature->id,
        'cause' => CandidatureRelance::CAUSE_MANUELLE,
        'canal' => CandidatureRelance::CANAL_EMAIL,
        'statut' => CandidatureRelance::STATUT_ENVOYE,
        'sent_at' => now(),
    ]);

    (new SendThrottledMail(['x@example.test'], new CandidatureSubmittedMail(candidature: $candidature), [], $ligne->id))
        ->failed(new RuntimeException('echec sur https://smtp/x?password=SUPERSECRET'));

    expect($ligne->refresh()->erreur)->not->toContain('SUPERSECRET');
});

it('envoie réellement le message quand la bride laisse passer', function (): void {
    Mail::fake();
    $candidature = Candidature::factory()->forCampagne($this->campagne)->create();

    (new SendThrottledMail(['candidat@example.test'], new CandidatureSubmittedMail(candidature: $candidature)))
        ->handle();

    Mail::assertSent(CandidatureSubmittedMail::class);
});
