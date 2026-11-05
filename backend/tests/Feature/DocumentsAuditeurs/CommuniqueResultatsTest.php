<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Models\CampagneCandidature;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses()->group('filament', 'documents-auditeurs');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([RolePermissionSeeder::class]);
    Storage::fake(CampagneCandidature::COMMUNIQUE_DISK);
    $this->campagne = CampagneCandidature::factory()->create([
        'slug' => 'p14-2026', 'communique_pdf_path' => 'communiques/appel-candidature-p14-2026-fr.pdf',
    ]);
});

it('expose le communiqué des résultats à part de celui de l\'appel', function (): void {
    $this->campagne->update(['communique_resultats_path' => 'communiques/resultats-p14-2026.pdf']);

    $donnees = $this->campagne->fresh()->communiqueResultatsUrl();

    expect($donnees)->toContain('communiques/resultats-p14-2026.pdf')
        ->and($this->campagne->fresh()->communiqueUrl('fr'))->toContain('appel-candidature-p14-2026-fr.pdf');
});

it('ne propose rien tant qu\'aucun communiqué des résultats n\'est déposé', function (): void {
    expect($this->campagne->communiqueResultatsUrl())->toBeNull();
});

it('publie le communiqué des résultats même une fois la campagne close', function (): void {
    $this->campagne->forceFill([
        'status' => 'open', 'opens_at' => now()->subMonths(4), 'closes_at' => now()->subMonth(),
        'communique_resultats_path' => 'communiques/resultats-p14-2026.pdf',
    ])->save();

    $this->getJson('/v1/applications/campaigns/current')->assertOk()->assertJsonPath('data', null);
    $this->getJson('/v1/applications/campaigns/resultats')
        ->assertOk()
        ->assertJsonPath('data.communique_resultats_url', fn (string $url): bool => str_contains($url, 'resultats-p14-2026.pdf'))
        ->assertJsonPath('data.promotion_numero', $this->campagne->promotion_numero)
        ->assertJsonMissingPath('data.id');
});

it('ne publie rien sans communiqué déposé, ni pour une campagne en brouillon', function (): void {
    $this->getJson('/v1/applications/campaigns/resultats')->assertOk()->assertJsonPath('data', null);

    $this->campagne->forceFill(['status' => 'draft', 'communique_resultats_path' => 'communiques/resultats-p14-2026.pdf'])->save();
    $this->getJson('/v1/applications/campaigns/resultats')->assertOk()->assertJsonPath('data', null);
});

it('publie les résultats de la promotion la plus récente', function (): void {
    $this->campagne->forceFill(['status' => 'closed', 'opens_at' => now()->subYears(2), 'closes_at' => now()->subYear(), 'communique_resultats_path' => 'communiques/resultats-ancienne.pdf'])->save();
    CampagneCandidature::factory()->create(['status' => 'closed', 'slug' => 'p15', 'opens_at' => now()->subMonths(3), 'closes_at' => now()->subMonth(), 'communique_resultats_path' => 'communiques/resultats-recente.pdf']);

    $this->getJson('/v1/applications/campaigns/resultats')
        ->assertJsonPath('data.communique_resultats_url', fn (string $url): bool => str_contains($url, 'resultats-recente.pdf'));
});

it('dépose le communiqué des résultats sans toucher au fichier de l\'appel', function (): void {
    Storage::disk(CampagneCandidature::COMMUNIQUE_DISK)->put('communiques/appel-candidature-p14-2026-fr.pdf', 'appel');
    $admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('super_admin'));
    $this->actingAs($admin);

    $this->livewire(EditCampagneCandidature::class, ['record' => $this->campagne->getRouteKey()])
        ->fillForm(['communique_resultats_path' => UploadedFile::fake()->createWithContent('RÉSULTATS.pdf', "%PDF-1.4\nresultats")])
        ->call('save')
        ->assertHasNoFormErrors();

    $chemin = $this->campagne->fresh()->communique_resultats_path;
    expect($chemin)->toBe('communiques/resultats-p14-2026.pdf')
        ->and(Storage::disk(CampagneCandidature::COMMUNIQUE_DISK)->get($chemin))->toContain('resultats')
        ->and(Storage::disk(CampagneCandidature::COMMUNIQUE_DISK)->get('communiques/appel-candidature-p14-2026-fr.pdf'))->toBe('appel');
});
