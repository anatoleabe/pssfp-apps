<?php

declare(strict_types=1);

use App\Support\NombreEnLettres;

uses()->group('resultats-selection');

/*
 * Les PV écrivent l'année en toutes lettres (« L'an deux-mille-vingt-cinq ») et
 * la promotion en ordinal majuscule (« TREIZIEME PROMOTION »). Orthographe
 * rectifiée de 1990 : traits d'union partout, comme dans les PV signés.
 */
it('écrit les cardinaux en toutes lettres', function (int $n, string $attendu): void {
    expect(NombreEnLettres::cardinal($n))->toBe($attendu);
})->with([
    [0, 'zéro'],
    [1, 'un'],
    [16, 'seize'],
    [17, 'dix-sept'],
    [21, 'vingt-et-un'],
    [70, 'soixante-dix'],
    [71, 'soixante-et-onze'],
    [80, 'quatre-vingts'],
    [81, 'quatre-vingt-un'],
    [91, 'quatre-vingt-onze'],
    [100, 'cent'],
    [200, 'deux-cents'],
    [201, 'deux-cent-un'],
    [1000, 'mille'],
    [2025, 'deux-mille-vingt-cinq'],
    [2026, 'deux-mille-vingt-six'],
    [2080, 'deux-mille-quatre-vingts'],
]);

it('écrit les ordinaux en toutes lettres', function (int $n, string $attendu): void {
    expect(NombreEnLettres::ordinal($n))->toBe($attendu);
})->with([
    [1, 'premier'],
    [2, 'deuxième'],
    [5, 'cinquième'],
    [9, 'neuvième'],
    [12, 'douzième'],
    [13, 'treizième'],
    [14, 'quatorzième'],
    [20, 'vingtième'],
    [21, 'vingt-et-unième'],
    [80, 'quatre-vingtième'],
    [100, 'centième'],
]);

it('refuse les nombres hors plage', function (): void {
    NombreEnLettres::cardinal(-1);
})->throws(InvalidArgumentException::class);
