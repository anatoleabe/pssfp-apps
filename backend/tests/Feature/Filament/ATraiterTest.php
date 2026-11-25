<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\CandidatureResource;
use App\Filament\Widgets\ATraiterWidget;
use App\Filament\Widgets\RaccourcisWidget;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\EtatPaie;
use App\Models\User;
use App\Services\Lmd\EtatsPaie;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

uses()->group('filament', 'socle');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(
        User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']),
        fn (User $u) => $u->assignRole($role),
    );
    $campagne = CampagneCandidature::factory()->create();
    $dossier = fn (array $attributs) => Candidature::factory()->forCampagne($campagne)->submitted()->create($attributs);
    $dossier(['statut' => Candidature::STATUT_CANDIDAT]);
    $dossier(['statut' => Candidature::STATUT_CANDIDAT]);
    $dossier(['statut' => Candidature::STATUT_CANDIDAT, 'withdrawn_at' => now()]);
    $dossier(['statut' => Candidature::STATUT_ACCEPTE]);
});

it('compte les dossiers en examen pour qui peut décider, hors dossiers retirés', function (): void {
    $this->actingAs(($this->compte)('admission_committee'));

    expect(CandidatureResource::getNavigationBadge())->toBe('2')
        ->and(CandidatureResource::getNavigationBadgeTooltip())->toBe('Dossiers en examen');
});

it('ne montre pas la pastille des dossiers à qui ne décide pas', function (): void {
    $this->actingAs(($this->compte)('receptionniste'));

    expect(CandidatureResource::getNavigationBadge())->toBeNull();
});

it('rassemble sur le tableau de bord les pastilles des écrans ouverts à l\'utilisateur', function (): void {
    $this->actingAs(($this->compte)('admission_committee'));

    $elements = ATraiterWidget::elements();

    expect(ATraiterWidget::canView())->toBeTrue()
        ->and(collect($elements)->firstWhere('libelle', 'Dossiers en examen'))
        ->toMatchArray(['nombre' => '2', 'groupe' => 'Admissions', 'url' => CandidatureResource::getUrl('index')]);
});

it('dit « Rien à traiter » quand rien n\'attend l\'utilisateur (lot 34 : le bloc reste visible)', function (): void {
    $this->actingAs(($this->compte)('editor'));

    expect(ATraiterWidget::elements())->toBe([])
        ->and(ATraiterWidget::canView())->toBeTrue();
    $this->get(Dashboard::getUrl())->assertOk()->assertSee('Rien à traiter');
});

describe('raccourcis de l\'accueil (lot 34)', function (): void {
    it('donne à chaque métier ses écrans, dans l\'ordre de son travail, et seulement ce qu\'il peut ouvrir', function (string $role, array $attendus, array $absents): void {
        $this->actingAs(($this->compte)($role));
        $libelles = array_column(RaccourcisWidget::raccourcis(), 'libelle');

        expect(array_slice($libelles, 0, count($attendus)))->toBe($attendus)
            ->and(count($libelles))->toBeLessThanOrEqual(RaccourcisWidget::MAX);
        foreach ($absents as $absent) {
            expect($libelles)->not->toContain($absent);
        }
    })->with([
        'service financier' => ['service_financier', ['Versements', 'Virements à valider', 'Reçus', 'Synthèse financière'], ['Candidatures', 'Utilisateurs']],
        'agent de scolarité' => ['scolarite_agent', ['Auditeurs', 'Versements à confirmer', 'Listes et états'], ['Virements à valider', 'Utilisateurs']],
        'enseignant' => ['enseignant', ['Mes cours', 'Mes émargements', 'Saisie des notes', 'Mes heures'], ['Auditeurs', 'Versements']],
        'réception' => ['receptionniste', ['Candidatures'], ['Auditeurs', 'Versements']],
    ]);

    it('affiche les raccourcis sur l\'accueil, avec la recherche au clavier', function (): void {
        $this->actingAs(($this->compte)('service_financier'));

        $this->get(Dashboard::getUrl())->assertOk()
            ->assertSeeInOrder(['À traiter', 'Raccourcis', 'Versements', 'Virements à valider'])
            ->assertSee('Ctrl+K');
    });
});

it('donne à chacun les états de paie qu\'il peut faire avancer', function (string $role, bool $visa, array $attendus): void {
    AppSettings::set(EtatsPaie::CLE_VISA, $visa);

    $statuts = app(EtatsPaie::class)->statutsATraiter(($this->compte)($role));

    expect($statuts)->toEqualCanonicalizing($attendus);
})->with([
    'financier' => ['service_financier', false, [EtatPaie::TRANSMIS, EtatPaie::PRIS_EN_CHARGE]],
    'scolarité, sans visa' => ['scolarite_responsable', false, [EtatPaie::BROUILLON, EtatPaie::VERIFIE, EtatPaie::VISE]],
    'scolarité, avec visa' => ['scolarite_responsable', true, [EtatPaie::BROUILLON, EtatPaie::VISE]],
    'super administrateur, avec visa' => ['super_admin', true, [EtatPaie::BROUILLON, EtatPaie::VERIFIE, EtatPaie::VISE, EtatPaie::TRANSMIS, EtatPaie::PRIS_EN_CHARGE]],
    'agent de scolarité' => ['scolarite_agent', false, []],
]);

it('affiche les statuts des candidatures en clair', function (): void {
    expect(Candidature::STATUTS)->toBe([
        'postulant' => 'Postulant',
        'candidat' => 'En examen',
        'accepte' => 'Accepté',
        'refuse' => 'Refusé',
    ]);
});
