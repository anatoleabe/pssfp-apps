<?php

declare(strict_types=1);

use App\Filament\Resources\CandidatureResource\Pages\ListCandidatures;
use App\Filament\Widgets\AvancementCampagneWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\User;
use App\Services\CandidatureService;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

uses()->group('applications', 'draft-blockage');

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

/** Brouillon complet au sens de checkSubmittable, photo comprise. */
function draftComplet(int $campagneId, array $overrides = []): Candidature
{
    $candidature = Candidature::factory()->create(array_merge([
        'campagne_id' => $campagneId,
        'statut' => Candidature::STATUT_POSTULANT,
        'photo_path' => 'candidat-photos/test/photo.jpg',
        'civilite' => 'M.',
        'nom' => 'Ndongo',
        'prenom' => 'Paul',
        'date_naissance' => '1990-06-15',
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
        'telephone1' => '691234567',
        'email' => 'paul.ndongo@example.com',
        'specialite' => array_values((array) config('specialites'))[0],
        'type_etude' => 'presentiel',
        'premiere_langue' => 'fr',
        'diplome_obtenu' => 'Master',
        'institut' => 'Université de Yaoundé II',
        'specialite_diplome' => 'Finances publiques',
        'annee_diplome' => 2015,
        'statut_actuel' => 'Etudiant',
        'moyen_connaissance' => 'Site officiel du PSSFP',
        'engagement_nom' => 'Paul Ndongo',
    ], $overrides));

    // Ces brouillons datent d'avant la bascule du formulaire v2 : on reste en
    // v1 pour que le classement ne dépende pas du bloc « diplôme requis ».
    DB::table('candidatures')->where('id', $candidature->id)->update(['form_version' => 1]);

    return $candidature->refresh();
}

it('classe un brouillon complet comme prêt à soumettre', function (): void {
    $draft = draftComplet($this->campagne->id);

    expect(app(CandidatureService::class)->classifyDraft($draft))
        ->toBe(CandidatureService::DRAFT_READY);
});

it('classe un brouillon sans photo comme bloqué par la seule photo', function (): void {
    $draft = draftComplet($this->campagne->id, ['photo_path' => null]);

    expect(app(CandidatureService::class)->classifyDraft($draft))
        ->toBe(CandidatureService::DRAFT_PHOTO_ONLY);
});

it('ne classe pas en photo seule un brouillon auquel il manque aussi autre chose', function (): void {
    $draft = draftComplet($this->campagne->id, [
        'photo_path' => null,
        'annee_diplome' => null,
    ]);

    expect(app(CandidatureService::class)->classifyDraft($draft))
        ->toBe(CandidatureService::DRAFT_OTHER);
});

it('classe en autre un brouillon avec photo mais champ manquant', function (): void {
    $draft = draftComplet($this->campagne->id, ['specialite_diplome' => null]);

    expect(app(CandidatureService::class)->classifyDraft($draft))
        ->toBe(CandidatureService::DRAFT_OTHER);
});

it('regroupe les brouillons d’une campagne par cause de blocage', function (): void {
    $pret = draftComplet($this->campagne->id);
    $photo1 = draftComplet($this->campagne->id, ['photo_path' => null]);
    $photo2 = draftComplet($this->campagne->id, ['photo_path' => null]);
    $autre = draftComplet($this->campagne->id, ['annee_diplome' => null]);

    $result = app(CandidatureService::class)->classifyDraftsForCampagne($this->campagne->id);

    expect($result[CandidatureService::DRAFT_READY])->toBe([$pret->id])
        ->and($result[CandidatureService::DRAFT_PHOTO_ONLY])
        ->toEqualCanonicalizing([$photo1->id, $photo2->id])
        ->and($result[CandidatureService::DRAFT_OTHER])->toBe([$autre->id]);
});

it('exclut les dossiers déjà soumis du classement des brouillons', function (): void {
    $draft = draftComplet($this->campagne->id);
    $soumis = draftComplet($this->campagne->id);
    $soumis->update(['statut' => Candidature::STATUT_CANDIDAT, 'submitted_at' => now()]);

    $result = app(CandidatureService::class)->classifyDraftsForCampagne($this->campagne->id);

    expect($result[CandidatureService::DRAFT_READY])->toBe([$draft->id]);
});

it('exclut les brouillons retirés par le candidat', function (): void {
    draftComplet($this->campagne->id, ['withdrawn_at' => now()]);

    $result = app(CandidatureService::class)->classifyDraftsForCampagne($this->campagne->id);

    expect($result[CandidatureService::DRAFT_READY])->toBe([])
        ->and($result[CandidatureService::DRAFT_PHOTO_ONLY])->toBe([])
        ->and($result[CandidatureService::DRAFT_OTHER])->toBe([]);
});

it('ne mélange pas les campagnes', function (): void {
    $autreCampagne = CampagneCandidature::factory()->create([
        'status' => 'closed',
        'opens_at' => now()->subYear(),
        'closes_at' => now()->subMonths(10),
    ]);
    draftComplet($autreCampagne->id);
    $pret = draftComplet($this->campagne->id);

    $result = app(CandidatureService::class)->classifyDraftsForCampagne($this->campagne->id);

    expect($result[CandidatureService::DRAFT_READY])->toBe([$pret->id]);
});

it('affiche la ventilation des blocages dans le widget d’avancement', function (): void {
    draftComplet($this->campagne->id);
    draftComplet($this->campagne->id, ['photo_path' => null]);
    draftComplet($this->campagne->id, ['photo_path' => null]);
    draftComplet($this->campagne->id, ['annee_diplome' => null]);

    config()->set('pssfp.filament.require_2fa', false);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $this->livewire(AvancementCampagneWidget::class)
        ->assertSee('Prêts à soumettre')
        ->assertSee('Bloqués par la photo')
        ->assertSee('Autres champs manquants');
});

it('filtre la liste admin sur les brouillons bloqués par la photo', function (): void {
    $pret = draftComplet($this->campagne->id);
    $photo = draftComplet($this->campagne->id, ['photo_path' => null]);

    config()->set('pssfp.filament.require_2fa', false);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $this->livewire(ListCandidatures::class)
        ->filterTable('blocage_brouillon', CandidatureService::DRAFT_PHOTO_ONLY)
        ->assertCanSeeTableRecords([$photo])
        ->assertCanNotSeeTableRecords([$pret]);
});

it('filtre la liste admin sur les brouillons prêts à soumettre', function (): void {
    $pret = draftComplet($this->campagne->id);
    $photo = draftComplet($this->campagne->id, ['photo_path' => null]);

    config()->set('pssfp.filament.require_2fa', false);
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    $this->livewire(ListCandidatures::class)
        ->filterTable('blocage_brouillon', CandidatureService::DRAFT_READY)
        ->assertCanSeeTableRecords([$pret])
        ->assertCanNotSeeTableRecords([$photo]);
});

it('respecte la campagne sélectionnée et non la campagne ouverte', function (): void {
    $ancienne = CampagneCandidature::factory()->create([
        'status' => 'closed',
        'opens_at' => now()->subYear(),
        'closes_at' => now()->subMonths(10),
    ]);
    $pretAncienne = draftComplet($ancienne->id);
    $pretCourante = draftComplet($this->campagne->id);

    config()->set('pssfp.filament.require_2fa', false);
    $admin = App\Models\User::factory()->create();
    $admin->assignRole('super_admin');
    $this->actingAs($admin);

    // Un admin qui consulte une campagne passée doit voir SES brouillons.
    $this->livewire(App\Filament\Resources\CandidatureResource\Pages\ListCandidatures::class)
        ->filterTable('campagne_id', $ancienne->id)
        ->filterTable('blocage_brouillon', App\Services\CandidatureService::DRAFT_READY)
        ->assertCanSeeTableRecords([$pretAncienne])
        ->assertCanNotSeeTableRecords([$pretCourante]);
});
