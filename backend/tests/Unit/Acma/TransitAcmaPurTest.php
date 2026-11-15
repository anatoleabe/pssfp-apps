<?php

declare(strict_types=1);

use App\Services\Acma\CsvAcma;
use App\Services\Acma\DatesAcma;
use App\Services\Acma\NormalisationAcma;

/* Lot 15a : CSV du transit, dates d'ACMA et matricules (classes pures, données fictives). */

it('écrit et relit un CSV RFC 4180 en distinguant NULL du texte « \N »', function (): void {
    $valeurs = [null, '\N', '', 'a,b', 'dit "oui"', "deux\nlignes", "fin\r\n", '0000-00-00', '1145000', 'Légende'];
    $fichier = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($fichier, CsvAcma::ligne(['entête', 'x']).CsvAcma::ligne($valeurs).CsvAcma::ligne([null, null]));

    $lu = iterator_to_array(CsvAcma::lire($fichier), false);
    unlink($fichier);

    expect($lu)->toHaveCount(3)
        ->and($lu[1])->toBe($valeurs)
        ->and($lu[2])->toBe([null, null]);
});

it('refuse un CSV tronqué ou mal formé', function (): void {
    $fichier = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($fichier, "\"ouvert,\"x\"\r\n");
    expect(fn () => iterator_to_array(CsvAcma::lire($fichier)))->toThrow(RuntimeException::class);
    unlink($fichier);
    expect(fn () => CsvAcma::analyser('"a"b,"c"'))->toThrow(RuntimeException::class);
});

it('normalise les matricules sans jamais toucher aux zéros ni aux caractères parasites', function (string $brut, ?string $attendu): void {
    expect(NormalisationAcma::matricule($brut))->toBe($attendu);
})->with([
    'minuscules' => ['90efp002', '90EFP002'],
    'espaces autour' => [' 90EFP002 ', '90EFP002'],
    'espace interne' => ['90 EFP 002', '90EFP002'],
    'zéros conservés' => ['99XX012', '99XX012'],
    'sept caractères' => ['99XX012', '99XX012'],
    'neuf caractères' => ['90EFSC001', '90EFSC001'],
    'tiret gardé' => ['90EFP010-', '90EFP010-'],
    'vide' => ['  ', null],
    'sans lettre ni chiffre' => ['--', null],
]);

it('repère les fiches zz, les caractères parasites et les collisions sans les zéros de tête', function (): void {
    expect(NormalisationAcma::estNeutralise('zz90EFP004'))->toBeTrue()
        ->and(NormalisationAcma::estNeutralise('ZZ90'))->toBeTrue()
        ->and(NormalisationAcma::estNeutralise('90EFPZZ'))->toBeFalse()
        ->and(NormalisationAcma::aCaractereParasite('90EFP010-'))->toBeTrue()
        ->and(NormalisationAcma::aCaractereParasite('90EFP010'))->toBeFalse()
        ->and(NormalisationAcma::sansZerosDeTete('99XX012'))->toBe('99XX12')
        ->and(NormalisationAcma::sansZerosDeTete('99XX12'))->toBe('99XX12')
        ->and(NormalisationAcma::sansZerosDeTete('26EFP001'))->toBe('26EFP1');
});

it('lit les dates d\'ACMA sans jamais en inventer', function (?string $brut, ?string $attendu): void {
    expect(DatesAcma::date($brut))->toBe($attendu);
})->with([
    ['2024-11-02', '2024-11-02'],
    ['2024-11-02 09:30:00', '2024-11-02'],
    ['0000-00-00', null],
    ['2021-06-00', null],
    ['2023-02-30', null],
    ['1120-05-05', '1120-05-05'],
    ['', null],
    [null, null],
    ['29 septembre 2021', null],
]);

it('valide les années académiques et la fenêtre « à un an près »', function (): void {
    expect(NormalisationAcma::annee('2024-2025'))->toBe('2024-2025')
        ->and(NormalisationAcma::annee('2025/2026'))->toBeNull()
        ->and(NormalisationAcma::annee('2025-2027'))->toBeNull()
        ->and(DatesAcma::horsAnnee('2023-01-01', '2024-2025'))->toBeFalse()
        ->and(DatesAcma::horsAnnee('2022-12-31', '2024-2025'))->toBeTrue()
        ->and(DatesAcma::horsAnnee('2026-12-31', '2024-2025'))->toBeFalse()
        ->and(DatesAcma::horsAnnee('2027-01-01', '2024-2025'))->toBeTrue()
        ->and(DatesAcma::horodatage('2025-03-04 10:00:00'))->toBe('2025-03-04 10:00:00')
        ->and(DatesAcma::horodatage('2025-02-30 10:00:00'))->toBeNull();
});
