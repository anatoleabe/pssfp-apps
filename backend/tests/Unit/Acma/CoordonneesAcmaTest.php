<?php

declare(strict_types=1);

use App\Services\Acma\CoordonneesAcma;

/* Lot 15i : propositions de téléphones et d'e-mails depuis les coordonnées brutes d'ACMA (classe pure, données fictives). */

it('propose des téléphones E.164 depuis un champ ACMA', function (?string $brut, array $attendus): void {
    expect(array_column(CoordonneesAcma::telephones($brut), 'e164'))->toBe($attendus);
})->with([
    'vide' => [null, []],
    'blanc' => ['   ', []],
    'mobile camerounais' => ['699000001', ['+237699000001']],
    'séparateurs' => ['6 99 00 00 01', ['+237699000001']],
    'points et tirets' => ['699.00-00.01', ['+237699000001']],
    'indicatif' => ['+237 699000001', ['+237699000001']],
    'indicatif sans plus' => ['00237699000001', ['+237699000001']],
    'fixe' => ['222000001', ['+237222000001']],
    'deux numéros' => ['699000001 / 677000002', ['+237699000001', '+237677000002']],
    'doublon' => ['699000001;699000001', ['+237699000001']],
    'étranger' => ['+33 6 00 00 00 01', ['+33600000001']],
    'trop court' => ['12345', []],
    'texte' => ['néant', []],
]);

it('signale un ancien numéro camerounais à huit chiffres, sans le confondre avec un numéro à neuf', function (): void {
    $p = CoordonneesAcma::telephones('99000001');

    expect($p)->toHaveCount(1)
        ->and($p[0]['e164'])->toBe('+237699000001')
        ->and($p[0]['ancien_format'])->toBeTrue()
        ->and(CoordonneesAcma::telephones('699000001')[0]['ancien_format'])->toBeFalse()
        ->and(CoordonneesAcma::telephones('22000001')[0]['e164'])->toBe('+237222000001');
});

it('ne propose que des e-mails de forme valide, en minuscules', function (?string $brut, ?string $attendu): void {
    expect(CoordonneesAcma::email($brut))->toBe($attendu);
})->with([
    'vide' => [null, null],
    'valide' => ['Essai.Fictif@Exemple.invalid', 'essai.fictif@exemple.invalid'],
    'espaces' => ['  essai@exemple.invalid ', 'essai@exemple.invalid'],
    'autre usage du champ' => ['x', null],
    'sans domaine' => ['essai@', null],
]);

it('propose d\'abord le mobile, puis le téléphone, puis le premier e-mail valide', function (): void {
    $c = CoordonneesAcma::propositions([
        'telephone_source' => '222000001',
        'mobile_source' => '699000001',
        'email_source' => 'x',
        'email2_source' => 'essai@exemple.invalid',
    ]);

    expect($c['telephone'])->toBe('+237699000001')
        ->and($c['telephone2'])->toBe('+237222000001')
        ->and($c['email'])->toBe('essai@exemple.invalid');

    $vide = CoordonneesAcma::propositions([]);
    expect($vide['telephone'])->toBeNull()->and($vide['telephone2'])->toBeNull()->and($vide['email'])->toBeNull();
});
