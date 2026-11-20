<?php

declare(strict_types=1);

use App\Filament\Resources\AuditeurResource\Pages\ViewAuditeur;
use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\SuspensionAuditeur;
use App\Models\User;
use App\Services\Scolarite\LibelleSituation;
use App\Services\Scolarite\Solvabilite;
use App\Services\Scolarite\StatutAuditeur;
use App\Services\Scolarite\SuspensionsAuditeurs;
use App\Support\CandidatureObjectStorage;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Livewire\livewire;

/*
 * Suspension d'un auditeur pour l'année en cours, sur sa demande écrite (décisions du 20/11/2026) : administrateur
 * seul, demande jointe et confirmée, compte eCampus suspendu, reste de l'année non réclamé, levée manuelle.
 */

uses()->group('scolarite', 'filament');

/**
 * Faux eCampus : un compte (ou aucun), et la réponse aux changements. Note les appels de changement. Le compte et les
 * avertissements se changent en cours de test par `$test->ecampus` : un second Http::fake ne remplacerait pas le premier.
 */
function fauxEcampusSuspension(object $test, ?array $compte, array $avertissements = []): void
{
    $test->changements = [];
    $test->ecampus = ['compte' => $compte, 'avertissements' => $avertissements];
    Http::fake(['ecampus.test/*' => function (Request $r) use ($test) {
        $d = $r->data();
        if (($d['wsfunction'] ?? '') === 'core_user_update_users') {
            $test->changements[] = array_map('strval', $d['users'][0]);
            if ($test->ecampus['avertissements'] === [] && $test->ecampus['compte'] !== null && isset($d['users'][0]['suspended'])) {
                $test->ecampus['compte']['suspended'] = (bool) $d['users'][0]['suspended'];
            }

            return Http::response(['warnings' => $test->ecampus['avertissements']]);
        }

        return ($d['wsfunction'] ?? '') === 'core_user_get_users_by_field'
            ? Http::response($test->ecampus['compte'] === null ? [] : [$test->ecampus['compte']])
            : Http::response(['exception' => 'moodle_exception', 'errorcode' => 'inconnu']);
    }]);
}

function demandeSuspension(): string
{
    return 'scolarite/justificatifs/'.Str::ulid().'.pdf';
}

beforeEach(function (): void {
    config()->set('pssfp.filament.require_2fa', false);
    config()->set('pssfp.foad_url', 'https://ecampus.test');
    config()->set('pssfp.ecampus.jeton', 'jeton-de-test');
    config()->set('scolarite.annee_en_cours', '2026-2027');
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class, RolePermissionSeeder::class]);
    $this->compte = fn (string $role): User => tap(User::factory()->create(['email' => $role.'-'.Str::random(6).'@pssfp.local']), fn (User $u) => $u->assignRole($role));
    $this->admin = ($this->compte)('admin');

    $this->auditeur = Auditeur::factory()->create();
    $bareme = BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1', 'tranche1_echeance' => '2020-01-01', 'tranche2_echeance' => '2020-02-01']);
    $this->inscription = InscriptionAnnuelle::factory()->create(['auditeur_id' => $this->auditeur->id, 'bareme_id' => $bareme->id]);
    $c = $this->auditeur->candidature;
    $this->compteEcampus = ['id' => 77, 'username' => mb_strtolower((string) $c->matricule), 'idnumber' => (string) $c->matricule,
        'email' => (string) $c->email, 'firstname' => 'A', 'lastname' => 'B', 'suspended' => false, 'lastaccess' => 0];
    fauxEcampusSuspension($this, $this->compteEcampus);

    $this->suspendre = fn (array $p = []): SuspensionAuditeur => app(SuspensionsAuditeurs::class)->suspendre(
        $p['auditeur'] ?? $this->auditeur,
        $p['demande'] ?? demandeSuspension(),
        $p['demande_du'] ?? '2026-11-15',
        $p['motif'] ?? 'Raisons professionnelles',
        $p['effet_le'] ?? '2026-11-16',
        $p['verifiee'] ?? true,
        $p['agent'] ?? $this->admin,
    );
});

describe('suspension', function (): void {
    it('suspend pour l\'année en cours avec la demande, marque l\'inscription et suspend le compte eCampus', function (): void {
        $s = ($this->suspendre)();

        $a = $this->auditeur->fresh();
        expect($a->statut)->toBe(Auditeur::STATUT_SUSPENDU)
            ->and($a->statut_depuis->toDateString())->toBe('2026-11-16')
            ->and($a->statut_motif)->toContain('2026-2027')->toContain('15/11/2026')
            ->and($s->annee_academique)->toBe('2026-2027')
            ->and($s->inscription_annuelle_id)->toBe($this->inscription->id)
            ->and($s->suspendu_par)->toBe($this->admin->id)
            ->and($s->ecampus_suspendu_at)->not->toBeNull()
            ->and($this->inscription->fresh()->estSuspendue())->toBeTrue()
            ->and($this->changements)->toBe([['id' => '77', 'suspended' => '1']])
            ->and(Activity::query()->where('event', 'suspension')->sole()->causer_id)->toBe($this->admin->id);
    });

    it('réserve la suspension à l\'administration', function (string $role, bool $autorise): void {
        $appel = fn () => ($this->suspendre)(['agent' => ($this->compte)($role)]);

        $autorise ? expect($appel)->not->toThrow(HttpException::class) : expect($appel)->toThrow(HttpException::class);
    })->with([['admin', true], ['super_admin', true], ['scolarite_responsable', false], ['scolarite_agent', false], ['service_financier', false]]);

    it('refuse sans demande, sans confirmation, sans motif, dans le futur ou deux fois', function (array $p, string $message): void {
        expect(fn () => ($this->suspendre)($p))->toThrow(InvalidArgumentException::class, $message);
        expect(SuspensionAuditeur::query()->count())->toBe(0);
    })->with([
        'sans demande' => [['demande' => ''], 'demande écrite'],
        'chemin étranger' => [['demande' => 'candidatures/autre/piece.pdf'], 'demande écrite'],
        'non confirmée' => [['verifiee' => false], 'Cochez'],
        'sans motif' => [['motif' => '  '], 'motif'],
        'lettre future' => [['demande_du' => '2099-01-01'], 'futur'],
    ]);

    it('refuse un auditeur déjà suspendu ou non inscrit dans l\'année en cours', function (): void {
        ($this->suspendre)();
        expect(fn () => ($this->suspendre)())->toThrow(InvalidArgumentException::class, 'seul un auditeur actif');

        $autre = Auditeur::factory()->create();
        expect(fn () => ($this->suspendre)(['auditeur' => $autre]))->toThrow(InvalidArgumentException::class, 'pas inscrit en 2026-2027');
    });

    it('n\'emploie pas deux fois la même pièce', function (): void {
        $piece = demandeSuspension();
        ($this->suspendre)(['demande' => $piece]);
        $autre = Auditeur::factory()->create();
        InscriptionAnnuelle::factory()->create(['auditeur_id' => $autre->id, 'bareme_id' => $this->inscription->bareme_id]);

        expect(fn () => ($this->suspendre)(['auditeur' => $autre, 'demande' => $piece]))->toThrow(InvalidArgumentException::class, 'demande écrite');
    });
});

describe('eCampus', function (): void {
    it('garde la suspension quand eCampus refuse, puis la refait sur demande', function (): void {
        $this->ecampus['avertissements'] = [['message' => 'refus']];
        $s = ($this->suspendre)();

        expect($this->auditeur->fresh()->statut)->toBe(Auditeur::STATUT_SUSPENDU)
            ->and($s->ecampus_suspendu_at)->toBeNull()
            ->and($s->ecampus_detail)->toContain('pas confirmé')
            ->and(SuspensionsAuditeurs::ecampusARefaire($s))->toBeTrue();

        $this->ecampus['avertissements'] = [];
        app(SuspensionsAuditeurs::class)->reessayerEcampus($s, $this->admin);
        expect($s->fresh()->ecampus_suspendu_at)->not->toBeNull();
    });

    it('ne touche ni un compte absent ni le compte d\'un autre', function (): void {
        $this->ecampus['compte'] = null;
        $s = ($this->suspendre)();
        expect($s->ecampus_suspendu_at)->not->toBeNull()->and($s->ecampus_detail)->toContain('Aucun compte')->and($this->changements)->toBe([]);

        $autre = Auditeur::factory()->create();
        InscriptionAnnuelle::factory()->create(['auditeur_id' => $autre->id, 'bareme_id' => $this->inscription->bareme_id]);
        $this->ecampus['compte'] = ['idnumber' => 'XX', 'email' => 'x@example.org'] + $this->compteEcampus;
        $s2 = ($this->suspendre)(['auditeur' => $autre]);
        expect($s2->ecampus_suspendu_at)->toBeNull()->and($s2->ecampus_detail)->toContain('laissé tel quel')->and($this->changements)->toBe([]);
    });
});

describe('finances', function (): void {
    it('ne réclame plus le reste de l\'année suspendue : ni en retard, ni échu, situation « Année suspendue »', function (): void {
        $i = fn (): InscriptionAnnuelle => InscriptionAnnuelle::query()->with('bareme')->findOrFail($this->inscription->id);
        expect(LibelleSituation::cle($i()))->toBe(LibelleSituation::NON_SOLDEE)
            ->and(Solvabilite::echuImpaye($i()->situation()))->toBeGreaterThan(0)
            ->and(InscriptionAnnuelle::query()->whereKey($this->inscription->id)->enRetard()->exists())->toBeTrue();

        ($this->suspendre)();

        expect(LibelleSituation::cle($i()))->toBe(LibelleSituation::SUSPENDUE)
            ->and($i()->situation()->enRetard())->toBeFalse()
            ->and($i()->situation()->reste)->toBe(1185000)
            ->and(Solvabilite::echuImpaye($i()->situation()))->toBe(0)
            ->and(Solvabilite::classer([$i()], 0)['categorie'])->toBe(Solvabilite::EN_REGLE)
            ->and(InscriptionAnnuelle::query()->whereKey($this->inscription->id)->enRetard()->exists())->toBeFalse();
    });
});

describe('levée', function (): void {
    it('dans la même année : actif, reste de nouveau dû, compte eCampus rouvert', function (): void {
        ($this->suspendre)();
        $s = app(SuspensionsAuditeurs::class)->lever($this->auditeur, 'Reprise demandée par écrit', $this->admin);

        expect($this->auditeur->fresh()->statut)->toBe(Auditeur::STATUT_ACTIF)
            ->and($s->reprise_meme_annee)->toBeTrue()
            ->and($s->ecampus_rouvert_at)->not->toBeNull()
            ->and($this->inscription->fresh()->estSuspendue())->toBeFalse()
            ->and(end($this->changements))->toBe(['id' => '77', 'suspended' => '0'])
            ->and(Activity::query()->where('event', 'suspension_levee')->exists())->toBeTrue();
    });

    it('une année suivante : l\'année suspendue reste non réclamée', function (): void {
        ($this->suspendre)();
        config()->set('scolarite.annee_en_cours', '2027-2028');

        $s = app(SuspensionsAuditeurs::class)->lever($this->auditeur, 'Reprise à la rentrée suivante', $this->admin);

        expect($s->reprise_meme_annee)->toBeFalse()->and($this->inscription->fresh()->estSuspendue())->toBeTrue();
    });

    it('refuse sans motif ou sans suspension en cours', function (): void {
        expect(fn () => app(SuspensionsAuditeurs::class)->lever($this->auditeur, 'motif', $this->admin))->toThrow(InvalidArgumentException::class, 'Aucune suspension');
        ($this->suspendre)();
        expect(fn () => app(SuspensionsAuditeurs::class)->lever($this->auditeur, ' ', $this->admin))->toThrow(InvalidArgumentException::class, 'motif');
    });
});

describe('garde-fous', function (): void {
    it('la base refuse de modifier la demande, de supprimer une suspension ou de retoucher une levée', function (): void {
        $s = ($this->suspendre)();

        // Chaque essai dans son point de reprise : une erreur SQL rompt la transaction du test.
        $essai = fn (callable $f): Closure => fn () => DB::transaction($f);
        expect($essai(fn () => SuspensionAuditeur::query()->whereKey($s->id)->update(['motif' => 'autre'])))->toThrow(QueryException::class, 'ne se modifie pas')
            ->and($essai(fn () => DB::table('suspensions_auditeurs')->where('id', $s->id)->delete()))->toThrow(QueryException::class, 'ne se supprime pas');

        app(SuspensionsAuditeurs::class)->lever($this->auditeur, 'Reprise', $this->admin);
        expect($essai(fn () => SuspensionAuditeur::query()->whereKey($s->id)->update(['levee_motif' => 'autre'])))->toThrow(QueryException::class, 'levée');
    });

    it('« Changer le statut » ne pose plus « Suspendu » et ne sort pas d\'une suspension en cours', function (): void {
        $resp = ($this->compte)('scolarite_responsable');
        expect(fn () => app(StatutAuditeur::class)->changer($this->auditeur, Auditeur::STATUT_SUSPENDU, 'motif', '2026-11-01', $resp))
            ->toThrow(InvalidArgumentException::class, 'Suspendre pour l\'année');

        ($this->suspendre)();
        expect(fn () => app(StatutAuditeur::class)->changer($this->auditeur, Auditeur::STATUT_ACTIF, 'motif', '2026-11-01', $resp))
            ->toThrow(InvalidArgumentException::class, 'levez-la');
    });
});

describe('fiche auditeur', function (): void {
    beforeEach(function (): void {
        Storage::fake(CandidatureObjectStorage::DISK);
    });

    it('suspend depuis la fiche avec la demande jointe et la confirmation, puis lève', function (): void {
        $this->actingAs($this->admin);
        $page = livewire(ViewAuditeur::class, ['record' => $this->auditeur->uuid])->assertActionVisible('suspendre');

        $page->callAction('suspendre', data: ['demande_path' => bordereauScanne(), 'demande_du' => '2026-11-15', 'effet_le' => '2026-11-16', 'motif' => 'Mutation', 'verifiee' => false])
            ->assertHasActionErrors(['verifiee']);
        expect(SuspensionAuditeur::query()->count())->toBe(0);

        $page->callAction('suspendre', data: ['demande_path' => bordereauScanne(), 'demande_du' => '2026-11-15', 'effet_le' => '2026-11-16', 'motif' => 'Mutation', 'verifiee' => true])
            ->assertHasNoActionErrors();
        $s = SuspensionAuditeur::query()->sole();
        Storage::disk(CandidatureObjectStorage::DISK)->assertExists($s->demande_path);
        expect($this->auditeur->fresh()->statut)->toBe(Auditeur::STATUT_SUSPENDU);

        $page = livewire(ViewAuditeur::class, ['record' => $this->auditeur->uuid])
            ->assertActionHidden('suspendre')->assertActionHidden('changerStatut')->assertActionVisible('voirDemandeSuspension');
        $page->callAction('leverSuspension', data: ['motif' => 'Reprise'])->assertHasNoActionErrors();
        expect($this->auditeur->fresh()->statut)->toBe(Auditeur::STATUT_ACTIF);
    });

    it('cache la suspension au responsable de scolarité', function (): void {
        $this->actingAs(($this->compte)('scolarite_responsable'));

        livewire(ViewAuditeur::class, ['record' => $this->auditeur->uuid])->assertActionHidden('suspendre');
    });
});
