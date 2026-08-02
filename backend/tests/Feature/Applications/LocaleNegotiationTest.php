<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;

uses()->group('applications', 'i18n');

/**
 * L'API rendait les champs traduisibles en français quelle que soit la langue
 * demandée : le portail anglais affichait le nom de campagne « Année
 * académique 2026-2027 » en titre de page. L'ADR-0006 prévoyait pourtant que
 * `Accept-Language` détermine la locale servie.
 */
beforeEach(function (): void {
    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->campagne->setTranslation('nom', 'fr', 'Année académique 2026-2027');
    $this->campagne->setTranslation('nom', 'en', '2026-2027 academic year');
    $this->campagne->save();
});

it('serves the french name when no preference is expressed', function (): void {
    // En-tête vide : Symfony injecte sinon `en-us,en;q=0.5` dans les requêtes
    // de test, ce qui masquerait le comportement réel d'un client neutre.
    $this->withHeader('Accept-Language', '')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.nom', 'Année académique 2026-2027');
});

it('serves the english name when the browser asks for english', function (): void {
    $this->withHeader('Accept-Language', 'en-GB,en;q=0.9')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.nom', '2026-2027 academic year');
});

it('honours the quality factors of the header', function (): void {
    // Le français est explicitement préféré malgré l'anglais en tête de liste.
    $this->withHeader('Accept-Language', 'en;q=0.4, fr;q=0.9')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.nom', 'Année académique 2026-2027');
});

it('falls back to french for an unsupported language', function (): void {
    $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.nom', 'Année académique 2026-2027');
});

it('falls back to french when the english translation is missing', function (): void {
    $this->campagne->forgetTranslation('nom', 'en');
    $this->campagne->save();

    // Une campagne dont le nom anglais n'a pas encore été saisi doit afficher
    // le français plutôt qu'un titre vide.
    $this->withHeader('Accept-Language', 'en')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.nom', 'Année académique 2026-2027');
});

it('accepts an explicit locale query parameter', function (): void {
    $this->getJson('/v1/applications/campaigns/current?locale=en')
        ->assertOk()
        ->assertJsonPath('data.nom', '2026-2027 academic year');
});

it('announces the served language and varies on the header', function (): void {
    $response = $this->withHeader('Accept-Language', 'en')
        ->getJson('/v1/applications/campaigns/current')
        ->assertOk();

    expect($response->headers->get('Content-Language'))->toBe('en')
        ->and($response->headers->get('Vary'))->toContain('Accept-Language');
});
