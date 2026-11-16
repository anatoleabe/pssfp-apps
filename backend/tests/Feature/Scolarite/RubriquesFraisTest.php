<?php

declare(strict_types=1);

use App\Filament\Resources\RubriqueFraisResource;
use App\Filament\Resources\RubriqueFraisResource\Pages\EditRubriqueFrais;
use App\Filament\Resources\RubriqueFraisResource\Pages\ListRubriquesFrais;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\RecuVersement;
use App\Models\Role;
use App\Models\RubriqueFrais;
use App\Models\User;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\RubriquesFrais;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/*
 * Rubriques de frais des reçus, R2 : ventilation par rubriques (ordre de répartition, remise sur la pension, compte de
 * scolarité de la promotion), figée à l'émission ; écran Finances > Rubriques réservé en écriture à l'administrateur.
 * Données fictives.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($mock) => $mock->shouldReceive('send')->andReturnNull());
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local', 'name' => 'Agent '.$role]), fn (User $u) => $u->assignRole($role));
    $this->financier = ($this->compte)('service_financier');
    $this->admin = ($this->compte)('admin');
    $this->travelTo(now('Africa/Douala')->setDate(2026, 11, 10)->setTime(9, 0));
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->auditeur = function (int $promotion, ?string $compte): InscriptionAnnuelle {
        $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Promotion '.$promotion], 'promotion_numero' => $promotion, 'compte_scolarite' => $compte]);
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create(['nom' => 'Essai'.$promotion, 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => 'essai-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE]);
        app(AttributionMatricules::class)->attribuer($campagne, (string) ($promotion + 12), $this->admin);

        return $c->fresh()->auditeur->inscriptionCourante;
    };
    $this->encaisser = fn (InscriptionAnnuelle $i, int $montant): RecuVersement => app(RegistreVersements::class)->encaisser($i->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->financier)->recu;
});

describe('migrations', function (): void {
    it('pré-remplit le M1 2026-2027 avec les codes de la RAF, la pension selon la promotion', function (): void {
        $rubriques = RubriqueFrais::query()->duCouple('2026-2027', 'M1')->get();

        expect($rubriques->pluck('montant')->all())->toBe([50000, 35000, 0, 50000, 1050000])
            ->and($rubriques->pluck('code_imputation')->all())->toBe(['710010', '770830', '770820', '770810', null])
            ->and($rubriques->last()->compte_selon_promotion)->toBeTrue()
            ->and((int) $rubriques->sum('montant'))->toBe(1185000)
            ->and(RubriqueFrais::query()->where('niveau', 'M2')->exists())->toBeFalse();
    });

    it('pré-remplit le compte de scolarité des promotions et refuse 710100', function (): void {
        $p13 = CampagneCandidature::factory()->closed()->create(['promotion_numero' => 13]);
        $p10 = CampagneCandidature::factory()->closed()->create(['promotion_numero' => 10]);
        $migration = require database_path('migrations/2026_12_12_100300_add_compte_scolarite_to_campagnes_candidature.php');
        $migration->down();
        $migration->up();

        expect($p13->fresh()->compte_scolarite)->toBe('710170')
            ->and($p10->fresh()->compte_scolarite)->toBe('710140');
        DB::beginTransaction();
        try {
            expect(fn () => DB::table('campagnes_candidature')->where('id', $p10->id)->update(['compte_scolarite' => '710100']))->toThrow(QueryException::class);
        } finally {
            DB::rollBack();
        }
    });

    it('accorde la gestion des rubriques à l\'administrateur seulement, par migration additive', function (): void {
        Permission::query()->where('name', RubriquesFrais::PERMISSION)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $migration = require database_path('migrations/2026_12_12_100400_grant_finances_rubriques_gerer.php');
        $migration->up();
        $migration->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        expect(Role::findByName('admin', 'web')->hasPermissionTo(RubriquesFrais::PERMISSION))->toBeTrue()
            ->and(Role::findByName('super_admin', 'web')->hasPermissionTo(RubriquesFrais::PERMISSION))->toBeTrue()
            ->and(Role::findByName('service_financier', 'web')->hasPermissionTo(RubriquesFrais::PERMISSION))->toBeFalse();
        $migration->down();
        expect(Permission::query()->where('name', RubriquesFrais::PERMISSION)->exists())->toBeFalse();
    });
});

describe('ventilation par rubriques', function (): void {
    it('remplit les rubriques dans l\'ordre, pension au compte de la promotion, comme le reçu ACMA', function (): void {
        $i = ($this->auditeur)(14, '710180');
        $droits = ($this->encaisser)($i, 185000);
        $tranche = ($this->encaisser)($i, 500000);

        expect(array_column($droits->ventilation, 'ce_versement'))->toBe([50000, 35000, 0, 50000, 50000])
            ->and(array_column($droits->ventilation, 'code'))->toBe(['710010', '770830', '770820', '770810', '710180'])
            ->and(array_column($droits->ventilation, 'reste'))->toBe([0, 0, 0, 0, 1000000])
            ->and(array_column($tranche->ventilation, 'ce_versement'))->toBe([0, 0, 0, 0, 500000])
            ->and(collect($tranche->ventilation)->last()['total_verse'])->toBe(550000)
            ->and($tranche->reste_a_payer)->toBe(500000);
    });

    it('déduit la remise de la pension, visiblement, et garde la ligne de l\'avance', function (): void {
        $i = ($this->auditeur)(14, '710180');
        app(RegistreVersements::class)->accorderRemise($i, 100000, 'Bourse partielle', ($this->compte)('scolarite_responsable'));
        $recu = ($this->encaisser)($i, 1100000);

        $pension = $recu->ventilation[4];
        expect($pension['du'])->toBe(950000)
            ->and($pension['libelle'])->toBe('Pension (frais de scolarité) (remise de 100 000 FCFA déduite)')
            ->and($pension['reste'])->toBe(0)
            ->and(collect($recu->ventilation)->last())->toMatchArray(['libelle' => 'Avance sur la scolarité à venir', 'ce_versement' => 15000]);
    });

    it('affiche « à préciser » pour une promotion sans compte, et retombe sur les paliers si la somme diffère du barème', function (): void {
        $sansCompte = ($this->auditeur)(15, null);
        expect(collect(($this->encaisser)($sansCompte, 185000)->ventilation)->last()['code'])->toBeNull();

        RubriqueFrais::query()->duCouple('2026-2027', 'M1')->where('ordre', 2)->firstOrFail()->update(['montant' => 30000]);
        $paliers = ($this->encaisser)(($this->auditeur)(14, '710180'), 185000);
        expect(array_column($paliers->ventilation, 'libelle'))->toBe(['Droits d\'inscription', '1re tranche', '2e tranche']);
    });

    it('fige la ventilation : changer les rubriques ensuite ne change pas un reçu émis', function (): void {
        $recu = ($this->encaisser)(($this->auditeur)(14, '710180'), 185000);
        $avant = $recu->ventilation;
        RubriqueFrais::query()->duCouple('2026-2027', 'M1')->where('ordre', 1)->firstOrFail()->update(['libelle' => 'Droits d\'inscription administrative']);

        expect($recu->fresh()->ventilation)->toEqual($avant);
    });
});

describe('écran Finances > Rubriques', function (): void {
    it('se lit par l\'espace Finances et ne se modifie que par l\'administrateur, journalisé', function (): void {
        BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
        $this->actingAs($this->financier);
        expect(RubriqueFraisResource::canAccess())->toBeTrue()->and(RubriqueFraisResource::canCreate())->toBeFalse();
        $this->livewire(ListRubriquesFrais::class)->assertOk()->assertSee('Frais de bibliothèque')
            ->assertSee('2026-2027 M2 : pas de rubriques')->assertActionHidden('proposer')->assertActionHidden('comptesPromotions');

        $this->actingAs($this->admin);
        $pension = RubriqueFrais::query()->duCouple('2026-2027', 'M1')->where('ordre', 5)->firstOrFail();
        $this->livewire(EditRubriqueFrais::class, ['record' => $pension->getRouteKey()])
            ->fillForm(['montant' => 1000000])->call('save')->assertHasNoFormErrors();
        expect(Activity::query()->where('log_name', 'finances')->where('subject_id', $pension->id)->latest('id')->first()->properties['attributes']['montant'])->toBe(1000000);
        $this->livewire(ListRubriquesFrais::class)->assertSee('les rubriques totalisent 1 135 000 FCFA');

        $this->actingAs(($this->compte)('scolarite_agent'));
        expect(RubriqueFraisResource::canAccess())->toBeFalse();
    });

    it('propose les rubriques types d\'un couple vide et met à jour les comptes des promotions', function (): void {
        $p14 = CampagneCandidature::factory()->closed()->create(['promotion_numero' => 14, 'compte_scolarite' => null]);
        $this->actingAs($this->admin);
        $this->livewire(ListRubriquesFrais::class)
            ->callAction('proposer', data: ['annee_academique' => '2026-2027', 'niveau' => 'M2'])->assertHasNoActionErrors()
            ->callAction('comptesPromotions', data: ['comptes' => [(string) $p14->id => '710180']])->assertHasNoActionErrors();

        expect(RubriqueFrais::query()->duCouple('2026-2027', 'M2')->pluck('code_imputation')->all())->toBe(['710010', '770830', '770820', '770810', null])
            ->and($p14->fresh()->compte_scolarite)->toBe('710180')
            ->and(Activity::query()->where('event', 'compte_scolarite_modifie')->exists())->toBeTrue()
            ->and(RubriquesFrais::compteValide('710100'))->toBeFalse();
    });
});
