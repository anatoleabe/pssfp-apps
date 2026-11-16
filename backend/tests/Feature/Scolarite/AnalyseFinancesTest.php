<?php

declare(strict_types=1);

use App\Filament\Pages\Scolarite\AnalyseFinances;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AnalyseFinancesExcel;
use App\Services\Scolarite\CriteresFinances;
use App\Services\Scolarite\IndicateursFinanciers;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

/*
 * Analyse de l'espace Finances, sous-lot F3 (spec module-4-scolarite-espace-financier §6.1) : tableau croisé, total
 * recalculé, export Excel en trois feuilles, journal sans donnée nominative. Même calcul que les fiches.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);

    $admin = ($this->compte)('admin');
    foreach ([14 => ['Alpha', 'Beta'], 15 => ['Gamma']] as $promotion => $noms) {
        $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Promotion '.$promotion], 'promotion_numero' => $promotion]);
        foreach ($noms as $nom) {
            Candidature::factory()->forCampagne($campagne)->submitted()->create([
                'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
                'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
            ]);
        }
        app(AttributionMatricules::class)->attribuer($campagne, (string) ($promotion + 12), $admin);
    }
    foreach (['Alpha' => 300000, 'Beta' => 50000, 'Gamma' => 185000] as $nom => $montant) {
        $i = Candidature::query()->where('nom', $nom)->firstOrFail()->auditeur->inscriptionCourante;
        $v = app(RegistreVersements::class)->encaisser($i, ['montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence',
            'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur'], $this->financier)->versement;
        if ($nom !== 'Beta') {
            confirmerVersement($v);
        }
    }
    $this->lignes = app(IndicateursFinanciers::class)->lignes(CriteresFinances::depuis(['annee' => '2026-2027']));
});

it('regroupe par promotion puis niveau, avec un total égal à la somme des fiches', function (): void {
    $tableau = app(AnalyseFinancesExcel::class)->tableau($this->lignes, ['promotion', 'niveau']);
    $total = end($tableau);
    $fiches = InscriptionAnnuelle::query()->get()->map(fn (InscriptionAnnuelle $i) => $i->situation());

    expect($tableau)->toHaveCount(3)
        ->and(array_column($tableau, 'cles'))->toBe([['14e promotion', 'M1'], ['15e promotion', 'M1'], ['Total', 'Total']])
        ->and($tableau[0]['indicateurs']['inscrits'])->toBe(2)
        ->and($total['indicateurs']['du'])->toBe($fiches->sum('duTotal'))
        ->and($total['indicateurs']['verse'])->toBe($fiches->sum('verse'))
        ->and($total['indicateurs']['a_confirmer'])->toBe(50000)
        ->and($tableau[0]['indicateurs']['du'] + $tableau[1]['indicateurs']['du'])->toBe($total['indicateurs']['du'])
        ->and($total['indicateurs']['taux'])->toBe(round(100 * $total['indicateurs']['verse'] / $total['indicateurs']['du'], 1));
});

it('exporte trois feuilles, montants en nombres', function (): void {
    $contenu = app(AnalyseFinancesExcel::class)->generer($this->lignes, ['promotion'], ['Année académique' => '2026-2027']);
    $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($fichier, $contenu);
    $classeur = IOFactory::load($fichier);
    unlink($fichier);

    expect($classeur->getSheetNames())->toBe(['Synthèse', 'Auditeurs', 'Versements'])
        ->and($classeur->getSheet(0)->getCell('A1')->getValue())->toBe('Année académique')
        ->and($classeur->getSheet(1)->getHighestRow())->toBe(1 + 3)
        ->and($classeur->getSheet(1)->getCell('J2')->getValue())->toBeInt()
        ->and($classeur->getSheet(2)->getHighestRow())->toBe(1 + Versement::query()->count());
});

it('remplace le détail des versements par un renvoi au-delà du plafond d\'export', function (): void {
    config()->set('scolarite.export_max', 2);
    $contenu = app(AnalyseFinancesExcel::class)->generer($this->lignes, ['promotion'], ['Année académique' => '2026-2027']);
    $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($fichier, $contenu);
    $feuille = IOFactory::load($fichier)->getSheet(2);
    unlink($fichier);

    expect(Versement::query()->count())->toBe(3)
        ->and($feuille->getHighestRow())->toBe(2)
        ->and((string) $feuille->getCell('A2')->getValue())->toStartWith('Plus de 2 versements');
});

it('s\'ouvre à l\'espace Finances, journalise l\'export sans nom, et se ferme à l\'agent', function (): void {
    $this->actingAs($this->financier);
    $this->livewire(AnalyseFinances::class)->assertOk()->assertSee('Tableau croisé')->assertSee('14e promotion')
        ->set('lignesPar', 'niveau')->set('situation', 'non_soldee')->assertSee('M1')
        ->callAction('exporter')->assertFileDownloaded();

    $journal = Activity::query()->where('event', 'finances_export')->sole();
    expect($journal->properties['ecran'])->toBe('analyse')
        ->and(json_encode($journal->properties))->not->toContain('Alpha');

    $this->actingAs(($this->compte)('scolarite_agent'));
    expect(AnalyseFinances::canAccess())->toBeFalse();
});
