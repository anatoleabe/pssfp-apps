<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use Illuminate\Support\Facades\Storage;

uses()->group('applications', 'communique');

beforeEach(function (): void {
    Storage::fake(CampagneCandidature::COMMUNIQUE_DISK);
});

it('exposes a null communique url when no PDF has been uploaded', function (): void {
    CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
    ]);

    $this->getJson('/v1/applications/campaigns/current')
        ->assertOk()
        ->assertJsonPath('data.communique_url', null)
        ->assertJsonPath('data.communique_reference', null);
});

it('exposes the public url, reference and signature date once uploaded', function (): void {
    Storage::disk(CampagneCandidature::COMMUNIQUE_DISK)
        ->put('communiques/appel-candidature-p14-2026.pdf', '%PDF-1.4 fake');

    CampagneCandidature::factory()->create([
        'slug' => 'p14-2026',
        'status' => 'open',
        'opens_at' => now()->subMonth(),
        'closes_at' => now()->addMonth(),
        'communique_pdf_path' => 'communiques/appel-candidature-p14-2026.pdf',
        'communique_reference' => 'N° 90001121/C/MINFI/SG/PSSFP/PCP/PCS/UPAAS/AT',
        'communique_signed_at' => '2026-07-15',
    ]);

    $response = $this->getJson('/v1/applications/campaigns/current')->assertOk();

    expect($response->json('data.communique_url'))->toContain('appel-candidature-p14-2026.pdf');
    $response->assertJsonPath('data.communique_reference', 'N° 90001121/C/MINFI/SG/PSSFP/PCP/PCS/UPAAS/AT');
    $response->assertJsonPath('data.communique_signed_at', '2026-07-15');
});

it('returns null from the model helper when the path is empty', function (): void {
    $campagne = CampagneCandidature::factory()->make(['communique_pdf_path' => null]);

    expect($campagne->communiqueUrl())->toBeNull();
});
