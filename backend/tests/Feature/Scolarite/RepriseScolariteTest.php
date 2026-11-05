<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\DocumentsAuditeurs\FraisInscription;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\NotificationAcces;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Scolarite\RepriseScolarite;
use App\Services\Sms\SmsServiceInterface;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->admin = tap(User::factory()->create(['email' => 'adm-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->campagne = CampagneCandidature::factory()->create([
        'nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14,
        'parametres_lettres' => ['frais_inscription_montant' => 185000, 'frais_tranche1_date' => '2026-12-15', 'frais_inscription_echeance' => 'Avant la rentrée'],
    ]);
    $retenu = fn (string $nom, string $tel): Candidature => Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'nom' => $nom, 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
    ]);
    $this->coche = $retenu('Abega', '+237699000001');
    $this->nonCoche = $retenu('Biya', '+237699000002');
    $this->retire = $retenu('Chantal', '+237699000003');
    $this->sansMatricule = $retenu('Dongmo', '+237699000004');

    // État d'avant le registre : matricules sans auditeur, cases cochées.
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin, [$this->coche->id, $this->nonCoche->id, $this->retire->id]);
    InscriptionAnnuelle::query()->delete();
    Auditeur::query()->delete();
    BaremeScolarite::query()->delete();
    foreach ([$this->coche, $this->retire, $this->sansMatricule] as $c) {
        $c->forceFill(['frais_inscription_payes_at' => '2026-11-03 10:00:00', 'frais_inscription_payes_by' => $this->admin->id])->save();
    }
    $this->retire->forceFill(['withdrawn_at' => now()])->save();
    Candidature::query()->whereKey([$this->coche->id, $this->nonCoche->id])->update(['ecampus_statut' => ComptesEcampus::CREE]);

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel): void {
            $this->sms[] = $tel;
        });
    });
});

it('pré-remplit les barèmes de l\'année depuis la campagne, M2 à 135 000', function (): void {
    app(RepriseScolarite::class)->executer();

    $m1 = BaremeScolarite::query()->where('niveau', 'M1')->sole();
    $m2 = BaremeScolarite::query()->where('niveau', 'M2')->sole();
    expect($m1->annee_academique)->toBe('2026-2027')
        ->and($m1->montant_inscription)->toBe(185000)
        ->and($m1->montant_tranche1)->toBe(500000)
        ->and($m1->tranche1_echeance?->toDateString())->toBe('2026-12-15')
        ->and($m1->inscription_echeance)->toBe('Avant la rentrée')
        ->and($m2->montant_inscription)->toBe(135000)
        ->and($m2->tranche1_echeance)->toBeNull();
});

it('garde le code d\'accès aux mêmes personnes qu\'avant', function (): void {
    $avant = Candidature::query()->whereNotNull('matricule')->get()
        ->mapWithKeys(fn (Candidature $c): array => [$c->id => $c->frais_inscription_payes_at !== null && $c->withdrawn_at === null])->all();

    app(RepriseScolarite::class)->executer();

    $apres = Candidature::query()->whereNotNull('matricule')->get()
        ->mapWithKeys(fn (Candidature $c): array => [$c->id => FraisInscription::payes($c) && FraisInscription::concerne($c)])->all();
    expect($apres)->toBe($avant);
});

it('convertit chaque case cochée en versement de reprise, retirés compris', function (): void {
    $bilan = app(RepriseScolarite::class)->executer();

    $reprises = Versement::query()->where('mode', Versement::MODE_REPRISE)->orderBy('reference')->get();
    expect($bilan)->toBe(['baremes' => 2, 'auditeurs' => 3, 'reprises' => 2])
        ->and($reprises)->toHaveCount(2)
        ->and($reprises->first()->reference)->toBe('REPRISE-'.$this->coche->fresh()->matricule)
        ->and($reprises->first()->montant)->toBe(185000)
        ->and($reprises->first()->date_versement->toDateString())->toBe('2026-11-03')
        ->and($reprises->first()->saisi_par)->toBe($this->admin->id)
        ->and($this->retire->fresh()->auditeur)->not->toBeNull();
});

it('est idempotente', function (): void {
    app(RepriseScolarite::class)->executer();
    $second = app(RepriseScolarite::class)->executer();

    expect($second)->toBe(['baremes' => 0, 'auditeurs' => 0, 'reprises' => 0])
        ->and(Versement::query()->count())->toBe(2)
        ->and(BaremeScolarite::query()->count())->toBe(2);
});

it('n\'envoie aucun SMS, ni à la reprise ni au versement suivant', function (): void {
    app(RepriseScolarite::class)->executer();
    $agent = tap(User::factory()->create(['email' => 'ag-'.Str::random(5).'@pssfp.local']), fn (User $u) => $u->assignRole('scolarite_agent'));

    app(RegistreVersements::class)->enregistrer($this->coche->fresh()->auditeur->inscriptionCourante, [
        'montant' => 100000, 'date_versement' => '2026-11-04', 'mode' => 'virement', 'reference' => 'BOR-77', 'payeur' => 'auditeur',
    ], $agent);

    expect($this->sms)->toBeEmpty();
});

it('reprend la case d\'un dossier sans matricule à l\'attribution de son matricule', function (): void {
    app(RepriseScolarite::class)->executer();
    expect(Versement::query()->count())->toBe(2);

    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin, [$this->sansMatricule->id]);

    $d = $this->sansMatricule->fresh();
    expect(FraisInscription::payes($d))->toBeTrue()
        ->and(Versement::query()->where('reference', 'REPRISE-'.$d->matricule)->exists())->toBeTrue();
});

it('laisse prévenir à la création du compte un auditeur soldé dont le compte eCampus manque', function (): void {
    Candidature::query()->whereKey($this->coche->id)->update(['ecampus_statut' => ComptesEcampus::ECHEC]);
    app(RepriseScolarite::class)->executer();

    expect($this->coche->fresh()->auditeur->inscriptionCourante->acces_notifie_at)->toBeNull();

    $this->coche->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
    expect(app(NotificationAcces::class)->siDisponible($this->coche->fresh()))->toBeTrue()
        ->and($this->sms)->toBe([$this->coche->phone_e164]);
});

it('refuse les montants incohérents en base, même hors du registre', function (): void {
    app(RepriseScolarite::class)->executer();
    $i = $this->coche->fresh()->auditeur->inscriptionCourante;

    expect(fn () => $i->forceFill(['remise_montant' => -1])->save())->toThrow(QueryException::class)
        ->and(fn () => $i->fresh()->forceFill(['remise_montant' => 2000000])->save())->toThrow(QueryException::class)
        ->and(fn () => Versement::factory()->create(['inscription_annuelle_id' => $i->id, 'montant' => 0]))->toThrow(QueryException::class);
});
