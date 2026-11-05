<?php

declare(strict_types=1);

use App\Services\Scolarite\SituationFinanciere;
use Carbon\CarbonImmutable;

/**
 * @param  array{0:int,1:int,2:int}  $bareme
 */
function situation(array $bareme, int $remise, int $verse, ?string $t1 = null, ?string $t2 = null, string $jour = '2026-11-05'): SituationFinanciere
{
    return SituationFinanciere::calculer(
        montantInscription: $bareme[0],
        montantTranche1: $bareme[1],
        montantTranche2: $bareme[2],
        remise: $remise,
        verse: $verse,
        aujourdhui: CarbonImmutable::parse($jour),
        echeanceTranche1: $t1 === null ? null : CarbonImmutable::parse($t1),
        echeanceTranche2: $t2 === null ? null : CarbonImmutable::parse($t2),
    );
}

const M1 = [185000, 500000, 500000];
const M2 = [135000, 500000, 500000];

it('répartit le cumul dans l\'ordre des paliers', function (array $bareme, int $remise, int $verse, array $dus, array $payes, int $reste, bool $inscriptionSoldee): void {
    $s = situation($bareme, $remise, $verse);

    expect(array_map(fn ($p): int => $p->du, $s->paliers))->toBe($dus)
        ->and(array_map(fn ($p): int => $p->paye, $s->paliers))->toBe($payes)
        ->and($s->reste)->toBe($reste)
        ->and($s->inscriptionSoldee())->toBe($inscriptionSoldee);
})->with([
    'rien versé' => [M1, 0, 0, [185000, 500000, 500000], [0, 0, 0], 1185000, false],
    'juste sous le seuil' => [M1, 0, 184999, [185000, 500000, 500000], [184999, 0, 0], 1000001, false],
    'seuil M1 atteint' => [M1, 0, 185000, [185000, 500000, 500000], [185000, 0, 0], 1000000, true],
    'exemple libre' => [M1, 0, 485000, [185000, 500000, 500000], [185000, 300000, 0], 700000, true],
    'exemple de la spec' => [M1, 200000, 485000, [185000, 500000, 300000], [185000, 300000, 0], 500000, true],
    'tout payé' => [M1, 0, 1185000, [185000, 500000, 500000], [185000, 500000, 500000], 0, true],
    'seuil M2' => [M2, 0, 135000, [135000, 500000, 500000], [135000, 0, 0], 1000000, true],
    'M2 sous le seuil M1 mais au-dessus du sien' => [M2, 0, 150000, [135000, 500000, 500000], [135000, 15000, 0], 985000, true],
    'remise sur la 2e tranche seule : le code reste bloqué' => [M1, 200000, 0, [185000, 500000, 300000], [0, 0, 0], 985000, false],
    'remise couvrant les 2 tranches : le code reste bloqué' => [M1, 1000000, 0, [185000, 0, 0], [0, 0, 0], 185000, false],
    'remise entamant l\'inscription' => [M1, 1100000, 0, [85000, 0, 0], [0, 0, 0], 85000, false],
    'exonération totale : inscription soldée sans versement' => [M1, 1185000, 0, [0, 0, 0], [0, 0, 0], 0, true],
    'remise au-delà du total : plafonnée' => [M1, 2000000, 0, [0, 0, 0], [0, 0, 0], 0, true],
]);

it('expose le total dû, le cumul et la remise appliquée', function (): void {
    $s = situation(M1, 200000, 485000);

    expect($s->duTotal)->toBe(985000)
        ->and($s->verse)->toBe(485000)
        ->and($s->remise)->toBe(200000)
        ->and($s->tropPercu)->toBe(0)
        ->and($s->solde())->toBeFalse();
});

it('signale le trop-perçu sans le reporter sur un palier', function (): void {
    $s = situation(M1, 0, 1200000);

    expect($s->tropPercu)->toBe(15000)
        ->and($s->reste)->toBe(0)
        ->and($s->solde())->toBeTrue()
        ->and($s->paliers[2]->paye)->toBe(500000);
});

it('marque en retard une tranche non soldée dont l\'échéance est dépassée', function (): void {
    $s = situation(M1, 0, 185000, '2026-11-01', '2027-02-01');

    expect($s->paliers[0]->enRetard)->toBeFalse()
        ->and($s->paliers[1]->enRetard)->toBeTrue()
        ->and($s->paliers[2]->enRetard)->toBeFalse()
        ->and($s->enRetard())->toBeTrue();
});

it('ne marque pas en retard le jour même de l\'échéance', function (): void {
    expect(situation(M1, 0, 185000, '2026-11-05')->paliers[1]->enRetard)->toBeFalse();
});

it('ne marque pas en retard une tranche soldée, ni une tranche sans date', function (): void {
    expect(situation(M1, 0, 685000, '2026-11-01')->paliers[1]->enRetard)->toBeFalse()
        ->and(situation(M1, 0, 0)->enRetard())->toBeFalse();
});

it('ne marque pas en retard une tranche entièrement couverte par la remise', function (): void {
    expect(situation(M1, 1000000, 185000, '2026-11-01', '2026-11-01')->enRetard())->toBeFalse();
});

it('garde l\'ordre et les clés des paliers', function (): void {
    expect(array_map(fn ($p): string => $p->cle, situation(M1, 0, 0)->paliers))
        ->toBe(['inscription', 'tranche1', 'tranche2']);
});
