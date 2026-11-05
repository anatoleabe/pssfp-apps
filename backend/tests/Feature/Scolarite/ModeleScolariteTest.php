<?php

declare(strict_types=1);

use App\Models\Auditeur;
use App\Models\BaremeScolarite;
use App\Models\InscriptionAnnuelle;
use App\Models\Versement;
use Carbon\Carbon;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Database\UniqueConstraintViolationException;

uses()->group('scolarite');

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class]);
});

it('interdit deux barèmes pour la même année et le même niveau', function (): void {
    BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']);

    expect(fn () => BaremeScolarite::factory()->create(['annee_academique' => '2026-2027', 'niveau' => 'M1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('interdit deux inscriptions pour le même auditeur et la même année', function (): void {
    $i = InscriptionAnnuelle::factory()->create();

    expect(fn () => InscriptionAnnuelle::factory()->create(['auditeur_id' => $i->auditeur_id, 'annee_academique' => $i->annee_academique]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuse une référence déjà prise pour le même mode, même sur un autre auditeur', function (): void {
    Versement::factory()->create(['mode' => 'virement', 'reference' => 'BOR-1']);

    expect(fn () => Versement::factory()->create(['mode' => 'virement', 'reference' => 'BOR-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('accepte la même référence sur un autre mode, ou après annulation', function (): void {
    $v = Versement::factory()->create(['mode' => 'virement', 'reference' => 'BOR-2']);
    Versement::factory()->create(['mode' => 'especes', 'reference' => 'BOR-2']);
    $v->forceFill(['annule_at' => now(), 'annule_motif' => 'Erreur'])->save();

    expect(Versement::factory()->create(['mode' => 'virement', 'reference' => 'BOR-2'])->exists)->toBeTrue();
});

it('calcule la situation à partir des seuls versements valides et du barème copié', function (): void {
    $i = InscriptionAnnuelle::factory()->create();
    Versement::factory()->for($i, 'inscription')->create(['montant' => 200000]);
    Versement::factory()->for($i, 'inscription')->create(['montant' => 100000, 'annule_at' => now()]);

    // Le barème changé après coup ne touche pas une inscription existante.
    $i->bareme->update(['montant_inscription' => 999999]);

    $s = $i->fresh()->situation();
    expect($s->verse)->toBe(200000)
        ->and($s->paliers[0]->du)->toBe(185000)
        ->and($s->paliers[1]->paye)->toBe(15000);
});

it('lit les échéances en direct sur le barème', function (): void {
    $i = InscriptionAnnuelle::factory()->create();
    Versement::factory()->for($i, 'inscription')->create(['montant' => 185000]);

    expect($i->fresh()->situation()->enRetard())->toBeFalse();

    $i->bareme->update(['tranche1_echeance' => '2020-01-01']);

    expect($i->fresh()->situation()->enRetard())->toBeTrue();
});

it('donne pour inscription courante celle de l\'année la plus récente', function (): void {
    $a = Auditeur::factory()->create();
    InscriptionAnnuelle::factory()->for($a)->create(['annee_academique' => '2026-2027']);
    $recente = InscriptionAnnuelle::factory()->for($a)->create(['annee_academique' => '2027-2028', 'niveau' => 'M2']);
    InscriptionAnnuelle::factory()->for($a)->create(['annee_academique' => '2025-2026']);

    expect($a->inscriptionCourante()->first()->id)->toBe($recente->id)
        ->and(Auditeur::query()->with('inscriptionCourante')->find($a->id)->inscriptionCourante->id)->toBe($recente->id);
});

it('filtre en base comme le calcul en PHP', function (int $remise, int $verse, ?string $echeanceT1, ?string $echeanceT2): void {
    $bareme = BaremeScolarite::factory()->create(['tranche1_echeance' => $echeanceT1, 'tranche2_echeance' => $echeanceT2]);
    $i = InscriptionAnnuelle::factory()->create(['bareme_id' => $bareme->id, 'annee_academique' => $bareme->annee_academique, 'remise_montant' => $remise]);
    if ($verse > 0) {
        Versement::factory()->for($i, 'inscription')->create(['montant' => $verse]);
    }
    $s = $i->fresh()->situation();
    $present = fn (string $scope): bool => InscriptionAnnuelle::query()->whereKey($i->id)->{$scope}()->exists();

    expect($present('inscriptionSoldee'))->toBe($s->inscriptionSoldee())
        ->and($present('inscriptionNonSoldee'))->toBe(! $s->inscriptionSoldee())
        ->and($present('entierementSoldee'))->toBe($s->solde())
        ->and($present('enRetard'))->toBe($s->enRetard());
})->with([
    'rien' => [0, 0, null, null],
    'sous le seuil, tranche échue' => [0, 100000, '2020-01-01', null],
    'seuil atteint' => [0, 185000, null, null],
    'seuil atteint, tranche 1 échue' => [0, 185000, '2020-01-01', '2099-01-01'],
    'tranche 1 soldée, tranche 2 échue' => [0, 685000, '2020-01-01', '2020-06-01'],
    'remise 2e tranche, échue' => [500000, 685000, '2020-01-01', '2020-06-01'],
    'tout soldé' => [0, 1185000, '2020-01-01', '2020-06-01'],
    'remise couvrant les tranches, échues' => [1000000, 185000, '2020-01-01', '2020-06-01'],
    'exonération totale' => [1185000, 0, '2020-01-01', '2020-06-01'],
    'trop-perçu' => [0, 1300000, '2020-01-01', '2020-06-01'],
]);

it('range les références en majuscules, sans espaces autour', function (): void {
    expect(Versement::normaliserReference('  bor-12 a '))->toBe('BOR-12 A');
});

it('garde la date du jour au fuseau de Yaoundé', function (): void {
    Carbon::setTestNow('2026-11-05 23:30:00');
    expect(InscriptionAnnuelle::aujourdhui()->toDateString())->toBe('2026-11-06');
    Carbon::setTestNow();
});
