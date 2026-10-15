<?php

declare(strict_types=1);

use App\Services\ResultatsSelection\Repartition;

uses()->group('resultats-selection');

it('totalise par ligne, par colonne et au global', function (): void {
    $r = new Repartition(['M' => 'HOMMES', 'F' => 'FEMMES'], [
        'Audit' => ['M' => 3, 'F' => 2],
        'Fiscalité' => ['F' => 4],
        'Marchés' => [],
    ]);

    expect($r->valeur('Fiscalité', 'M'))->toBe(0)
        ->and($r->totalLigne('Audit'))->toBe(5)
        ->and($r->totalColonne('F'))->toBe(6)
        ->and($r->total())->toBe(9);
});

it('produit des pourcentages entiers dont la somme fait toujours 100', function (array $effectifs, array $attendus): void {
    $colonnes = array_combine(array_keys($effectifs), array_keys($effectifs));
    $r = new Repartition($colonnes, ['Spécialité' => $effectifs]);

    expect(array_map(fn (string $c): string => $r->pourcentage($c), array_keys($effectifs)))->toBe($attendus);
})->with([
    'arrondi naïf à 101' => [['M' => 57, 'F' => 63], ['48 %', '52 %']],
    'tiers' => [['a' => 1, 'b' => 1, 'c' => 1], ['34 %', '33 %', '33 %']],
    'exact' => [['P' => 3, 'D' => 1], ['75 %', '25 %']],
    'vide' => [['P' => 0, 'D' => 0], ['0 %', '0 %']],
]);

it('croise des éléments en gardant les lignes vides et en regroupant les valeurs inconnues', function (): void {
    $r = Repartition::croiser(
        [['l' => 'B', 'c' => 'x'], ['l' => 'B', 'c' => 'zzz'], ['l' => 'Hors liste', 'c' => 'y']],
        ['A', 'B'],
        fn (array $e): string => $e['l'],
        ['x' => 'X', 'y' => 'Y'],
        fn (array $e): string => $e['c'],
    );

    expect(array_keys($r->lignes))->toBe(['A', 'B', 'Hors liste'])
        ->and($r->colonnes)->toBe(['x' => 'X', 'y' => 'Y', '—' => 'NON PRÉCISÉ'])
        ->and($r->valeur('B', '—'))->toBe(1)
        ->and($r->totalLigne('A'))->toBe(0);
});

it('donne la part de chaque ligne, arrondie pour totaliser 100', function (): void {
    $r = new Repartition(['n' => 'N'], ['a' => ['n' => 1], 'b' => ['n' => 1], 'c' => ['n' => 1]]);

    expect([$r->partLigne('a'), $r->partLigne('b'), $r->partLigne('c')])->toBe(['34 %', '33 %', '33 %']);
});
