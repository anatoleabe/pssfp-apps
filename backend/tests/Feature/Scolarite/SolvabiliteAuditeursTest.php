<?php

declare(strict_types=1);

use App\Filament\Pages\Scolarite\SolvabiliteAuditeurs;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\Role;
use App\Models\User;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\ListeSolvabilite;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\Solvabilite;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
 * Finances > Solvabilité (17/11/2026) : auditeurs en règle, partiellement et totalement insolvables, exports PDF et
 * Excel. Données fictives.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13]);
    $this->bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'tranche1_echeance' => '2026-11-01', 'tranche2_echeance' => '2027-03-01']);
    $this->auditeur = function (string $nom, CampagneCandidature $campagne, int $verse = 0): Auditeur {
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create([
            'nom' => $nom, 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => Str::random(8).'@example.org',
        ]);
        $c->forceFill(['matricule' => '26EFP'.random_int(100000, 999999)])->save();
        $a = Auditeur::query()->create(['candidature_id' => $c->id]);
        $i = InscriptionAnnuelle::query()->create([
            'auditeur_id' => $a->id, 'bareme_id' => $this->bareme->id, 'annee_academique' => '2026-2027', 'niveau' => 'M1',
            'montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000,
        ]);
        if ($verse > 0) {
            app(RegistreVersements::class)->encaisser($i, ['montant' => $verse, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence',
                'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur'], $this->financier);
        }

        return $a;
    };
    // 1re tranche échue le 01/11 : 685 000 versés = en règle ; 185 000 = partiel ; rien = total.
    $this->enRegle = ($this->auditeur)('ALPHA', $this->p14, 685000);
    $this->partiel = ($this->auditeur)('BRAVO', $this->p14, 185000);
    $this->total = ($this->auditeur)('CHARLIE', $this->p13);
});

it('classe les auditeurs et totalise chaque situation', function (): void {
    $lignes = app(ListeSolvabilite::class)->lignes([]);
    $par = $lignes->pluck('categorie', 'nom')->all();

    expect($par)->toBe(['ALPHA' => Solvabilite::EN_REGLE, 'BRAVO' => Solvabilite::PARTIELLE, 'CHARLIE' => Solvabilite::TOTALE]);
    $t = ListeSolvabilite::totaux($lignes);
    expect($t[Solvabilite::PARTIELLE])->toMatchArray(['effectif' => 1, 'verse' => 185000, 'echu_impaye' => 500000, 'reste' => 1000000])
        ->and($t[Solvabilite::TOTALE])->toMatchArray(['effectif' => 1, 'echu_impaye' => 685000]);
});

it('filtre par promotion, niveau, statut et dossiers retirés', function (): void {
    $liste = app(ListeSolvabilite::class);
    $this->partiel->candidature->forceFill(['withdrawn_at' => now()])->save();
    $this->enRegle->forceFill(['statut' => Auditeur::STATUT_SUSPENDU])->save();

    expect($liste->lignes(['campagne_id' => $this->p13->id])->pluck('nom')->all())->toBe(['CHARLIE'])
        ->and($liste->lignes([])->pluck('nom')->all())->toBe(['ALPHA', 'CHARLIE'])
        ->and($liste->lignes(['inclure_retires' => true])->pluck('nom')->all())->toBe(['ALPHA', 'BRAVO', 'CHARLIE'])
        ->and($liste->lignes(['statut' => Auditeur::STATUT_ACTIF])->pluck('nom')->all())->toBe(['CHARLIE'])
        ->and($liste->lignes(['niveau' => 'M2']))->toBeEmpty();
});

it('montre un onglet par situation, avec son effectif, au service financier', function (): void {
    $this->actingAs($this->financier);

    $this->livewire(SolvabiliteAuditeurs::class)
        ->assertOk()
        ->assertSee('Totalement insolvable')->assertSee('CHARLIE')->assertDontSee('BRAVO')
        ->set('onglet', Solvabilite::PARTIELLE)->assertSee('BRAVO')->assertDontSee('CHARLIE')
        ->set('onglet', Solvabilite::EN_REGLE)->assertSee('ALPHA')
        ->set('campagne', (string) $this->p13->id)->assertDontSee('ALPHA');
});

it('ferme la page aux agents sans accès à l\'espace Finances', function (): void {
    $this->actingAs(($this->compte)('scolarite_agent'))->get(SolvabiliteAuditeurs::getUrl())->assertForbidden();
    $this->actingAs($this->financier)->get(SolvabiliteAuditeurs::getUrl())->assertOk();
});

it('exporte l\'onglet en PDF et en Excel, et journalise chaque export', function (): void {
    $this->actingAs($this->financier);
    $page = $this->livewire(SolvabiliteAuditeurs::class)->set('onglet', Solvabilite::PARTIELLE);

    $page->callAction('exporterPdf')->assertFileDownloaded(contentType: 'application/pdf');
    $page->callAction('exporterExcel')->assertFileDownloaded();

    $journal = Activity::query()->where('event', ListeSolvabilite::EVENEMENT)->orderBy('id')->get();
    expect($journal->pluck('properties.format')->all())->toBe(['pdf', 'excel'])
        ->and($journal->pluck('properties.categorie')->unique()->all())->toBe([Solvabilite::PARTIELLE])
        ->and($journal->first()->properties['effectif'])->toBe(1);

    $chemin = tempnam(sys_get_temp_dir(), 'solv');
    file_put_contents($chemin, app(ListeSolvabilite::class)->excel([], Solvabilite::PARTIELLE, $this->financier));
    $feuille = IOFactory::load($chemin)->getActiveSheet();
    unlink($chemin);
    expect($feuille->getCell('B2')->getValue())->toBe('BRAVO')->and($feuille->getCell('J2')->getValue())->toBe(500000)
        ->and($feuille->getCell('L2')->getValue())->toBe('Partiellement insolvable');

    expect(app(ListeSolvabilite::class)->pdf([], Solvabilite::PARTIELLE, $this->financier))->toStartWith('%PDF');
});

it('refuse l\'export sans le droit d\'export, et une situation inconnue', function (): void {
    $sansExport = ($this->compte)('scolarite_responsable');
    $sansExport->givePermissionTo(EspaceFinances::PERMISSION);
    Role::findByName('scolarite_responsable', 'web')->revokePermissionTo(RegistreVersements::PERMISSION_EXPORT);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($sansExport->fresh());

    $this->livewire(SolvabiliteAuditeurs::class)->assertActionHidden('exporterPdf')->assertActionHidden('exporterExcel');
    expect(fn () => app(ListeSolvabilite::class)->excel([], 'inconnue', $this->financier))->toThrow(InvalidArgumentException::class);
});
