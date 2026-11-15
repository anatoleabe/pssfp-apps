<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\Candidature;
use App\Models\DeblocageAcces;
use App\Models\ImputationVersement;
use App\Models\InscriptionAnnuelle;
use App\Models\User;
use App\Models\Versement;
use App\Services\Scolarite\AccesEcampus;
use App\Services\Scolarite\CompteAuditeur;
use App\Services\Scolarite\Palier;
use App\Services\Scolarite\RepriseComptes;
use App\Services\Scolarite\SituationFinanciere;
use App\Services\Scolarite\VerificationComptes;
use App\Services\Sms\SmsServiceInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Reprise des versements existants (spec D1, §10 et §14.5). Les versements sont créés tels
 * qu'avant D1, sans imputation. Barème M1 2026-2027 : 185 000 + 500 000 + 500 000 ; M2 :
 * 135 000 + 500 000 + 500 000. Auditeurs fictifs.
 */

uses()->group('scolarite');

const MIGRATION_REPRISE = '2026_12_01_100100_reprise_comptes_auditeurs.php';

beforeEach(function (): void {
    $this->mock(SmsServiceInterface::class)->shouldNotReceive('send');
    Mail::fake();
    $this->m1 = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2020-01-01']);
    $this->m2 = BaremeScolarite::factory()->m2()->create(['annee_academique' => '2026-2027']);
    $this->ancien = BaremeScolarite::factory()->create(['annee_academique' => '2025-2026', 'niveau' => 'M1']);

    /**
     * Un auditeur et son inscription, avec des versements d'avant D1 : [montant, date, payeur, annulé].
     */
    $this->cas = function (array $versements, int $remise = 0, ?BaremeScolarite $bareme = null, array $options = []): InscriptionAnnuelle {
        $auditeur = $options['auditeur'] ?? Auditeur::factory()->campagneCommune()->create();
        $i = InscriptionAnnuelle::factory()->create(['auditeur_id' => $auditeur->id, 'bareme_id' => ($bareme ?? $this->m1)->id]);
        $i->forceFill(['remise_montant' => $remise])->save();
        foreach ($versements as $n => [$montant, $date, $payeur, $annule]) {
            Versement::factory()->sansImputation()->create([
                'inscription_annuelle_id' => $i->id, 'montant' => $montant, 'date_versement' => $date, 'payeur' => $payeur,
                'mode' => $options['mode'] ?? 'virement', 'reference' => 'BOR-'.Str::random(10),
                'annule_at' => $annule ? now() : null, 'annule_motif' => $annule ? 'Erreur de saisie' : null,
            ]);
        }

        return $i;
    };

    $this->soldee = ($this->cas)([[185000, '2026-11-02', 'auditeur', false], [1000000, '2026-11-20', 'auditeur', false]]);
    $this->tropPercu = ($this->cas)([[1300000, '2026-11-05', 'auditeur', false]]);
    $this->remise = ($this->cas)([[685000, '2026-11-05', 'auditeur', false], [100000, '2026-11-06', 'auditeur', false]], 500000);
    $this->exoneree = ($this->cas)([[185000, '2026-11-05', 'auditeur', false]], 1185000);
    $this->annules = ($this->cas)([[185000, '2026-11-05', 'auditeur', false], [500000, '2026-11-06', 'auditeur', true]]);
    $this->pointage = ($this->cas)([[185000, '2026-10-01', 'auditeur', false]], options: ['mode' => Versement::MODE_REPRISE]);
    $this->retire = ($this->cas)([[185000, '2026-11-05', 'auditeur', false]]);
    $this->retire->auditeur->candidature->forceFill(['withdrawn_at' => now()])->save();
    $this->debloque = ($this->cas)([[100000, '2026-11-05', 'auditeur', false]]);
    DeblocageAcces::factory()->create(['inscription_annuelle_id' => $this->debloque->id]);
    $this->employeur = ($this->cas)([[185000, '2026-11-05', 'auditeur', false], [1185000, '2027-03-15', 'employeur', false]]);
    $this->m2Admis = ($this->cas)([[135000, '2026-11-05', 'auditeur', false], [1100000, '2026-11-06', 'autre', false]], bareme: $this->m2);
    $this->sansVersement = ($this->cas)([]);
    // Un auditeur à deux inscriptions (inconnu en production) : l'excédent de 2026-2027 va sur l'arriéré par R3.
    $this->arriere = ($this->cas)([[1085000, '2025-11-05', 'auditeur', false]], bareme: $this->ancien);
    $this->courante = ($this->cas)([[1385000, '2026-11-05', 'auditeur', false]], options: ['auditeur' => $this->arriere->auditeur]);

    $this->inscriptions = fn () => InscriptionAnnuelle::query()->with(['bareme', 'auditeur.candidature'])->orderBy('id')->get();
    // Situation et accès « d'avant D1 » : le cumul est la somme des versements valides de l'inscription.
    $this->ancienneSituation = fn (InscriptionAnnuelle $i): SituationFinanciere => SituationFinanciere::calculer($i->montant_inscription, $i->montant_tranche1,
        $i->montant_tranche2, (int) $i->remise_montant, (int) $i->versementsValides()->sum('montant'), InscriptionAnnuelle::aujourdhui(),
        $i->bareme?->tranche1_echeance, $i->bareme?->tranche2_echeance);
    $this->acces = function (InscriptionAnnuelle $i, bool $ancien): string {
        $c = Candidature::query()->with('auditeur.inscriptionCourante.bareme')->findOrFail($i->auditeur->candidature_id);
        $courante = $c->auditeur->inscriptionCourante;
        if ($ancien) {
            $courante->setAttribute('imputations_actives_sum_montant', (int) $courante->versementsValides()->sum('montant'));
            // Avant la confirmation par la scolarité (15/11/2026), tout l'argent comptait.
            $courante->setAttribute('imputations_confirmees_sum_montant', (int) $courante->versementsValides()->sum('montant'));
        }

        return AccesEcampus::etat($c);
    };
    $this->empreinte = fn (SituationFinanciere $s): array => [
        array_map(fn (Palier $p): array => [$p->du, $p->paye, $p->reste, $p->enRetard], $s->paliers),
        $s->duTotal, $s->reste, $s->inscriptionSoldee(), $s->solde(), $s->enRetard(),
    ];
});

it('reprend chaque versement sur son inscription sans changer ni la situation ni l\'accès de qui n\'a pas de trop-perçu', function (): void {
    $avant = ($this->inscriptions)()->mapWithKeys(fn (InscriptionAnnuelle $i): array => [$i->id => [
        'situation' => ($this->empreinte)(($this->ancienneSituation)($i)),
        'trop_percu' => ($this->ancienneSituation)($i)->tropPercu,
        'acces' => ($this->acces)($i, true),
    ]]);

    $bilan = app(RepriseComptes::class)->executer();

    foreach (($this->inscriptions)() as $i) {
        $nouvelle = $i->situation();
        $hors = $i->auditeur_id === $this->arriere->auditeur_id;
        if (! $hors) {
            expect(($this->empreinte)($nouvelle))->toBe($avant[$i->id]['situation'], 'inscription '.$i->id)
                ->and($nouvelle->tropPercu)->toBe(0);
        }
        expect(($this->acces)($i, false))->toBe($avant[$i->id]['acces'], 'accès de l\'inscription '.$i->id);
    }
    $avances = fn (InscriptionAnnuelle $i): int => CompteAuditeur::pour($i->auditeur_id)->avance();
    expect($avances($this->tropPercu))->toBe(115000)
        ->and($avances($this->remise))->toBe(100000)
        ->and($avances($this->exoneree))->toBe(185000)
        ->and(CompteAuditeur::pour($this->employeur->auditeur_id)->avanceReservee())->toBe(185000)
        ->and($avances($this->m2Admis))->toBe(100000)
        ->and($avances($this->soldee))->toBe(0)
        ->and($avances($this->annules))->toBe(0)
        ->and($avances($this->debloque))->toBe(0)
        ->and(ImputationVersement::query()->whereHas('versement', fn ($q) => $q->whereNotNull('annule_at'))->count())->toBe(0)
        // L'avance de l'auditeur à deux années comble son arriéré (R3, origine « avance »).
        ->and($this->arriere->fresh()->situation()->solde())->toBeTrue()
        ->and($avances($this->arriere))->toBe(100000)
        ->and($bilan)->toMatchArray(['auditeurs_traites' => 11, 'auditeurs_ignores' => 0, 'imputations_avance' => 1,
            'avance_totale' => 115000 + 100000 + 185000 + 185000 + 100000 + 100000, 'avance_reservee' => 185000, 'auditeurs_avec_avance' => 6])
        ->and(ImputationVersement::query()->where('origine', 'reprise')->whereNotNull('cree_par')->count())->toBe(0);
});

it('ne trouve aucun écart à la vérification, et compte à part l\'auditeur dont l\'argent a changé d\'année', function (): void {
    app(RepriseComptes::class)->executer();

    $verification = app(VerificationComptes::class);
    $equivalence = $verification->equivalence();
    expect($verification->ecarts())->toBe([])
        ->and($equivalence['ecarts'])->toBe([])
        ->and($equivalence['exclus'])->toBe(1)
        ->and($verification->totaux())->toMatchArray(['avance' => 785000, 'avance_reservee' => 185000]);
});

it('est idempotente : une seconde exécution n\'écrit rien', function (): void {
    app(RepriseComptes::class)->executer();
    $lignes = ImputationVersement::query()->orderBy('id')->get()->toArray();

    $bilan = app(RepriseComptes::class)->executer();

    expect(ImputationVersement::query()->orderBy('id')->get()->toArray())->toBe($lignes)
        ->and($bilan['auditeurs_traites'])->toBe(0)
        ->and($bilan['auditeurs_ignores'])->toBe(11)
        ->and(Activity::query()->where('event', 'compte_repris')->count())->toBe(6);
});

it('n\'envoie rien, n\'émet aucun reçu et laisse intacts les reçus déjà émis', function (): void {
    $versement = Versement::query()->where('inscription_annuelle_id', $this->tropPercu->id)->sole();
    // Reçu émis avant D1 : trop-perçu compris dans son total, libellé « avance ».
    $recu = [
        'uuid' => (string) Str::uuid(), 'numero' => 'RCPT-2026-000001', 'annee_numero' => 2026, 'sequence' => 1,
        'versement_id' => $versement->id, 'inscription_annuelle_id' => $this->tropPercu->id, 'montant' => 1300000,
        'objet' => 'Frais de scolarité 2026-2027 : droits d\'inscription (185 000 FCFA), 1re tranche (500 000 FCFA), 2e tranche (500 000 FCFA), avance (115 000 FCFA)',
        'auditeur_nom' => 'ESSAI Reprise', 'matricule' => '26TST0001', 'annee_academique' => '2026-2027', 'niveau' => 'M1',
        'mode' => 'virement', 'reference' => $versement->reference, 'date_versement' => '2026-11-05', 'payeur' => 'auditeur',
        'total_verse' => 1300000, 'reste_a_payer' => 0, 'emis_par' => User::factory()->create()->id, 'emetteur_nom' => 'Agent financier',
        'emis_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::table('recus_versements')->insert($recu);
    $avant = (array) DB::table('recus_versements')->sole();

    app(RepriseComptes::class)->executer();

    expect((array) DB::table('recus_versements')->sole())->toBe($avant)
        ->and(DB::table('recus_versements')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'recu_emis')->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('journalise la reprise d\'un compte seulement s\'il garde une avance', function (): void {
    app(RepriseComptes::class)->executer();

    $journaux = Activity::query()->where('event', 'compte_repris')->get();
    $tropPercu = $journaux->firstWhere('subject_id', $this->tropPercu->auditeur_id);
    expect($journaux)->toHaveCount(6)
        ->and($tropPercu->properties['avance'])->toBe(115000)
        ->and($tropPercu->properties['impute'])->toBe(1185000)
        ->and($tropPercu->properties['imputations'])->toBe([['inscription' => $this->tropPercu->uuid, 'annee' => '2026-2027', 'montant' => 1185000]])
        ->and($journaux->firstWhere('subject_id', $this->soldee->auditeur_id))->toBeNull();
});

it('passe par la migration, qui vérifie, journalise son bilan et se rejoue sans effet', function (): void {
    Log::spy();
    $migration = require database_path('migrations/'.MIGRATION_REPRISE);

    $migration->up();
    $migration->up();

    expect(ImputationVersement::query()->where('origine', 'reprise')->count())->toBe(14);
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $bilan): bool => $message === 'Reprise des comptes'
        && $bilan['totaux']['avance'] === 785000 && $bilan['auditeurs_hors_equivalence'] === 1)->twice();
});

it('s\'annule en entier au moindre écart, en nommant l\'auditeur par son uuid', function (): void {
    // Un compte déjà touché à la main : la reprise l'ignore, et la vérification trouve son avance à côté d'un reste.
    $touche = Versement::query()->where('inscription_annuelle_id', $this->debloque->id)->sole();
    DB::table('imputations_versements')->insert([
        'uuid' => (string) Str::uuid(), 'auditeur_id' => $touche->auditeur_id, 'versement_id' => $touche->id,
        'inscription_annuelle_id' => $this->debloque->id, 'montant' => 40000, 'origine' => 'saisie', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $migration = require database_path('migrations/'.MIGRATION_REPRISE);

    expect(fn () => DB::transaction(fn () => $migration->up()))
        ->toThrow(RuntimeException::class, 'auditeur:'.$this->debloque->auditeur->uuid)
        ->and(ImputationVersement::query()->count())->toBe(1);
});

it('vérifie en lecture seule : code 0 sur une base saine, non nul sur une incohérence, que des SELECT', function (): void {
    app(RepriseComptes::class)->executer();
    $requetes = [];
    DB::listen(function (QueryExecuted $q) use (&$requetes): void {
        $requetes[] = $q->sql;
    });

    expect(Artisan::call('scolarite:verifier-comptes', ['--equivalence' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Aucun écart');

    $versement = Versement::query()->where('inscription_annuelle_id', $this->soldee->id)->orderBy('id')->firstOrFail();
    $code = null;
    $pendant = [];
    try {
        DB::transaction(function () use ($versement, &$code, &$requetes, &$pendant): void {
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
            DB::table('versements')->where('id', $versement->id)->update(['annule_at' => now(), 'annule_motif' => 'Incohérence injectée']);
            $requetes = [];
            $code = Artisan::call('scolarite:verifier-comptes');
            $pendant = $requetes;
            throw new RuntimeException('retour arrière');
        });
    } catch (RuntimeException) {
    }
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('versement:'.$versement->uuid)
        ->and($pendant)->not->toBeEmpty()
        ->and(array_filter($pendant, fn (string $sql): bool => ! preg_match('/^\s*(select|with)\b/i', $sql)))->toBe([]);
});
