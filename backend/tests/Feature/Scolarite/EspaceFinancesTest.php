<?php

declare(strict_types=1);

use App\Filament\Navigation\GroupeNavigation;
use App\Filament\Resources\RecuVersementResource;
use App\Filament\Resources\RecuVersementResource\Pages\ListRecusVersements;
use App\Filament\Resources\VersementResource;
use App\Filament\Resources\VersementResource\Pages\ListVersements;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\RecuVersement;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\EspaceFinances;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\VersementsExcel;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use App\Support\ModulePermissionMap;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Espace Finances, sous-lot F1 (spec module-4-scolarite-espace-financier §4, §6.2, §6.3 et §9 ; décisions du
 * 16/11/2026) : groupe de menu, liste des versements avec leur statut, reçus, permission finances.consulter.
 */

uses()->group('scolarite');

const MIGRATION_FINANCES = '2026_12_08_100100_grant_finances_consulter.php';

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());

    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $dossier = fn (string $nom): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => $nom, 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+2376990'.random_int(10000, 99999),
        'email' => mb_strtolower($nom).'-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    $this->a = $dossier('Alpha');
    $this->b = $dossier('Beta');
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->a->refresh();
    $this->b->refresh();
    $this->saisie = fn (array $champs = []): array => $champs + [
        'montant' => 100000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ];
    $this->encaisser = fn (Candidature $c, array $champs = []): Versement => app(RegistreVersements::class)
        ->encaisser($c->auditeur->inscriptionCourante, ($this->saisie)($champs), $this->financier)->versement;
});

describe('permissions', function (): void {
    it('ouvre l\'espace Finances au service financier, au responsable et aux administrateurs, pas à l\'agent', function (): void {
        foreach (['service_financier', 'scolarite_responsable', 'admin', 'super_admin'] as $role) {
            expect(($this->compte)($role)->can(EspaceFinances::PERMISSION))->toBeTrue("{$role} consulte");
        }
        expect(($this->compte)('scolarite_agent')->can(EspaceFinances::PERMISSION))->toBeFalse()
            ->and($this->financier->can(RegistreVersements::PERMISSION_EXPORT))->toBeTrue()
            ->and(ModulePermissionMap::sensitiveActions())->toHaveKey(EspaceFinances::PERMISSION);
    });

    it('accorde par migration additive, rejouable, et se défait', function (): void {
        // État de la production avant la migration.
        Permission::query()->where('name', EspaceFinances::PERMISSION)->delete();
        Role::findByName('service_financier', 'web')->revokePermissionTo(RegistreVersements::PERMISSION_EXPORT);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $exportAvant = fn (string $r): bool => Role::findByName($r, 'web')->hasPermissionTo(RegistreVersements::PERMISSION_EXPORT);
        $agentExport = $exportAvant('scolarite_agent');

        $migration = require database_path('migrations/'.MIGRATION_FINANCES);
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['service_financier', 'scolarite_responsable', 'admin', 'super_admin'] as $role) {
            expect(Role::findByName($role, 'web')->hasPermissionTo(EspaceFinances::PERMISSION))->toBeTrue();
        }
        expect(Role::findByName('scolarite_agent', 'web')->hasPermissionTo(EspaceFinances::PERMISSION))->toBeFalse()
            ->and($exportAvant('service_financier'))->toBeTrue()
            ->and($exportAvant('scolarite_agent'))->toBe($agentExport);

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect(Permission::query()->where('name', EspaceFinances::PERMISSION)->exists())->toBeFalse()
            ->and($exportAvant('service_financier'))->toBeFalse()
            ->and($exportAvant('scolarite_agent'))->toBe($agentExport);
    });
});

describe('menu', function (): void {
    it('range le groupe Finances juste après la Scolarité, avec les versements et les reçus', function (): void {
        $ordre = GroupeNavigation::ORDRE;
        expect($ordre[array_search(GroupeNavigation::SCOLARITE, $ordre, true) + 1])->toBe(GroupeNavigation::FINANCES)
            ->and(VersementResource::getNavigationGroup())->toBe(GroupeNavigation::FINANCES)
            ->and(RecuVersementResource::getNavigationGroup())->toBe(GroupeNavigation::FINANCES);
    });

    it('ferme les listes à l\'agent de scolarité', function (): void {
        $this->actingAs(($this->compte)('scolarite_agent'));
        expect(VersementResource::canAccess())->toBeFalse()
            ->and(RecuVersementResource::canAccess())->toBeFalse();

        foreach (['service_financier', 'scolarite_responsable', 'admin', 'super_admin'] as $role) {
            $this->actingAs(($this->compte)($role));
            expect(VersementResource::canAccess())->toBeTrue("{$role} : versements")
                ->and(RecuVersementResource::canAccess())->toBeTrue("{$role} : reçus");
        }
    });
});

describe('statut d\'un versement', function (): void {
    it('se déduit des tables : enregistré, confirmé, annulé', function (): void {
        $enregistre = ($this->encaisser)($this->a);
        $confirme = ($this->encaisser)($this->a);
        confirmerVersement($confirme);
        $annule = ($this->encaisser)($this->b);
        app(RegistreVersements::class)->annuler($annule, 'Doublon de saisie', ($this->compte)('scolarite_responsable'));

        expect($enregistre->refresh()->statut())->toBe(Versement::STATUT_ENREGISTRE)
            ->and($confirme->refresh()->statut())->toBe(Versement::STATUT_CONFIRME)
            ->and($annule->refresh()->statut())->toBe(Versement::STATUT_ANNULE)
            ->and(Versement::query()->statut(Versement::STATUT_ENREGISTRE)->pluck('id')->all())->toBe([$enregistre->id])
            ->and(Versement::query()->statut(Versement::STATUT_CONFIRME)->pluck('id')->all())->toBe([$confirme->id])
            ->and(Versement::query()->statut(Versement::STATUT_ANNULE)->pluck('id')->all())->toBe([$annule->id]);
    });
});

describe('liste des versements', function (): void {
    it('montre par défaut les versements valides, avec leur statut, et cherche par matricule ou référence', function (): void {
        $va = ($this->encaisser)($this->a, ['reference' => 'BOR-A1']);
        $vb = ($this->encaisser)($this->b, ['reference' => 'BOR-B1']);
        $annule = ($this->encaisser)($this->b, ['reference' => 'BOR-B2']);
        app(RegistreVersements::class)->annuler($annule, 'Doublon de saisie', ($this->compte)('scolarite_responsable'));

        $this->actingAs($this->financier);
        $this->livewire(ListVersements::class)
            ->assertCanSeeTableRecords([$va, $vb])
            ->assertCanNotSeeTableRecords([$annule])
            ->assertTableColumnFormattedStateSet('statut', 'Enregistré', $va)
            ->searchTable($this->a->matricule)
            ->assertCanSeeTableRecords([$va])
            ->assertCanNotSeeTableRecords([$vb])
            ->searchTable('BOR-B1')
            ->assertCanSeeTableRecords([$vb])
            ->assertCanNotSeeTableRecords([$va]);

        $this->livewire(ListVersements::class)
            ->filterTable('statut', [Versement::STATUT_ANNULE])
            ->assertCanSeeTableRecords([$annule])
            ->assertCanNotSeeTableRecords([$va, $vb]);
    });

    it('enregistre un versement et son reçu depuis la liste, pour l\'auditeur choisi', function (): void {
        $this->actingAs($this->financier);
        $this->livewire(ListVersements::class)
            ->assertActionVisible('encaisser')
            ->callAction('encaisser', data: ['auditeur_id' => $this->b->auditeur->id] + ($this->saisie)(['reference' => 'BOR-LISTE']))
            ->assertHasNoActionErrors()
            ->assertNotified('Versement enregistré, reçu RCPT-2026-000001 émis');

        $v = Versement::query()->sole();
        expect($v->auditeur_id)->toBe($this->b->auditeur->id)
            ->and($v->saisi_par)->toBe($this->financier->id)
            ->and(RecuVersement::query()->sole()->versement_id)->toBe($v->id);
    });

    it('n\'offre l\'enregistrement qu\'à qui encaisse ; l\'export suit scolarite.export', function (): void {
        $this->actingAs(($this->compte)('scolarite_responsable'));
        $this->livewire(ListVersements::class)->assertActionHidden('encaisser')->assertActionVisible('exporter');

        $sansExport = ($this->compte)('scolarite_responsable');
        $sansExport->givePermissionTo(EspaceFinances::PERMISSION);
        Role::findByName('scolarite_responsable', 'web')->revokePermissionTo(RegistreVersements::PERMISSION_EXPORT);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($sansExport->fresh());
        $this->livewire(ListVersements::class)->assertActionHidden('exporter');
    });

    it('exporte les lignes filtrées en Excel, montants en nombres, et journalise les critères sans personne', function (): void {
        $va = ($this->encaisser)($this->a, ['reference' => 'BOR-X1', 'montant' => 120000]);
        ($this->encaisser)($this->b, ['reference' => 'BOR-X2']);

        $this->actingAs($this->financier);
        $this->livewire(ListVersements::class)
            ->searchTable('BOR-X1')
            ->callAction('exporter')
            ->assertFileDownloaded();

        $contenu = app(VersementsExcel::class)->generer(Versement::query()->whereKey($va->id)->get());
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichier, $contenu);
        $feuille = IOFactory::load($fichier)->getActiveSheet();
        unlink($fichier);
        $entetes = $feuille->rangeToArray('A1:'.$feuille->getHighestColumn().'1')[0];
        $ligne = array_combine($entetes, $feuille->rangeToArray('A2:'.$feuille->getHighestColumn().'2')[0]);

        $journal = Activity::query()->where('event', 'finances_export')->sole();
        expect($ligne['Référence'])->toBe('BOR-X1')
            ->and($ligne['Matricule'])->toBe($this->a->matricule)
            ->and($ligne['Statut'])->toBe('Enregistré')
            ->and($feuille->getCell('A2')->getDataType())->toBe('s')
            ->and($feuille->getCell(Coordinate::stringFromColumnIndex((int) array_search('Montant', $entetes, true) + 1).'2')->getValue())->toBe(120000)
            ->and($journal->properties['ecran'])->toBe('versements')
            ->and($journal->properties['lignes'])->toBe(1)
            ->and(json_encode($journal->properties))->not->toContain('Alpha')
            ->and(json_encode($journal->properties))->not->toContain((string) $this->a->matricule);
    });

    it('lit l\'export par morceaux', function (): void {
        ($this->encaisser)($this->a, ['reference' => 'BOR-L1']);
        ($this->encaisser)($this->b, ['reference' => 'BOR-L2']);

        $lecture = VersementsExcel::lireVersements(Versement::query()->orderBy('id'));
        expect($lecture)->toBeInstanceOf(LazyCollection::class)
            ->and(app(VersementsExcel::class)->generer($lecture))->not->toBe('');
    });
});

describe('périodes en jours de Yaoundé, application en UTC (production)', function (): void {
    it('borne l\'enregistrement et l\'émission sur le jour de Yaoundé', function (): void {
        config()->set('app.timezone', 'UTC');
        // 23 h 30 à Yaoundé le 09/11 (22 h 30 UTC), puis 0 h 30 à Yaoundé le 10/11 (23 h 30 UTC le 09/11).
        $this->travelTo(CarbonImmutable::parse('2026-11-09 22:30:00', 'UTC'));
        $soir = ($this->encaisser)($this->a, ['reference' => 'BOR-SOIR', 'date_versement' => '2026-11-09']);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 23:30:00', 'UTC'));
        $minuit = ($this->encaisser)($this->b, ['reference' => 'BOR-MINUIT', 'date_versement' => '2026-11-09']);

        $this->actingAs($this->financier);
        $this->livewire(ListVersements::class)
            ->filterTable('periode', ['date' => 'enregistrement', 'du' => '2026-11-09', 'au' => '2026-11-09'])
            ->assertCanSeeTableRecords([$soir])
            ->assertCanNotSeeTableRecords([$minuit])
            ->filterTable('periode', ['date' => 'enregistrement', 'du' => '2026-11-10', 'au' => '2026-11-10'])
            ->assertCanSeeTableRecords([$minuit])
            ->assertCanNotSeeTableRecords([$soir]);

        $this->livewire(ListRecusVersements::class)
            ->filterTable('emission', ['du' => '2026-11-10', 'au' => '2026-11-10'])
            ->assertCanSeeTableRecords([$minuit->recuEnVigueur()])
            ->assertCanNotSeeTableRecords([$soir->recuEnVigueur()]);
    });
});

describe('reçus', function (): void {
    it('s\'ouvrent à l\'espace Finances et exportent le registre de l\'année', function (): void {
        ($this->encaisser)($this->a);
        ($this->encaisser)($this->b);

        $this->actingAs(($this->compte)('scolarite_responsable'));
        $this->livewire(ListRecusVersements::class)
            ->assertCanSeeTableRecords(RecuVersement::query()->get())
            ->callAction('exporterRegistre')
            ->assertFileDownloaded();

        expect(Activity::query()->where('event', 'finances_export')->sole()->properties['ecran'])->toBe('recus');
    });
});
