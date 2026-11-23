<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Ecampus\ComptesEcampus;
use App\Services\ExportsRetenus\AttributionMatricules;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\CompteAuditeur;
use App\Services\Scolarite\DeblocagesAcces;
use App\Services\Scolarite\ImputationsCompte;
use App\Services\Scolarite\OuvertureAuditeur;
use App\Services\Scolarite\RecusVersements;
use App\Services\Scolarite\RegistreVersements;
use App\Services\Sms\SmsServiceInterface;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Compte de l'auditeur à travers les écrivains d'argent (spec D1, §7.4 et §14.3) :
 * registre des versements, ouverture d'inscription, accès anticipé, reçus. Barème
 * M1 2026-2027 = 185 000 + 500 000 + 500 000 ; l'inscription 2027-2028 est créée à
 * la main (D3 apportera l'outil). Auditeurs fictifs.
 */

uses()->group('scolarite');

beforeEach(function (): void {
    // Après tous les versements des scénarios (2026-2027), au fuseau de Yaoundé.
    $this->travelTo(CarbonImmutable::parse('2027-12-01 09:00', 'Africa/Douala'));
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    // Depuis le 15/11/2026, le service financier enregistre l'argent (spec encaissement-confirmation §2.1).
    $this->agent = $compte('service_financier');
    $this->scolarite = $compte('scolarite_agent');
    $this->responsable = $compte('scolarite_responsable');
    $this->financier = $compte('service_financier');
    $this->admin = $compte('admin');

    $this->campagne = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 14]);
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);
    $this->dossier = Candidature::factory()->forCampagne($this->campagne)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699004001', 'email' => 'essai-compte@example.org',
    ]);
    app(AttributionMatricules::class)->attribuer($this->campagne, '26', $this->admin);
    $this->dossier->refresh();
    $this->dossier->forceFill(['ecampus_statut' => ComptesEcampus::CREE])->save();
    $this->auditeur = $this->dossier->auditeur;
    $this->m1 = $this->auditeur->inscriptionCourante;

    $this->sms = [];
    $this->mock(SmsServiceInterface::class, function ($mock): void {
        $mock->shouldReceive('send')->andReturnUsing(function (string $tel, string $texte): void {
            $this->sms[] = $texte;
        });
    });
    $this->registre = fn (): RegistreVersements => app(RegistreVersements::class);
    $this->verser = fn (int $montant, string $date = '2026-11-12', string $payeur = 'auditeur', ?InscriptionAnnuelle $sur = null): Versement => verserConfirme(
        $sur ?? $this->m1,
        ['montant' => $montant, 'date_versement' => $date, 'mode' => 'virement', 'reference' => 'BOR-'.Str::random(8), 'payeur' => $payeur],
        $this->agent,
    )->versement;
    // L'inscription 2027-2028, créée à la main puis ouverte comme le fera D3 : R3 impute l'avance.
    $this->ouvrir = function (string $annee = '2027-2028', string $niveau = 'M2'): InscriptionAnnuelle {
        $bareme = BaremeScolarite::query()->firstOrCreate(['annee_academique' => $annee, 'niveau' => $niveau], $niveau === 'M2'
            ? ['montant_inscription' => 135000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]
            : ['montant_inscription' => 185000, 'montant_tranche1' => 500000, 'montant_tranche2' => 500000]);
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->auditeur->id, 'bareme_id' => $bareme->id]);
        app(ImputationsCompte::class)->sousVerrou($this->auditeur->id, fn (ImputationsCompte $c) => $c->imputerAvance($this->auditeur->id, 'ouverture', null));

        return $i;
    };
    $this->actives = fn (): array => ImputationVersement::query()->whereNull('annule_at')->orderBy('id')->get()
        ->map(fn (ImputationVersement $x): array => [$x->versement_id, $x->inscription_annuelle_id, $x->montant, $x->origine])->all();
    $this->compte = fn (): CompteAuditeur => CompteAuditeur::pour($this->auditeur);
});

it('E1 : solde l\'année et garde l\'excédent en avance, journalisé avec ses imputations', function (): void {
    $v = ($this->verser)(2320000);

    $imputation = ImputationVersement::query()->sole();
    $journal = Activity::query()->where('event', 'versement_enregistre')->sole();
    expect(($this->actives)())->toBe([[$v->id, $this->m1->id, 1185000, 'saisie']])
        ->and($imputation->cree_par)->toBe($this->agent->id)
        ->and($v->auditeur_id)->toBe($this->auditeur->id)
        ->and(($this->compte)()->avance())->toBe(1135000)
        ->and(($this->compte)()->avanceLibre())->toBe(1135000)
        ->and($this->m1->fresh()->situation()->verse)->toBe(1185000)
        ->and($this->m1->fresh()->situation()->tropPercu)->toBe(0)
        ->and($journal->properties['imputations'])->toBe([['inscription' => $this->m1->uuid, 'annee' => '2026-2027', 'montant' => 1185000]])
        ->and($journal->properties['avance'])->toBe(1135000);
});

it('E2 : impute l\'avance d\'elle-même à l\'ouverture de l\'année suivante, sans SMS ni reçu', function (): void {
    $v = ($this->verser)(2320000);
    $this->sms = [];
    $m2 = ($this->ouvrir)();

    $journal = Activity::query()->where('event', 'avance_imputee')->sole();
    expect(($this->actives)())->toBe([[$v->id, $this->m1->id, 1185000, 'saisie'], [$v->id, $m2->id, 1135000, 'avance']])
        ->and(ImputationVersement::query()->where('origine', 'avance')->value('cree_par'))->toBeNull()
        ->and(($this->compte)()->avance())->toBe(0)
        ->and($m2->fresh()->situation()->solde())->toBeTrue()
        ->and($journal->subject->is($this->auditeur))->toBeTrue()
        ->and($journal->properties['declencheur'])->toBe('ouverture')
        ->and($journal->properties['imputations'])->toBe([['inscription' => $m2->uuid, 'annee' => '2027-2028', 'montant' => 1135000]])
        ->and($this->sms)->toBe([])
        ->and(DB::table('recus_versements')->count())->toBe(0);
});

it('E3 : un nouveau versement règle d\'abord les arriérés, et l\'accès de l\'année reste fermé', function (): void {
    ($this->verser)(185000, '2026-11-10');
    ($this->verser)(500000, '2027-01-15');
    $m2 = ($this->ouvrir)();
    ($this->verser)(300000, '2027-11-10', sur: $m2);

    $compte = ($this->compte)();
    expect($this->m1->fresh()->situation()->verse)->toBe(985000)
        ->and($m2->fresh()->situation()->verse)->toBe(0)
        ->and($compte->arrieres())->toBe(200000)
        ->and($compte->resteParcours())->toBe(1335000)
        ->and(AccesEcampus::accesOuvert($this->dossier->fresh()))->toBeFalse();
});

it('annule un versement : ses imputations d\'abord, puis l\'avance d\'un autre versement comble le reste', function (): void {
    $v1 = ($this->verser)(185000, '2026-11-10');
    $v2 = ($this->verser)(1300000, '2026-11-12');

    ($this->registre)()->annuler($v1, 'Bordereau rejeté par la banque', $this->responsable);

    $annulee = ImputationVersement::query()->where('versement_id', $v1->id)->sole();
    $journal = Activity::query()->where('event', 'versement_annule')->sole();
    expect($annulee->annule_motif)->toBe('Versement annulé : Bordereau rejeté par la banque')
        ->and($annulee->annule_par)->toBe($this->responsable->id)
        ->and(($this->actives)())->toBe([[$v2->id, $this->m1->id, 1000000, 'saisie'], [$v2->id, $this->m1->id, 185000, 'avance']])
        ->and(($this->compte)()->avance())->toBe(115000)
        ->and($journal->properties['imputations_annulees'])->toBe([['inscription' => $this->m1->uuid, 'annee' => '2026-2027', 'montant' => 185000]])
        ->and(Activity::query()->where('event', 'avance_imputee')->sole()->properties['declencheur'])->toBe('annulation');
});

it('E8, E9 : une hausse de remise désimpute le plus récent ; la remise retirée, l\'avance revient', function (): void {
    $v1 = ($this->verser)(685000, '2026-11-10');
    $v2 = ($this->verser)(500000, '2026-12-10');

    ($this->registre)()->accorderRemise($this->m1, 200000, 'Bourse partielle', $this->responsable);

    $ancienne = ImputationVersement::query()->where('versement_id', $v2->id)->whereNotNull('annule_at')->sole();
    $part = ImputationVersement::query()->where('remplace_id', $ancienne->id)->sole();
    expect(($this->actives)())->toBe([[$v1->id, $this->m1->id, 685000, 'saisie'], [$v2->id, $this->m1->id, 300000, 'saisie']])
        ->and($ancienne->annule_motif)->toBe('Remise portée à 200 000 FCFA : Bourse partielle')
        ->and($part->cree_par)->toBe($this->agent->id)
        ->and(($this->compte)()->avanceLibre())->toBe(200000)
        ->and(Activity::query()->where('event', 'remise_accordee')->sole()->properties['desimpute'])->toBe(200000);

    ($this->registre)()->accorderRemise($this->m1->fresh(), 0, 'Bourse retirée', $this->responsable);

    expect(($this->actives)())->toBe([[$v1->id, $this->m1->id, 685000, 'saisie'], [$v2->id, $this->m1->id, 300000, 'saisie'], [$v2->id, $this->m1->id, 200000, 'avance']])
        ->and(($this->compte)()->avance())->toBe(0)
        ->and($this->m1->fresh()->situation()->solde())->toBeTrue();
});

it('E10 : une exonération totale annule l\'imputation et garde l\'argent en avance', function (): void {
    ($this->verser)(185000);

    ($this->registre)()->accorderRemise($this->m1, 1185000, 'Exonération totale', $this->responsable);

    $s = $this->m1->fresh()->situation();
    expect(($this->actives)())->toBe([])
        ->and(($this->compte)()->avanceLibre())->toBe(185000)
        ->and($s->inscriptionSoldee())->toBeTrue()
        ->and($s->verse)->toBe(0);
});

it('E5, E6 : réserve l\'excédent de l\'employeur, qui comble l\'année quand l\'auditeur est annulé', function (): void {
    $v1 = ($this->verser)(185000, '2026-11-10');
    $v2 = ($this->verser)(1185000, '2027-03-15', 'employeur');

    expect(($this->compte)()->avanceReservee())->toBe(185000)
        ->and(($this->compte)()->avanceLibre())->toBe(0);

    ($this->registre)()->annuler($v1, 'Chèque sans provision', $this->responsable);

    expect(($this->actives)())->toBe([[$v2->id, $this->m1->id, 1000000, 'saisie'], [$v2->id, $this->m1->id, 185000, 'avance']])
        ->and(($this->compte)()->avance())->toBe(0);
});

it('E7 : l\'excédent de l\'employeur n\'est jamais imputé d\'office sur une autre année', function (): void {
    ($this->verser)(185000, '2026-11-10');
    ($this->verser)(1185000, '2027-03-15', 'employeur');
    $m2 = ($this->ouvrir)();

    expect($m2->fresh()->situation()->verse)->toBe(0)
        ->and(($this->compte)()->avanceReservee())->toBe(185000);
});

it('traite le payeur « autre » comme l\'auditeur : son excédent est libre (décision du 15/11/2026)', function (): void {
    ($this->verser)(1385000, payeur: 'autre');
    $m2 = ($this->ouvrir)();

    expect($m2->fresh()->situation()->verse)->toBe(200000)
        ->and(($this->compte)()->avance())->toBe(0);
});

it('E11 : refuse en D1 l\'annulation d\'un versement imputé sur deux années, sans rien changer', function (): void {
    $v = ($this->verser)(2320000);
    ($this->ouvrir)();

    expect(fn () => ($this->registre)()->annuler($v, 'Virement rejeté', $this->responsable))
        ->toThrow(InvalidArgumentException::class, 'Ce versement est imputé sur plusieurs années (2026-2027, 2027-2028) : son annulation passera par une demande d\'autorisation (sous-lot D4). Prévenez l\'USI.')
        ->and($v->fresh()->estAnnule())->toBeFalse()
        ->and(ImputationVersement::query()->whereNull('annule_at')->count())->toBe(2)
        ->and(RegistreVersements::anneesTouchees($v))->toBe(['2026-2027', '2027-2028']);
});

it('prévoit, par le planificateur, qu\'une annulation ou une remise rebloquerait l\'accès de l\'année courante', function (): void {
    $v1 = ($this->verser)(100000, '2026-11-10');
    $v2 = ($this->verser)(100000, '2026-11-11');

    expect(RegistreVersements::annulationRebloque($v1))->toBeTrue()
        ->and(RegistreVersements::remiseRebloque($this->m1->fresh(), 0))->toBeFalse();

    // Un versement d'arriérés annulé ne touche pas l'accès de la nouvelle année.
    $m2 = ($this->ouvrir)();
    ($this->registre)()->accorderRemise($m2, 1135000, 'Exonération', $this->responsable);
    expect(RegistreVersements::annulationRebloque($v2->fresh()))->toBeFalse()
        ->and(RegistreVersements::remiseRebloque($m2->fresh(), 0))->toBeTrue();
});

it('crée l\'inscription sous le verrou de l\'auditeur et y impute l\'avance existante, sans SMS', function (): void {
    $sansBareme = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2031-2032'], 'promotion_numero' => 19]);
    $autre = Candidature::factory()->forCampagne($sansBareme)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699004002', 'email' => 'essai-ouverture@example.org',
    ]);
    app(AttributionMatricules::class)->attribuer($sansBareme, '31', $this->admin);
    $auditeur = $autre->fresh()->auditeur;
    $ancienne = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'annee_academique' => '2030-2031', 'bareme_id' => BaremeScolarite::factory()->create(['annee_academique' => '2030-2031'])->id]);
    ($this->registre)()->enregistrer($ancienne, ['montant' => 1385000, 'date_versement' => '2026-11-10', 'mode' => 'virement', 'reference' => 'BOR-OUV1', 'payeur' => 'auditeur'], $this->agent);
    BaremeScolarite::factory()->create(['annee_academique' => '2031-2032', 'niveau' => 'M1']);
    $this->sms = [];

    $nouvelle = app(OuvertureAuditeur::class)->creerInscription($auditeur->fresh(), $this->scolarite);

    expect(ImputationVersement::query()->where('inscription_annuelle_id', $nouvelle->id)->sole()->only(['montant', 'origine']))->toBe(['montant' => 200000, 'origine' => 'avance'])
        ->and(Activity::query()->where('event', 'avance_imputee')->sole()->properties['declencheur'])->toBe('ouverture')
        ->and($this->sms)->toBe([]);
});

it('impute la reprise du pointage à la création de l\'inscription', function (): void {
    $p15 = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2032-2033'], 'promotion_numero' => 20]);
    BaremeScolarite::factory()->create(['annee_academique' => '2032-2033', 'niveau' => 'M1']);
    $c = Candidature::factory()->forCampagne($p15)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699004003', 'email' => 'essai-pointage@example.org',
    ]);
    $c->forceFill(['frais_inscription_payes_at' => now(), 'frais_inscription_payes_by' => $this->agent->id])->save();

    app(AttributionMatricules::class)->attribuer($p15, '32', $this->admin);

    $versement = Versement::query()->where('mode', Versement::MODE_REPRISE)->sole();
    expect($versement->auditeur_id)->toBe($c->fresh()->auditeur->id)
        ->and(ImputationVersement::query()->where('versement_id', $versement->id)->sole()->only(['montant', 'origine', 'cree_par']))
        ->toBe(['montant' => 185000, 'origine' => 'saisie', 'cree_par' => $this->agent->id]);
});

it('accorde un accès anticipé quand le versement est allé aux arriérés : les droits de l\'année ne sont pas soldés', function (): void {
    $m2 = ($this->ouvrir)();
    ($this->verser)(185000, '2027-11-10', sur: $m2);

    $r = app(DeblocagesAcces::class)->accorder($m2->fresh(), ['type' => 'moratoire', 'motif' => 'Arriéré de M1 en cours de règlement', 'echeance' => now()->addDays(30)->toDateString()], $this->responsable);

    expect($r->deblocage->inscription_annuelle_id)->toBe($m2->id)
        ->and($m2->fresh()->situation()->inscriptionSoldee())->toBeFalse()
        ->and($this->m1->fresh()->situation()->inscriptionSoldee())->toBeTrue();
});

it('verrouille l\'auditeur en premier dans chaque écriture d\'argent, et plus jamais l\'inscription', function (): void {
    AppSettings::set(RecusVersements::CLE_DEPUIS, now()->subDay()->toIso8601String());
    $tracer = function (Closure $geste): array {
        $trace = [];
        Event::listen(TransactionBeginning::class, function () use (&$trace): void {
            $trace[] = 'BEGIN';
        });
        DB::listen(function (QueryExecuted $q) use (&$trace): void {
            $trace[] = $q->sql;
        });
        $geste();
        Event::forget(TransactionBeginning::class);
        Event::forget(QueryExecuted::class);

        return $trace;
    };
    $verrou = 'select * from "auditeurs" where "auditeurs"."id" = ? limit 1 for update';
    $premiere = function (array $trace): ?string {
        $i = array_search('BEGIN', $trace, true);

        return $i === false ? null : ($trace[$i + 1] ?? null);
    };

    $v = null;
    $gestes = [
        'versement' => function () use (&$v): void {
            $v = ($this->verser)(185000);
        },
        'reçu' => function () use (&$v): void {
            app(RecusVersements::class)->emettre($v, $this->financier);
        },
        'remise' => fn () => ($this->registre)()->accorderRemise($this->m1->fresh(), 100000, 'Bourse partielle', $this->responsable),
        'annulation' => function () use (&$v): void {
            ($this->registre)()->annuler($v->fresh(), 'Doublon de saisie', $this->responsable);
        },
        'accès anticipé' => fn () => app(DeblocagesAcces::class)->accorder($this->m1->fresh(), ['type' => 'moratoire', 'motif' => 'Moratoire accordé par le responsable', 'echeance' => now()->addDays(30)->toDateString()], $this->responsable),
        'régularisation' => fn () => app(DeblocagesAcces::class)->regulariser($this->m1->fresh()),
    ];
    foreach ($gestes as $nom => $geste) {
        $trace = $tracer($geste);
        $inscription = array_keys(preg_grep('/from "inscriptions_annuelles".*for update/', $trace));
        expect($premiere($trace))->toBe($verrou, $nom);
        if ($nom === 'accès anticipé') {
            // L'accès anticipé garde le verrou de l'inscription, pris après celui de l'auditeur (ordre R8).
            expect($inscription)->toHaveCount(1)->and($inscription[0])->toBeGreaterThan(array_search($verrou, $trace, true));
        } else {
            // Le verrou de l'inscription a disparu du registre et des reçus.
            expect($inscription)->toBe([], $nom);
        }
    }
});

it('n\'enregistre rien et prévient l\'USI quand la base refuse le compte, sans journaliser le détail de la ligne', function (): void {
    // Un défaut du code simulé : la base refuse toute imputation, avec un détail qui pourrait contenir un nom.
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION refus_de_test() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'Compte incohérent (test)' USING ERRCODE = 'check_violation', DETAIL = 'Failing row contains (ESSAI Nomfictif)'; END $$;
        CREATE TRIGGER refus_de_test BEFORE INSERT ON imputations_versements FOR EACH ROW EXECUTE FUNCTION refus_de_test();
        SQL);
    Log::shouldReceive('channel')->with('single')->andReturnSelf();
    Log::shouldReceive('error')->once()->withArgs(fn (string $message, array $contexte): bool => str_contains($message, 'Compte de l\'auditeur')
        && $contexte['auditeur'] === $this->auditeur->uuid && $contexte['sqlstate'] === '23514'
        && str_contains($contexte['erreur'], 'Compte incohérent (test)') && ! str_contains($contexte['erreur'], 'Nomfictif'));

    expect(fn () => ($this->verser)(185000))
        ->toThrow(InvalidArgumentException::class, 'Le compte de l\'auditeur n\'a pas pu être mis à jour : rien n\'a été enregistré. Prévenez l\'USI.')
        ->and(Versement::query()->count())->toBe(0);
});

it('traduit le refus de la base pour toute écriture sous le verrou, la création d\'inscription comprise', function (): void {
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION refus_de_test() RETURNS trigger LANGUAGE plpgsql AS $$
        BEGIN RAISE EXCEPTION 'Compte incohérent (test)' USING ERRCODE = 'check_violation'; END $$;
        CREATE TRIGGER refus_de_test BEFORE INSERT ON imputations_versements FOR EACH ROW EXECUTE FUNCTION refus_de_test();
        SQL);
    Log::shouldReceive('channel')->with('single')->andReturnSelf();
    Log::shouldReceive('error')->once();
    $p20 = CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2033-2034'], 'promotion_numero' => 21]);
    $c = Candidature::factory()->forCampagne($p20)->submitted()->create([
        'statut' => Candidature::STATUT_ACCEPTE, 'phone_e164' => '+237699004009', 'email' => 'essai-refus@example.org',
    ]);
    $c->forceFill(['matricule' => '33TST0001', 'frais_inscription_payes_at' => now(), 'frais_inscription_payes_by' => $this->agent->id])->save();
    $auditeur = Auditeur::query()->create(['candidature_id' => $c->id]);
    BaremeScolarite::factory()->create(['annee_academique' => '2033-2034', 'niveau' => 'M1']);

    expect(fn () => app(OuvertureAuditeur::class)->creerInscription($auditeur, $this->scolarite))
        ->toThrow(InvalidArgumentException::class, 'Le compte de l\'auditeur n\'a pas pu être mis à jour')
        ->and(InscriptionAnnuelle::query()->where('auditeur_id', $auditeur->id)->count())->toBe(0);
});

it('refuse un montant au-delà de 100 000 000 FCFA, comme le formulaire', function (): void {
    expect(fn () => ($this->verser)(100000001))->toThrow(InvalidArgumentException::class, '100 000 000')
        ->and(Versement::query()->count())->toBe(0);
});

it('lit l\'avance d\'une liste en SQL comme le compte en PHP', function (): void {
    ($this->verser)(1385000, '2026-11-10');
    ($this->verser)(300000, '2026-11-11', 'employeur');

    expect((int) Auditeur::query()->avecAvance()->whereKey($this->auditeur->id)->value('avance'))->toBe(($this->compte)()->avance())
        ->and(($this->compte)()->avance())->toBe(500000)
        ->and(($this->compte)()->avanceReservee())->toBe(300000)
        ->and(Auditeur::query()->avecAvance()->find($this->auditeur->id)->candidature_id)->toBe($this->dossier->id);
});
