<?php

declare(strict_types=1);

use App\Models\AnomalieSysteme;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use App\Services\Supervision\Controle;
use App\Services\Supervision\Relances;
use App\Services\Supervision\Supervision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Contrôle interne CPT_COHERENCE (spec D1, §13 ; réponse du chef de projet : responsable
 * `admin`, escalade `super_admin`). Les incohérences que la base refuse sont injectées dans
 * un point de sauvegarde, déclencheurs reportés, et le contrôle s'exécute avant le retour arrière.
 */

uses()->group('scolarite', 'supervision');

beforeEach(function (): void {
    $this->inscription = InscriptionAnnuelle::factory()->create();
    $this->matricule = (string) $this->inscription->auditeur->candidature->matricule;
    $this->controle = fn (): Controle => app(Supervision::class)->controles()['CPT_COHERENCE'];
    // Cas trouvés pendant une incohérence injectée, puis annulée avec le point de sauvegarde.
    $this->pendant = function (Closure $injecter): array {
        $cas = [];
        try {
            DB::transaction(function () use ($injecter, &$cas): void {
                DB::statement('SET CONSTRAINTS ALL DEFERRED');
                $injecter();
                $cas = ($this->controle)()->cas();
                throw new RuntimeException('retour arrière');
            });
        } catch (RuntimeException) {
        }
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        return $cas;
    };
    $this->imputer = fn (Versement $v, int $montant): int => DB::table('imputations_versements')->insertGetId([
        'uuid' => (string) Str::uuid(), 'auditeur_id' => $v->auditeur_id, 'versement_id' => $v->id,
        'inscription_annuelle_id' => $v->inscription_annuelle_id, 'montant' => $montant, 'origine' => 'saisie',
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

it('est un contrôle bloquant d\'un jour, confié à l\'USI, escaladé au super administrateur', function (): void {
    $supervision = app(Supervision::class);
    $controle = ($this->controle)();
    $codes = array_keys($supervision->controles());

    expect($controle->criticite())->toBe(Controle::BLOQUANT)
        ->and($controle->delaiJours())->toBe(1)
        ->and($controle->responsable())->toBe('admin')
        ->and($controle->domaine())->toBe('Scolarité')
        ->and(array_search('CPT_COHERENCE', $codes, true))->toBeLessThan(array_search('SYS_CONTROLES', $codes, true))
        ->and(Relances::roleCible($controle, 1, null))->toBe('admin')
        ->and(Relances::roleCible($controle, 2, Relances::ESCALADE))->toBe('super_admin')
        ->and(Relances::roleCible($controle, 3, Relances::DIRECTION))->toBe(Relances::DIRECTION)
        // Les autres contrôles gardent le barème commun.
        ->and(Relances::roleCible($supervision->controles()['DEB_ECHUS'], 2, Relances::ESCALADE))->toBe(Relances::ESCALADE);
});

it('ne trouve aucun cas sur une base saine', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 1300000]);
    Versement::factory()->create(['inscription_annuelle_id' => InscriptionAnnuelle::factory()->create()->id, 'montant' => 185000, 'payeur' => 'employeur']);

    app(Supervision::class)->executer(['CPT_COHERENCE']);

    expect(($this->controle)()->cas())->toBe([])
        ->and(AnomalieSysteme::query()->where('controle_code', 'CPT_COHERENCE')->count())->toBe(0);
});

it('signale un versement imputé au-delà de son montant', function (): void {
    $v = Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 100000]);

    $cas = ($this->pendant)(fn () => ($this->imputer)($v, 150000));

    expect(array_keys($cas))->toContain('versement:'.$v->uuid)
        ->and($cas['versement:'.$v->uuid]['resume'])->toContain($this->matricule)->toContain('150 000')->toContain('100 000')
        ->and($cas['versement:'.$v->uuid]['resume'])->not->toContain((string) $this->inscription->auditeur->candidature->nom);
});

it('signale une imputation active sur un versement annulé', function (): void {
    $v = Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 185000]);

    $cas = ($this->pendant)(fn () => DB::table('versements')->where('id', $v->id)->update(['annule_at' => now(), 'annule_motif' => 'Incohérence injectée']));

    expect($cas['versement:'.$v->uuid]['resume'])->toContain('annulé');
});

it('signale une inscription imputée au-delà de son dû', function (): void {
    Versement::factory()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 1185000]);

    $cas = ($this->pendant)(fn () => DB::table('inscriptions_annuelles')->where('id', $this->inscription->id)->update(['remise_montant' => 200000]));

    expect($cas['inscription:'.$this->inscription->uuid]['resume'])->toContain($this->matricule)->toContain('985 000');
});

it('signale une avance libre à côté d\'un reste ouvert, et une avance réservée à côté d\'un reste de son année', function (string $payeur, string $nature): void {
    // Un écrivain qui oublierait R2 ou R3 : le versement reste entier en avance.
    Versement::factory()->sansImputation()->create(['inscription_annuelle_id' => $this->inscription->id, 'montant' => 185000, 'payeur' => $payeur]);

    $cas = ($this->controle)()->cas();
    $cle = 'auditeur:'.$this->inscription->auditeur->uuid;

    expect(array_keys($cas))->toBe([$cle])
        ->and($cas[$cle]['resume'])->toContain($this->matricule)->toContain($nature)->toContain('185 000');
})->with([
    'auditeur' => ['auditeur', 'avance libre'],
    'employeur' => ['employeur', 'avance réservée'],
]);
