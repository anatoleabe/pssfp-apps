<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Maquette;
use App\Models\User;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\InscriptionsPedagogiques;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;

uses()->group('lmd', 'applications');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M2']);
    $this->auditeur = Auditeur::factory()->campagneCommune()->create();
    $dossier = $this->auditeur->candidature;
    $dossier->forceFill(['specialite' => 'Gouvernance Territoriale et Finances Publiques locales', 'user_id' => User::factory()->create()->id])->save();
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->auditeur->id, 'bareme_id' => $bareme->id]);
    $this->jeton = fn (User $u): string => $u->createToken('candidat', ['profile:read', 'application:read'])->plainTextToken;
    $this->user = $dossier->user;
});

it('montre le parcours sans maquette tant qu\'elle n\'est pas validée', function (): void {
    $this->withToken(($this->jeton)($this->user))->getJson('/v1/applications/me/parcours')->assertOk()
        ->assertJsonPath('data.parcours.code', 'GTFP')
        ->assertJsonPath('data.maquette_validee', false)
        ->assertJsonPath('data.inscrit', false)
        ->assertJsonPath('data.semestres', []);
});

it('donne à l\'auditeur la maquette validée de son parcours, sans identifiant interne', function (): void {
    Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'GTFP'))->firstOrFail()
        ->forceFill(['statut' => Maquette::VALIDEE, 'validee_at' => now()])->save();
    app(InscriptionsPedagogiques::class)->inscrire($this->inscription);

    $r = $this->withToken(($this->jeton)($this->user))->getJson('/v1/applications/me/parcours')->assertOk()
        ->assertJsonPath('data.maquette_validee', true)
        ->assertJsonPath('data.inscrit', true)
        ->assertJsonPath('data.credits_total', 60)
        ->assertJsonPath('data.semestres.0.numero', 9)
        ->assertJsonPath('data.semestres.0.ues.0.code', 'MPGTF91')
        ->assertJsonPath('data.semestres.1.ues.1.partie', 'soutenance')
        ->assertJsonPath('data.semestres.1.ues.1.ecues.0.code', 'MPGTF1016');

    expect($r->getContent())->not->toContain('"id"')
        ->and($r->headers->get('Cache-Control'))->toContain('no-store');
});

it('répond 404 à un compte sans scolarité, et ne lit jamais le parcours d\'un autre', function (): void {
    $autre = User::factory()->create();

    $this->withToken(($this->jeton)($autre))->getJson('/v1/applications/me/parcours')->assertNotFound()
        ->assertJsonPath('code', 'auditeur_missing');
});

it('exige l\'authentification', function (): void {
    $this->getJson('/v1/applications/me/parcours')->assertUnauthorized();
});
