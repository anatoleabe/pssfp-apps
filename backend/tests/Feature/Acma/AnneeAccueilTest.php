<?php

declare(strict_types=1);

use App\Http\Resources\CandidatureResource;
use App\Models\AcmaDossier;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Parcours;
use App\Models\User;
use App\Services\Acma\ReinscriptionAncien;
use App\Services\Acma\Reprise\RepriseAcma;
use App\Services\DocumentsAuditeurs\LettreAccesFoadPdf;
use App\Services\DocumentsAuditeurs\LettresAdmissionSignees;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\Scolarite\PromotionAccueil;
use App\Services\Scolarite\PromotionsAnterieures;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Acma\TransitAcma;

uses()->group('acma', 'scolarite', 'ecampus');

/* Lot 15l (spec de la réinscription des anciens, §9, §10) : promotion d'accueil, cohorte eCampus, lettre d'accès, espace. */

/** Faux eCampus : comptes présents ; chaque appel est noté dans $test->appels. */
function eCampusAccueil(object $test, array $comptes = []): void
{
    $test->appels = [];
    Http::fake(['ecampus.test/*' => function (Request $requete) use ($test, &$comptes) {
        $d = $requete->data();
        $test->appels[] = $d;

        return match ($d['wsfunction'] ?? '') {
            'core_user_get_users_by_field' => Http::response(array_values(array_filter($comptes, fn (array $c): bool => in_array($c[$d['field']] ?? null, $d['values'], true)))),
            'core_user_create_users' => (function () use (&$comptes, $d) {
                $comptes[] = ['id' => 901, 'username' => $d['users'][0]['username'], 'email' => $d['users'][0]['email'], 'idnumber' => $d['users'][0]['idnumber']];

                return Http::response([['id' => 901, 'username' => $d['users'][0]['username']]]);
            })(),
            'core_cohort_add_cohort_members' => Http::response(['warnings' => []]),
            default => Http::response(['exception' => 'moodle_exception', 'errorcode' => 'inconnu', 'message' => 'x']),
        };
    }]);
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('scolarite.annee_en_cours', '2026-2027');
    config()->set('pssfp.foad_url', 'https://ecampus.test');
    config()->set('pssfp.ecampus.jeton', 'jeton-de-test');
    Storage::fake('local');
    Notification::fake();
    Mail::fake();
    $this->mock(SmsServiceInterface::class, fn ($m) => $m->shouldNotReceive('send'));
    $this->admin = tap(User::factory()->create(['email' => 'admin-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole('admin'));
    $this->p14 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14, 'slug' => 'p14-2026', 'prefix_numero' => 'P14026-',
        'parametres_lettres' => ['reference_acces' => 'N° {numero}/{annee}/ACCES', 'date_lettres' => '2026-11-04']]);
    $this->p13 = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique 2025-2026'], 'promotion_numero' => 13, 'slug' => 'p13-2025', 'prefix_numero' => 'P13025-']);
    $this->p12 = app(PromotionsAnterieures::class)->creer(12, '2024-2025', 'Rattachement d\'anciens de la P12', $this->admin);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027']);
    BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    Parcours::query()->create(['code' => 'TC', 'libelle' => 'Tronc commun', 'niveau' => 'M1', 'actif' => true, 'ordre' => 1]);
    Parcours::query()->create(['code' => 'ACFP', 'libelle' => 'Audit et contrôle', 'niveau' => 'M2', 'specialite' => 'Audit et contrôle des finances publiques', 'actif' => true, 'ordre' => 2]);
    TransitAcma::construire();
    app(RepriseAcma::class)->executer($this->admin, null, true);
    $this->reinscrire = fn (string $niveau, array $autres = []): Candidature => app(ReinscriptionAncien::class)->reinscrire(
        AcmaDossier::query()->where('matricule', '90EFP001')->firstOrFail(),
        array_merge(['nom' => 'ESSAI', 'prenom' => 'Alpha Béta', 'date_naissance' => '1985-03-12', 'genre' => 'M', 'nationalite' => 'CM',
            'phone_e164' => '+237699000001', 'email' => 'essai.alpha@exemple.invalid', 'campagne_id' => $this->p12->id, 'niveau' => $niveau,
            'parcours' => $niveau === 'M2' ? 'ACFP' : 'TC', 'motif' => 'Demande écrite de reprise des études', 'confirmer_m1_admis' => true], $autres),
        $this->admin,
    );
});

it('crée le compte eCampus d\'un réinscrit de la P12 en M1 dans la cohorte de la P14, sa promotion d\'accueil', function (): void {
    eCampusAccueil($this);
    $c = ($this->reinscrire)('M1');

    expect(PromotionAccueil::pour($c)?->is($this->p14))->toBeTrue()
        ->and(PromotionAccueil::cohorte($c))->toBe('PROMO14_TC_2026_2027')
        ->and(app(ReinscriptionAncien::class)->ouvrirEcampus($c, $this->admin))->toBe(ComptesEcampus::CREE);
    $cohorte = collect($this->appels)->firstWhere('wsfunction', 'core_cohort_add_cohort_members');
    expect(json_encode($cohorte))->toContain('PROMO14_TC_2026_2027')->not->toContain('PROMO12');
    Notification::assertNothingSent();
});

it('crée le compte d\'un réinscrit en M2 hors de sa promotion sans cohorte, signalée à poser', function (): void {
    eCampusAccueil($this);
    $c = ($this->reinscrire)('M2');

    expect(PromotionAccueil::pour($c)?->is($this->p13))->toBeTrue()
        ->and(PromotionAccueil::cohorte($c))->toBeNull()
        ->and(app(ReinscriptionAncien::class)->ouvrirEcampus($c, $this->admin))->toBe(ComptesEcampus::COHORTE_A_FINIR)
        ->and(collect($this->appels)->pluck('wsfunction')->all())->not->toContain('core_cohort_add_cohort_members')
        ->and($c->fresh()->ecampus_detail)->toContain('cohorte d\'option M2');
});

it('garde un compte eCampus existant et efface le mot de passe généré, qui n\'est pas le sien', function (): void {
    eCampusAccueil($this, [['id' => 5, 'username' => '90efp001', 'email' => 'ancien@exemple.invalid', 'idnumber' => '', 'lastname' => 'ESSAI', 'firstname' => 'Alpha']]);
    $c = ($this->reinscrire)('M1');

    expect(app(ReinscriptionAncien::class)->ouvrirEcampus($c, $this->admin))->toBe(ComptesEcampus::EXISTANT)
        ->and($c->fresh()->moodle_password)->toBeNull()
        ->and(collect($this->appels)->pluck('wsfunction')->all())->not->toContain('core_user_create_users');
});

it('édite la lettre d\'accès d\'un réinscrit avec les paramètres de sa promotion d\'accueil', function (): void {
    $c = ($this->reinscrire)('M1');

    expect(PromotionAccueil::lettreAcces($c)?->is($this->p14))->toBeTrue()
        ->and(PromotionAccueil::lettreAcces(Candidature::factory()->forCampagne($this->p13)->create())?->is($this->p13))->toBeTrue()
        ->and(app(LettreAccesFoadPdf::class)->html($c->fresh()))->toContain('90efp001');
});

it('accueille le réinscrit sans récépissé, sans lettre d\'admission ni communiqué, et ne change rien pour un retenu ordinaire', function (): void {
    $c = ($this->reinscrire)('M1');
    $ordinaire = Candidature::factory()->forCampagne($this->p14)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);
    $ordinaire->forceFill(['matricule' => '26EFP777'])->save();

    expect($c->fresh()->estReinscritAcma())->toBeTrue()
        ->and($c->fresh()->peutAvoirRecepisse())->toBeFalse()
        ->and(LettresAdmissionSignees::depotPossible($c->fresh()))->toBeFalse()
        ->and(CandidatureResource::make($c->fresh())->resolve()['reinscrit_acma'])->toBeTrue();
    expect($ordinaire->estReinscritAcma())->toBeFalse()
        ->and($ordinaire->peutAvoirRecepisse())->toBeTrue()
        ->and(LettresAdmissionSignees::depotPossible($ordinaire))->toBeTrue()
        ->and(PromotionAccueil::cohorte($ordinaire))->toBe('PROMO14_TC_2026_2027');
});
