<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\AuditeurActions;
use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\ImputationVersement;
use App\Models\RecuVersement;
use App\Models\Role;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use App\Support\CandidatureObjectStorage;
use App\Support\ModulePermissionMap;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Encaissement par le service financier (spec module-4-scolarite-encaissement-confirmation §2.1, décisions du 15/11/2026) :
 * un geste « Enregistrer et émettre le reçu », séparation levée sur le reçu, saisie retirée à la scolarité.
 */

uses()->group('scolarite');

const MIGRATION_SAISIE = '2026_12_04_100400_revoke_scolarite_versement_saisir.php';

beforeEach(function (): void {
    Storage::fake(CandidatureObjectStorage::DISK);
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.candidature_app_url', 'https://apply.pssfp.org');
    Mail::fake();
    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });

    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->agent = ($this->compte)('scolarite_agent');
    $this->responsable = ($this->compte)('scolarite_responsable');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());

    $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->awa = Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'nom' => 'Abena', 'prenom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699005001', 'email' => 'awa-'.Str::random(6).'@example.org',
        'ecampus_statut' => ComptesEcampus::CREE,
    ]);
    app(AttributionMatricules::class)->attribuer($campagne, '26', ($this->compte)('admin'));
    $this->awa->refresh();
    $this->fiche = $this->awa->auditeur;
    $this->inscription = $this->fiche->inscriptionCourante;
    $this->saisie = fn (array $champs = []): array => $champs + [
        'montant' => 500000, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(6), 'payeur' => 'auditeur',
    ];
});

describe('un geste : enregistrer et émettre le reçu', function (): void {
    it('écrit le versement et son reçu ensemble, puis prévient l\'auditeur', function (): void {
        $resultat = app(RegistreVersements::class)->encaisser($this->inscription, ($this->saisie)(['reference' => 'BOR-901']), $this->financier);

        $v = $resultat->versement;
        expect($resultat->recu)->not->toBeNull()
            ->and($resultat->recu->numero)->toBe('RCPT-2026-000001')
            ->and($resultat->recu->versement_id)->toBe($v->id)
            ->and($v->saisi_par)->toBe($this->financier->id)
            ->and($resultat->recu->emis_par)->toBe($this->financier->id)
            ->and(collect($this->sms)->filter(fn (string $t): bool => str_contains($t, 'RCPT-2026-000001'))->count())->toBe(1)
            ->and(Activity::query()->whereIn('event', ['versement_enregistre', 'recu_emis'])->pluck('event')->sort()->values()->all())->toBe(['recu_emis', 'versement_enregistre']);
    });

    it('n\'enregistre rien quand le reçu ne peut pas être émis', function (): void {
        AppSettings::set(RecusVersements::CLE_DEPUIS, null);

        expect(fn () => app(RegistreVersements::class)->encaisser($this->inscription, ($this->saisie)(), $this->financier))
            ->toThrow(InvalidArgumentException::class, 'mise en service');

        expect(Versement::query()->count())->toBe(0)
            ->and(ImputationVersement::query()->count())->toBe(0)
            ->and(RecuVersement::query()->count())->toBe(0)
            ->and(collect($this->sms)->filter(fn (string $t): bool => str_contains($t, 'recu'))->all())->toBe([]);
    });

    it('exige la saisie et l\'émission du reçu', function (): void {
        expect(fn () => app(RegistreVersements::class)->encaisser($this->inscription, ($this->saisie)(), ($this->compte)('admin')))->toThrow(HttpException::class)
            ->and(fn () => app(RegistreVersements::class)->encaisser($this->inscription, ($this->saisie)(), $this->agent))->toThrow(HttpException::class)
            ->and(Versement::query()->count())->toBe(0);
    });

    it('propose le geste unique au service financier depuis la fiche', function (): void {
        $this->actingAs($this->financier);
        $page = $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()]);
        $page->assertActionVisible('enregistrerVersement')
            ->assertActionHasLabel('enregistrerVersement', 'Enregistrer et émettre le reçu')
            ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-902']) + ['justificatif_path' => bordereauScanne()])
            ->assertHasNoActionErrors()
            ->assertNotified('Versement enregistré, reçu RCPT-2026-000001 émis');

        expect(RecuVersement::query()->sole()->versement->reference)->toBe('BOR-902');
    });

    it('n\'offre plus la saisie à un administrateur ; le super administrateur encaisse avec le reçu', function (): void {
        $this->actingAs(($this->compte)('admin'));
        $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])->assertActionHidden('enregistrerVersement');

        $this->actingAs(($this->compte)('super_admin'));
        $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])
            ->assertActionHasLabel('enregistrerVersement', 'Enregistrer et émettre le reçu')
            ->callAction('enregistrerVersement', data: ($this->saisie)(['reference' => 'BOR-903']) + ['justificatif_path' => bordereauScanne()])
            ->assertHasNoActionErrors();

        expect(Versement::query()->count())->toBe(1)
            ->and(RecuVersement::query()->count())->toBe(1);
    });
});

describe('séparation levée sur le reçu', function (): void {
    it('laisse l\'agent du service financier émettre le reçu d\'un versement qu\'il a saisi, et le compte en attente', function (): void {
        $v = app(RegistreVersements::class)->enregistrer($this->inscription, ($this->saisie)(), $this->financier)->versement;
        $recus = app(RecusVersements::class);

        expect($recus->raisonRefus($v, $this->financier))->toBeNull()
            ->and($recus->enAttente($this->financier)->pluck('id')->all())->toBe([$v->id])
            ->and($recus->emettre($v, $this->financier)->numero)->toBe('RCPT-2026-000001');
    });
});

describe('saisie réservée au service financier', function (): void {
    it('retire la saisie à l\'agent et au responsable de scolarité', function (): void {
        expect($this->agent->can(RegistreVersements::PERMISSION_SAISIR))->toBeFalse()
            ->and($this->responsable->can(RegistreVersements::PERMISSION_SAISIR))->toBeFalse()
            ->and($this->financier->can(RegistreVersements::PERMISSION_SAISIR))->toBeTrue()
            ->and(fn () => app(RegistreVersements::class)->enregistrer($this->inscription, ($this->saisie)(), $this->agent))->toThrow(HttpException::class)
            ->and(fn () => app(RegistreVersements::class)->enregistrer($this->inscription, ($this->saisie)(), $this->responsable))->toThrow(HttpException::class);

        $this->actingAs($this->agent);
        $this->livewire(ViewAuditeur::class, ['record' => $this->fiche->getRouteKey()])->assertActionHidden('enregistrerVersement');
    });

    it('range la scolarité au niveau lecture, le service financier au niveau gestion', function (): void {
        $niveau = fn (string $role): string => ModulePermissionMap::toLevels(Role::findByName($role, 'web')->permissions->pluck('name')->all())['scolarite'];

        expect($niveau('scolarite_agent'))->toBe(ModulePermissionMap::LEVEL_READ)
            ->and($niveau('scolarite_responsable'))->toBe(ModulePermissionMap::LEVEL_READ)
            ->and($niveau('admin'))->toBe(ModulePermissionMap::LEVEL_READ)
            ->and($niveau('service_financier'))->toBe(ModulePermissionMap::LEVEL_MANAGE);
    });

    it('retire la saisie par migration additive, la garde au service financier, et se défait', function (): void {
        // État de la production avant la migration : les deux rôles de scolarité et l'administrateur saisissent.
        Role::findByName('scolarite_agent', 'web')->givePermissionTo(RegistreVersements::PERMISSION_SAISIR);
        Role::findByName('scolarite_responsable', 'web')->givePermissionTo(RegistreVersements::PERMISSION_SAISIR);
        Role::findByName('admin', 'web')->givePermissionTo(RegistreVersements::PERMISSION_SAISIR);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $avant = fn (string $r): int => Role::findByName($r, 'web')->permissions()->count();
        $agentAvant = $avant('scolarite_agent');
        $financierAvant = $avant('service_financier');

        $migration = require database_path('migrations/'.MIGRATION_SAISIE);
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $droits = fn (string $r) => Role::findByName($r, 'web')->permissions()->pluck('name');
        expect($droits('scolarite_agent'))->not->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($droits('scolarite_responsable'))->not->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($droits('service_financier'))->toContain(RegistreVersements::PERMISSION_SAISIR)->toContain(RecusVersements::PERMISSION_EMETTRE)
            ->and($droits('admin'))->not->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($droits('super_admin'))->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($avant('scolarite_agent'))->toBe($agentAvant - 1)
            ->and($avant('service_financier'))->toBe($financierAvant);

        $migration->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect($droits('scolarite_agent'))->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($droits('scolarite_responsable'))->toContain(RegistreVersements::PERMISSION_SAISIR)
            ->and($droits('admin'))->toContain(RegistreVersements::PERMISSION_SAISIR);
    });

    it('laisse à la scolarité, et non plus à la saisie, le renvoi des accès et du lien vers la lettre', function (): void {
        $this->actingAs($this->agent);
        expect(AuditeurActions::peutRenvoyerAcces())->toBeTrue();

        $this->actingAs($this->financier);
        expect(AuditeurActions::peutRenvoyerAcces())->toBeFalse();
    });
});
