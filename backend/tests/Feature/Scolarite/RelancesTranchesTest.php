<?php

declare(strict_types=1);

use App\Filament\Pages\Parametres;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\CandidatureRelance;
use App\Models\User;
use App\Models\Versement;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AnneeEnCours;
use App\Services\Scolarite\RelancesTranches;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

uses()->group('scolarite');

/*
 * Lot 23 : relance automatique des tranches. Barème 2026-2027 : inscription 185 000, tranches de 500 000 ;
 * la première tranche est échue depuis le 15/10/2026, la seconde ne l'est pas.
 */
beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->travelTo('2026-11-23 09:00:00');
    AppSettings::set(AnneeEnCours::REGLAGE, '2026-2027');
    $this->admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'montant_inscription' => 185000,
        'montant_tranche1' => 500000, 'montant_tranche2' => 500000, 'tranche1_echeance' => '2026-10-15', 'tranche2_echeance' => '2027-02-15']);
    $retenu = fn (string $prenom, string $tel): Candidature => Candidature::factory()->forCampagne($campagne)->submitted()->create([
        'prenom' => $prenom, 'nom' => 'Essai', 'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => $tel, 'email' => Str::random(8).'@example.org',
    ]);
    $this->retard = $retenu('Awa', '+237699004001');
    $this->aJour = $retenu('Bruno', '+237699004002');
    $this->suspendu = $retenu('Carine', '+237699004003');
    app(AttributionMatricules::class)->attribuer($campagne, '26', $this->admin);
    $verser = fn (Candidature $c, int $montant) => Versement::factory()->create(['inscription_annuelle_id' => $c->refresh()->auditeur->inscriptionCourante->id, 'montant' => $montant]);
    $verser($this->retard, 285000);
    $verser($this->aJour, 685000);
    $verser($this->suspendu, 185000);
    $this->suspendu->auditeur->forceFill(['statut' => Auditeur::STATUT_SUSPENDU])->save();

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[$tel] = $texte;
        });
    });
    Mail::fake();
    $this->relances = app(RelancesTranches::class);
});

it('n\'envoie rien tant que la relance est éteinte', function (): void {
    $bilan = $this->relances->relancer();

    expect($bilan['actif'])->toBeFalse()
        ->and($this->sms)->toBe([])
        ->and(CandidatureRelance::query()->count())->toBe(0);
});

it('relance l\'auditeur dont une tranche est échue, avec le montant échu, et personne d\'autre', function (): void {
    RelancesTranches::regler(true, 7, $this->admin);

    $bilan = $this->relances->relancer();

    expect($bilan['relances'])->toBe(1)
        ->and(array_keys($this->sms))->toBe(['+237699004001'])
        ->and($this->sms['+237699004001'])->toContain('Awa')->toContain('400 000 FCFA')
        ->and(CandidatureRelance::query()->where('cause', CandidatureRelance::CAUSE_TRANCHE)->pluck('candidature_id')->unique()->all())->toBe([$this->retard->id])
        ->and(Activity::query()->where('event', RelancesTranches::EVENEMENT)->sole()->properties['montant_echu'])->toBe(400000);
});

it('attend l\'intervalle avant une seconde relance', function (): void {
    RelancesTranches::regler(true, 7, $this->admin);
    $this->relances->relancer();

    $this->travelTo('2026-11-27 09:00:00');
    expect($this->relances->relancer()['relances'])->toBe(0);

    $this->travelTo('2026-11-30 09:00:00');
    expect($this->relances->relancer()['relances'])->toBe(1);
});

it('s\'allume depuis Paramètres, refuse un intervalle hors bornes, et journalise le réglage', function (): void {
    $this->actingAs($this->admin);

    livewire(Parametres::class)->fillForm(['relances_tranches_actif' => true, 'relances_tranches_intervalle' => 10])->call('save')->assertHasNoFormErrors();

    expect(RelancesTranches::reglages())->toBe(['actif' => true, 'intervalle_jours' => 10])
        ->and(Activity::query()->where('event', 'relances_tranches_reglees')->sole()->properties['apres']['actif'])->toBeTrue()
        ->and(fn () => RelancesTranches::regler(true, 1, $this->admin))->toThrow(InvalidArgumentException::class);
});
