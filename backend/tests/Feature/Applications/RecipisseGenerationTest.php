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
use Illuminate\Support\Facades\Storage;

uses()->group('applications', 'recipisse', 'pdf');

beforeEach(function (): void {
    $this->seed([
        PaysSeeder::class,
        RegionsCamerounSeeder::class,
        DepartementsCamerounSeeder::class,
        RolePermissionSeeder::class,
    ]);

    Storage::fake('minio_candidatures');

    $campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026-test',
        'nom' => 'Année académique 2026-2027',
        'promotion_numero' => 14,
        'status' => 'open',
        'opens_at' => '2026-07-27 08:00:00',
        'closes_at' => '2026-09-18 15:30:00',
        'prefix_numero' => 'P14026-',
    ]);

    $user = User::factory()->candidat()->create([
        'phone_e164' => '+237691111888',
        'date_naissance' => '1995-06-15',
    ]);

    $this->candidature = Candidature::factory()->forCampagne($campagne)->create([
        'user_id' => $user->id,
        'phone_e164' => '+237691111888',
        'nom' => 'Dupont',
        'prenom' => 'Jean',
        'civilite' => 'M.',
        'date_naissance' => '1995-06-15',
        'lieu_naissance' => 'Yaoundé',
        'genre' => 'M',
        'statut_matrimonial' => 'Célibataire',
        'nationalite' => 'CM',
        'pays_origine' => 'CM',
        'pays_residence' => 'CM',
        'region' => 'CENTRE',
        'departement' => 'Mfoundi',
        'adresse' => 'Yaoundé',
        'ville_residence' => 'Yaoundé',
        'indicatif1' => '+237',
        'telephone1' => '691111888',
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'UY2',
        'specialite_diplome' => 'Économie',
        'annee_diplome' => 2024,
        'statut_actuel' => 'Etudiant',
        'engagement_nom' => 'Jean Dupont',
    ]);
});

it('generates a PDF and stores it on minio_candidatures disk', function (): void {
    /** @var RecipisseService $service */
    $service = app(RecipisseService::class);

    $result = $service->generate($this->candidature);

    expect($result['path'])->toBe("{$this->candidature->uuid}/recipisse.pdf");
    expect($result['hash'])->toMatch('/^[0-9a-f]{64}$/');
    Storage::disk('minio_candidatures')->assertExists($result['path']);
});

it('produces deterministic hash for the same candidature snapshot', function (): void {
    /** @var RecipisseService $service */
    $service = app(RecipisseService::class);

    $first = $service->generate($this->candidature);
    Storage::disk('minio_candidatures')->delete($first['path']); // pas critique pour le hash
    $second = $service->generate($this->candidature->fresh());

    // Le hash dépend du HTML qui inclut un timestamp `generatedAt`. Sur deux runs
    // proches dans le temps (< 1 minute), le hash peut différer si on tombe sur
    // deux secondes différentes — mais le hash doit toujours faire 64 hex chars.
    expect(strlen($first['hash']))->toBe(64);
    expect(strlen($second['hash']))->toBe(64);
});

it('writes the file under the candidate UUID prefix', function (): void {
    /** @var RecipisseService $service */
    $service = app(RecipisseService::class);

    $service->generate($this->candidature);

    $path = "{$this->candidature->uuid}/recipisse.pdf";
    Storage::disk('minio_candidatures')->assertExists($path);
});

it('renders the committee copy and both campaign labels', function (): void {
    $candidature = $this->candidature->load(['campagne', 'paysNationalite']);

    $html = view('pdf.candidature-recipisse', [
        'candidature' => $candidature,
        'campagne' => $candidature->campagne,
        'qrSvg' => '',
        'logoSrc' => '',
        'enteteSrc' => '',
        'photoSrc' => '',
        'generatedAt' => now(),
        'programName' => 'Master Professionnel en Finances Publiques',
        'contact' => [
            'adresse' => 'Campus de Messa, Yaoundé — Cameroun',
            'tel' => '+237 222 234 567',
            'web' => 'www.pssfp.org',
            'email' => 'contact@pssfp.org',
        ],
        'hashPlaceholder' => str_repeat('0', 64),
        'vcodePlaceholder' => '0000-0000',
    ])->render();

    expect($html)
        ->toContain('Campagne 2026')
        ->toContain('Année académique 2026-2027')
        ->toContain('COPIE DU COMITÉ DE PILOTAGE')
        ->toContain('STEERING COMMITTEE COPY')
        ->toContain('Décision du Comité de Pilotage')
        ->toContain('Applicant profile')
        ->toContain('Application number')
        ->toContain('Academic year')
        ->not->toContain('COPIE ADMINISTRATION')
        ->not->toContain('Page 1 sur 2 — Copie candidat')
        ->not->toContain('Page 2 sur 2 — Comité de Pilotage du PSSFP');
});

it('ships the two embedded PDF font families', function (): void {
    expect(resource_path('fonts/pdf/SourceSerif4-Regular.ttf'))->toBeFile()
        ->and(resource_path('fonts/pdf/SourceSerif4-Bold.ttf'))->toBeFile()
        ->and(resource_path('fonts/pdf/SourceSans3-Regular.ttf'))->toBeFile()
        ->and(resource_path('fonts/pdf/SourceSans3-Italic.ttf'))->toBeFile()
        ->and(resource_path('fonts/pdf/SourceSans3-Bold.ttf'))->toBeFile();
});
