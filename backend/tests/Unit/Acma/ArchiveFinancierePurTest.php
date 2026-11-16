<?php

declare(strict_types=1);

use App\Services\Acma\DatesAcma;
use App\Services\Acma\EcartsAcma2026;
use App\Services\Acma\NormalisationAcma;

/* Lot 15c : dates de soutenance en lettres, mentions, montants, références de reçu (classes pures, données fictives). */

it('lit les dates de soutenance en toutes lettres, abrégées ou mal tapées', function (?string $brut, ?string $attendue): void {
    expect(DatesAcma::texte($brut))->toBe($attendue);
})->with([
    ['29 septembre 2021', '2021-09-29'],
    ['15  juillet  2026', '2026-07-15'],
    ['3 aout 2019', '2019-08-03'],
    ['3 août 2019', '2019-08-03'],
    ['9decembre 2025', '2025-12-09'],
    ['12 juilllet 2022', '2022-07-12'],
    ['1er mars 2020', '2020-03-01'],
    ['5 sept. 2018', '2018-09-05'],
    ['20 Déc 2023', '2023-12-20'],
    ['14 juin 2024', '2024-06-14'],
    ['2 mai 2024', '2024-05-02'],
    ['12/08/2026', '2026-08-12'],
    ['2026-08-12 10:00:00', '2026-08-12'],
    ['31 février 2024', null],
    ['date illisible', null],
    ['', null],
    [null, null],
]);

it('normalise les mentions de soutenance', function (?string $brut, ?string $attendue): void {
    expect(NormalisationAcma::mention($brut))->toBe($attendue);
})->with([
    ['TRES-BIEN', 'Très bien'],
    ['TRES BIEN', 'Très bien'],
    ['Très bien', 'Très bien'],
    ['TRES-BIEN / VERY GOO', 'Très bien'],
    ['BIEN ', 'Bien'],
    ['ASSEZ-BIEN', 'Assez bien'],
    ['EXCELLENT', 'Excellent'],
    ['', null],
    ['INCONNUE', null],
]);

it('lit les montants d\'ACMA en francs entiers et bloque une décimale', function (): void {
    expect(NormalisationAcma::montant('1145000', 'x'))->toBe(1145000)
        ->and(NormalisationAcma::montant('-100000', 'x'))->toBe(-100000)
        ->and(NormalisationAcma::montant('1e+06', 'x'))->toBe(1000000)
        ->and(NormalisationAcma::montant('685000.0', 'x'))->toBe(685000)
        ->and(NormalisationAcma::montant('', 'x'))->toBeNull()
        ->and(fn () => NormalisationAcma::montant('685000.5', 'paiement 1'))->toThrow(RuntimeException::class, 'paiement 1');
});

it('normalise les références de reçu pour le rapprochement 2026-2027', function (): void {
    expect(EcartsAcma2026::reference('CR 2026/001'))->toBe('CR2026001')
        ->and(EcartsAcma2026::reference('cr-2026-001'))->toBe('CR2026001')
        ->and(EcartsAcma2026::reference(null))->toBe('');
});
