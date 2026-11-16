<?php

declare(strict_types=1);

use App\Filament\Resources\CampagneCandidatureResource\Pages\EditCampagneCandidature;
use App\Filament\Resources\RubriqueFraisResource;
use App\Filament\Resources\RubriqueFraisResource\Pages\ListRubriquesFrais;
use App\Models\Auditeur;
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
use Filament\Forms\Components\Repeater;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    $this->m2 = BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    $this->auditeur = function (int $promotion, ?string $compte): InscriptionAnnuelle {
        $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Promotion '.$promotion], 'promotion_numero' => $promotion, 'compte_scolarite' => $compte]);
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create(['nom' => 'Essai'.$promotion, 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+2376990'.random_int(10000, 99999), 'email' => 'essai-'.Str::random(6).'@example.org', 'ecampus_statut' => ComptesEcampus::CREE]);
        app(AttributionMatricules::class)->attribuer($campagne, (string) ($promotion + 12), $this->admin);

        return $c->fresh()->auditeur->inscriptionCourante;
    };
    // Dossier construit à la main (comme ListesAuditeursTest) : promotion, compte, inscriptions sur les barèmes donnés.
    $this->dossier = function (int $promotion, ?string $compte, array $baremes): Auditeur {
        $campagne = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Promotion '.$promotion], 'promotion_numero' => $promotion, 'compte_scolarite' => $compte]);
        $c = Candidature::factory()->forCampagne($campagne)->submitted()->create(['nom' => 'Dossier'.$promotion, 'prenom' => 'Fictif', 'statut' => Candidature::STATUT_ACCEPTE,
            'phone_e164' => '+2376991'.random_int(10000, 99999), 'email' => 'dossier-'.Str::random(6).'@example.org']);
        $c->forceFill(['matricule' => '25TST'.random_int(1000, 9999), 'rang_retenu' => 1, 'matricule_attribue_at' => now()])->save();
        $a = Auditeur::query()->create(['candidature_id' => $c->id]);
        foreach ($baremes as $b) {
            InscriptionAnnuelle::factory()->create(['auditeur_id' => $a->id, 'bareme_id' => $b->id]);
        }

        return $a->fresh();
    };
    $this->encaisser = fn (InscriptionAnnuelle $i, int $montant): RecuVersement => app(RegistreVersements::class)->encaisser($i->fresh(), [
        'montant' => $montant, 'date_versement' => '2026-11-09', 'mode' => 'cremincam_agence', 'reference' => 'BOR-'.Str::random(8), 'payeur' => 'auditeur',
    ], $this->financier)->recu;
});

describe('migrations', function (): void {
    it('pré-remplit le M1 et le M2 2026-2027 avec les codes de la RAF, la pension selon la promotion', function (): void {
        $m1 = RubriqueFrais::query()->duCouple('2026-2027', 'M1')->get();
        $m2 = RubriqueFrais::query()->duCouple('2026-2027', 'M2')->get();

        expect($m1->pluck('montant')->all())->toBe([50000, 35000, 50000, 50000, 1000000])
            ->and($m1->pluck('code_imputation')->all())->toBe(['710010', '770830', '770820', '770810', null])
            ->and($m1->last()->compte_selon_promotion)->toBeTrue()
            ->and((int) $m1->sum('montant'))->toBe(1185000)
            ->and($m2->pluck('montant')->all())->toBe([50000, 35000, 0, 50000, 1000000])
            ->and($m2->pluck('code_imputation')->all())->toBe(['710010', '770830', '770820', '770810', null])
            ->and((int) $m2->sum('montant'))->toBe(1135000);

        $migration = require database_path('migrations/2026_12_12_100500_preremplir_rubriques_frais_m1_2026_2027.php');
        $migration->down();
        expect(RubriqueFrais::query()->where('annee_academique', '2026-2027')->exists())->toBeFalse();
        $migration->up();
        $migration->up();
        expect(RubriqueFrais::query()->where('annee_academique', '2026-2027')->count())->toBe(10);
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

        expect(array_column($droits->ventilation, 'ce_versement'))->toBe([50000, 35000, 50000, 50000, 0])
            ->and(array_column($droits->ventilation, 'code'))->toBe(['710010', '770830', '770820', '770810', '710180'])
            ->and(array_column($droits->ventilation, 'reste'))->toBe([0, 0, 0, 0, 1000000])
            ->and(array_column($tranche->ventilation, 'ce_versement'))->toBe([0, 0, 0, 0, 500000])
            ->and(collect($tranche->ventilation)->last()['total_verse'])->toBe(500000)
            ->and($tranche->reste_a_payer)->toBe(500000);
    });

    it('déduit la remise de la pension, visiblement, et garde la ligne de l\'avance', function (): void {
        $i = ($this->auditeur)(14, '710180');
        app(RegistreVersements::class)->accorderRemise($i, 100000, 'Bourse partielle', ($this->compte)('scolarite_responsable'));
        $recu = ($this->encaisser)($i, 1100000);

        $pension = $recu->ventilation[4];
        expect($pension['du'])->toBe(900000)
            ->and($pension['libelle'])->toBe('Pension (frais de scolarité) (remise de 100 000 FCFA déduite)')
            ->and($pension['reste'])->toBe(0)
            ->and(collect($recu->ventilation)->last())->toMatchArray(['libelle' => 'Avance sur la scolarité à venir', 'ce_versement' => 15000])
            ->and(array_sum(array_column($recu->ventilation, 'ce_versement')))->toBe($recu->montant);
    });

    it('impute la pension d\'un P13 en M2 sur 710170, avec les rubriques du M2', function (): void {
        $i = ($this->dossier)(13, '710170', [$this->m2])->inscriptionCourante;
        $recu = ($this->encaisser)($i, 635000);

        expect(array_column($recu->ventilation, 'code'))->toBe(['710010', '770830', '770820', '770810', '710170'])
            ->and(array_column($recu->ventilation, 'ce_versement'))->toBe([50000, 35000, 0, 50000, 500000])
            ->and(array_column($recu->ventilation, 'du'))->toBe([50000, 35000, 0, 50000, 1000000])
            ->and(array_sum(array_column($recu->ventilation, 'ce_versement')))->toBe($recu->montant);
    });

    it('somme des lignes égale au montant, avec une remise et sur plusieurs années', function (): void {
        $m1Ancien = BaremeScolarite::factory()->create(['annee_academique' => '2025-2026', 'niveau' => 'M1']);
        $a = ($this->dossier)(13, '710170', [$m1Ancien, $this->m2]);
        $ancienne = $a->inscriptions()->where('annee_academique', '2025-2026')->sole();
        $courante = $a->inscriptionCourante;
        app(RegistreVersements::class)->accorderRemise($courante, 100000, 'Bourse partielle', ($this->compte)('scolarite_responsable'));
        ($this->encaisser)($ancienne, 1085000);

        // 100 000 d'arriéré sur 2025-2026, puis le M2 (1 035 000 après remise) et 65 000 d'avance.
        $recu = ($this->encaisser)($courante->fresh(), 1200000);
        $lignes = collect($recu->ventilation);

        expect($lignes->firstWhere('libelle', 'Autres années')['ce_versement'] ?? null)->toBe(100000)
            ->and($lignes->last())->toMatchArray(['libelle' => 'Avance sur la scolarité à venir', 'ce_versement' => 65000])
            ->and($lignes->pluck('libelle')->all()[4])->toBe('Pension (frais de scolarité) (remise de 100 000 FCFA déduite)')
            ->and($lignes->sum('ce_versement'))->toBe($recu->montant)
            ->and($lignes->whereNotNull('du')->sum('du'))->toBe(1035000)
            ->and($lignes->whereNotNull('du')->sum('reste'))->toBe(0)
            // Sept lignes : chaque exemplaire tient encore dans sa demi-page.
            ->and(app(RecusVersements::class)->pdf($recu))->toContain('/Count 1')->not->toContain('/Count 2');
    });

    it('affiche « à préciser » pour une promotion sans compte, et retombe sur les paliers si la somme diffère du barème', function (): void {
        $sansCompte = ($this->auditeur)(15, null);
        expect(collect(($this->encaisser)($sansCompte, 185000)->ventilation)->last()['code'])->toBeNull();

        // Barème relevé après la saisie des rubriques : elles ne servent plus. Repli par palier, mais journalisé et signalé.
        BaremeScolarite::query()->where('annee_academique', '2026-2027')->where('niveau', 'M1')->update(['montant_tranche2' => 520000]);
        Log::spy();
        $paliers = ($this->encaisser)(($this->auditeur)(14, '710180'), 185000);
        expect(array_column($paliers->ventilation, 'libelle'))->toBe(['Droits d\'inscription', '1re tranche', '2e tranche'])
            ->and(Activity::query()->where('event', 'ventilation_repli')->sole()->properties['raison'])->toBe('les rubriques totalisent 1 185 000 FCFA, le barème 1 205 000 FCFA')
            ->and(RubriquesFrais::anomalies())->toContain('2026-2027 M1 : les rubriques totalisent 1 185 000 FCFA, le barème 1 205 000 FCFA. Les reçus de ce couple sont ventilés par palier.');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c): bool => str_contains($m, 'par palier') && $c['niveau'] === 'M1')->once();
    });

    it('fige la ventilation : changer les rubriques ensuite ne change pas un reçu émis', function (): void {
        $recu = ($this->encaisser)(($this->auditeur)(14, '710180'), 185000);
        $avant = $recu->ventilation;
        RubriqueFrais::query()->duCouple('2026-2027', 'M1')->where('ordre', 1)->firstOrFail()->update(['libelle' => 'Droits d\'inscription administrative']);

        expect($recu->fresh()->ventilation)->toEqual($avant);
    });
});

describe('écran Finances > Rubriques', function (): void {
    // Clés du répéteur prévisibles (0, 1, …) : les données de test remplacent les lignes au lieu de s'y ajouter.
    beforeEach(function (): void {
        $this->annulerRepeteur = Repeater::fake();
    });
    afterEach(function (): void {
        ($this->annulerRepeteur)();
    });

    it('se lit par l\'espace Finances, signale les anomalies, et ne se modifie que par l\'administrateur', function (): void {
        BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028']);
        $this->actingAs($this->financier);
        expect(RubriqueFraisResource::canAccess())->toBeTrue()->and(RubriqueFraisResource::canCreate())->toBeFalse()
            ->and(RubriqueFraisResource::getNavigationBadge())->toBe('1');
        $this->livewire(ListRubriquesFrais::class)->assertOk()->assertSee('Frais de bibliothèque')
            ->assertSee('2027-2028 M2 : pas de rubriques')->assertActionHidden('modifierCouple')->assertActionHidden('comptesPromotions')
            ->assertTableActionHidden('modifierCouple', RubriqueFrais::query()->firstOrFail());
        $this->get('/admin/finances/tableau-de-bord')->assertOk()->assertSee('Rubriques de frais : 1 anomalie(s)');
        expect(fn () => RubriquesFrais::enregistrerCouple('2026-2027', 'M1', RubriquesFrais::lignesDuCouple('2026-2027', 'M1'), $this->financier))
            ->toThrow(HttpException::class);

        $this->actingAs(($this->compte)('scolarite_agent'));
        expect(RubriqueFraisResource::canAccess())->toBeFalse();
    });

    it('enregistre toutes les rubriques d\'un couple d\'un coup, et refuse une somme différente du barème', function (): void {
        $this->actingAs($this->admin);
        $lignes = RubriquesFrais::lignesDuCouple('2026-2027', 'M1');
        $pension = RubriqueFrais::query()->duCouple('2026-2027', 'M1')->where('ordre', 5)->firstOrFail();

        // Somme fausse : rien n'est écrit.
        $faux = $lignes;
        $faux[4]['montant'] = 990000;
        $this->livewire(ListRubriquesFrais::class)->callTableAction('modifierCouple', $pension, data: ['lignes' => $faux])->assertNotified('Rubriques non enregistrées');
        expect($pension->fresh()->montant)->toBe(1000000);

        // Bibliothèque −10 000, pension +10 000, et pension remontée en tête : enregistré, réordonné, journalisé.
        $juste = $lignes;
        $juste[1]['montant'] = 25000;
        $juste[4]['montant'] = 1010000;
        $this->livewire(ListRubriquesFrais::class)->callTableAction('modifierCouple', $pension, data: ['lignes' => [$juste[4], ...array_slice($juste, 0, 4)]])
            ->assertHasNoTableActionErrors();
        expect(RubriqueFrais::query()->duCouple('2026-2027', 'M1')->pluck('montant')->all())->toBe([1010000, 50000, 25000, 50000, 50000])
            ->and(RubriquesFrais::etat('2026-2027', 'M1')['raison'])->toBeNull()
            ->and(Activity::query()->where('log_name', 'finances')->where('subject_id', $pension->id)->latest('id')->first()->properties['attributes']['montant'])->toBe(1010000);

        // Une ligne retirée du formulaire est désactivée, pas supprimée ; la somme des actives reste contrôlée.
        expect(fn () => RubriquesFrais::enregistrerCouple('2026-2027', 'M1', array_slice($lignes, 0, 4), $this->admin))->toThrow(InvalidArgumentException::class, 'total du barème');
        $sansTenue = $lignes;
        unset($sansTenue[2]);
        $sansTenue[4]['montant'] = 1050000;
        RubriquesFrais::enregistrerCouple('2026-2027', 'M1', $sansTenue, $this->admin);
        expect(RubriqueFrais::query()->where('libelle', 'Tenue de sport')->where('annee_academique', '2026-2027')->where('niveau', 'M1')->sole()->actif)->toBeFalse()
            ->and(RubriqueFrais::query()->where('annee_academique', '2026-2027')->where('niveau', 'M1')->count())->toBe(5)
            ->and(RubriqueFrais::query()->where('annee_academique', '2026-2027')->where('niveau', 'M1')->orderBy('ordre')->pluck('ordre')->all())->toBe([1, 2, 3, 4, 5]);

        // Nouvelle pension à la place de l'ancienne, désactivée dans le même enregistrement.
        $relance = RubriquesFrais::lignesDuCouple('2026-2027', 'M1');
        $ancienne = collect($relance)->firstWhere('compte_selon_promotion', true);
        $relance = array_map(fn (array $l): array => $l['compte_selon_promotion'] ? ['actif' => false] + $l : $l, $relance);
        $relance[] = ['id' => null, 'libelle' => 'Pension 2026-2027', 'code_imputation' => null, 'compte_selon_promotion' => true, 'montant' => $ancienne['montant'], 'actif' => true];
        RubriquesFrais::enregistrerCouple('2026-2027', 'M1', $relance, $this->admin);
        expect(RubriqueFrais::query()->duCouple('2026-2027', 'M1')->get()->last())->libelle->toBe('Pension 2026-2027')->compte_selon_promotion->toBeTrue()
            ->and(RubriqueFrais::query()->whereKey($ancienne['id'])->sole())->actif->toBeFalse()->compte_selon_promotion->toBeTrue();
    });

    it('ne signale rien et n\'échoue pas sans barème ni rubrique (pastille du menu)', function (): void {
        RubriqueFrais::query()->delete();
        BaremeScolarite::query()->delete();

        expect(RubriquesFrais::anomalies())->toBe([])->and(RubriqueFraisResource::getNavigationBadge())->toBeNull();
    });

    it('bascule une rubrique à code fixe en « compte selon la promotion » sans erreur, et le code est effacé', function (): void {
        $this->actingAs($this->admin);
        $pension = RubriqueFrais::query()->duCouple('2026-2027', 'M2')->where('ordre', 5)->firstOrFail();
        $lignes = RubriquesFrais::lignesDuCouple('2026-2027', 'M2');

        $lignes[4] = ['compte_selon_promotion' => false, 'code_imputation' => '710170'] + $lignes[4];
        $this->livewire(ListRubriquesFrais::class)->callTableAction('modifierCouple', $pension, data: ['lignes' => $lignes])->assertHasNoTableActionErrors();
        expect($pension->fresh()->code_imputation)->toBe('710170');

        // Retour à la pension selon la promotion : le code masqué n'est pas transmis, le service le force à null (relecture M1).
        $lignes[4]['compte_selon_promotion'] = true;
        $this->livewire(ListRubriquesFrais::class)->callTableAction('modifierCouple', $pension, data: ['lignes' => $lignes])->assertHasNoTableActionErrors();
        expect($pension->fresh())->compte_selon_promotion->toBeTrue()->code_imputation->toBeNull();
    });

    it('crée les rubriques d\'un couple vide depuis le modèle du plan comptable, et met à jour les comptes des promotions', function (): void {
        BaremeScolarite::factory()->m2()->create(['annee_academique' => '2027-2028']);
        $p14 = CampagneCandidature::factory()->closed()->create(['promotion_numero' => 14, 'compte_scolarite' => null]);
        $modele = RubriquesFrais::lignesDuCouple('2027-2028', 'M2');
        expect(array_column($modele, 'code_imputation'))->toBe(['710010', '770830', '770820', '770810', null])
            ->and(array_column($modele, 'montant'))->toBe([0, 0, 0, 0, 0]);
        $modele[0]['montant'] = 135000;
        $modele[4]['montant'] = 1000000;

        $this->actingAs($this->admin);
        $this->livewire(ListRubriquesFrais::class)
            ->callAction('modifierCouple', data: ['annee_academique' => '2027-2028', 'niveau' => 'M2', 'lignes' => $modele])->assertHasNoActionErrors()
            ->callAction('comptesPromotions', data: ['comptes' => [(string) $p14->id => '710180']])->assertHasNoActionErrors();

        expect(RubriqueFrais::query()->duCouple('2027-2028', 'M2')->pluck('montant')->all())->toBe([135000, 0, 0, 0, 1000000])
            ->and(RubriquesFrais::etat('2027-2028', 'M2')['raison'])->toBeNull()
            ->and($p14->fresh()->compte_scolarite)->toBe('710180')
            ->and(Activity::query()->where('event', 'compte_scolarite_modifie')->exists())->toBeTrue()
            ->and(RubriquesFrais::compteValide('710100'))->toBeFalse();
    });

    it('n\'écrit pas le compte de scolarité sans la gestion des rubriques, même depuis la fiche de la promotion', function (): void {
        $p13 = CampagneCandidature::factory()->closed()->create(['promotion_numero' => 13, 'compte_scolarite' => '710170']);
        Role::findByName('admin', 'web')->revokePermissionTo(RubriquesFrais::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->admin->fresh());

        $this->livewire(EditCampagneCandidature::class, ['record' => $p13->getRouteKey()])
            ->assertFormFieldIsDisabled('compte_scolarite')
            ->fillForm(['compte_scolarite' => '710999'])->call('save')->assertHasNoFormErrors();
        expect($p13->fresh()->compte_scolarite)->toBe('710170');
        $this->livewire(ListRubriquesFrais::class)->assertActionHidden('comptesPromotions')->assertActionHidden('modifierCouple');
    });
});
