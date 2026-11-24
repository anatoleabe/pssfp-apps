<?php

declare(strict_types=1);

use App\Support\Pdf\Banniere;

/*
 * Bannière des documents du LMD (spec lot 24b) : Université pour les documents académiques,
 * Ministère des finances pour les documents administratifs.
 */

it('place les documents académiques sous la bannière de l\'Université', function (): void {
    $html = view('pdf.lmd._entete')->render();

    expect($html)->toContain('UNIVERSITÉ DE YAOUNDÉ II-SOA')
        ->toContain('FACULTÉ DES SCIENCES ÉCONOMIQUES ET DE GESTION')
        ->toContain('data:image/png;base64,')
        ->not->toContain('MINISTÈRE DES FINANCES');
});

it('garde les documents administratifs sous la bannière du Ministère', function (): void {
    $html = view('pdf.lmd._entete', ['banniere' => Banniere::PSSFP])->render();

    expect($html)->toContain('MINISTÈRE DES FINANCES')->not->toContain('UNIVERSITÉ DE YAOUNDÉ');
});

it('pose l\'en-tête officiel du PSSFP, avec son logo, sur les documents administratifs', function (): void {
    expect(view('pdf.lmd._entete', ['banniere' => Banniere::PSSFP])->render())->toContain('<img src="data:image/png;base64,')
        ->and(view('pdf.lmd._entete')->render())->not->toContain('SECRÉTARIAT GÉNÉRAL');
});

it('ne laisse que les documents administratifs sous la bannière du Ministère', function (): void {
    $administratifs = collect(glob(resource_path('views/pdf/lmd/*.blade.php')) ?: [])
        ->filter(fn (string $f): bool => str_contains((string) file_get_contents($f), 'Banniere::PSSFP'))
        ->map(fn (string $f): string => basename($f))->sort()->values()->all();

    expect($administratifs)->toBe(['_administratif.blade.php', 'projet-acte.blade.php']);
});
