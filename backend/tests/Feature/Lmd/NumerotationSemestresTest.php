<?php

declare(strict_types=1);

use App\Models\CampagneCandidature;
use App\Models\Candidature;
use App\Models\Maquette;
use App\Services\Lmd\ControleMaquette;
use App\Services\Lmd\ImportMaquettes;
use App\Services\Lmd\NumerotationSemestres;
use Database\Seeders\DepartementsCamerounSeeder;
use Database\Seeders\PaysSeeder;
use Database\Seeders\RegionsCamerounSeeder;
use Illuminate\Database\QueryException;

uses()->group('lmd');

/** Promotion (campagne avec au moins un retenu) dont le M1 se déroule l'année donnée. */
function promotionNumerotation(int $numero, string $anneeM1): CampagneCandidature
{
    $c = CampagneCandidature::factory()->closed()->create(['nom' => ['fr' => 'Année académique '.$anneeM1], 'promotion_numero' => $numero]);
    Candidature::factory()->forCampagne($c)->submitted()->create(['statut' => Candidature::STATUT_ACCEPTE]);

    return $c->refresh();
}

beforeEach(function (): void {
    $this->seed([PaysSeeder::class, RegionsCamerounSeeder::class, DepartementsCamerounSeeder::class]);
});

it('numérote en master par défaut, sans changer le semestre enregistré', function (): void {
    $p14 = promotionNumerotation(14, '2026-2027');

    expect($p14->numerotation_semestres)->toBe(NumerotationSemestres::MASTER)
        ->and(NumerotationSemestres::libelle(7, $p14))->toBe('Semestre 1')
        ->and(NumerotationSemestres::libelleCourt(8, $p14))->toBe('S2')
        ->and(NumerotationSemestres::libelleCourt(9, $p14))->toBe('S3')
        ->and(NumerotationSemestres::libelle(10, $p14))->toBe('Semestre 4')
        ->and(NumerotationSemestres::numero(9, $p14))->toBe(3)
        // Sans promotion connue : la numérotation par défaut.
        ->and(NumerotationSemestres::libelleCourt(7, null))->toBe('S1');
});

it('numérote S7 à S10 pour une promotion réglée en LMD, la maquette étant en 7-10 ou en 1-4', function (): void {
    $p14 = promotionNumerotation(14, '2026-2027');
    $p14->update(['numerotation_semestres' => NumerotationSemestres::LMD]);

    expect(array_map(fn (int $s): string => NumerotationSemestres::libelleCourt($s, $p14), [7, 8, 9, 10]))->toBe(['S7', 'S8', 'S9', 'S10'])
        ->and(array_map(fn (int $s): string => NumerotationSemestres::libelleCourt($s, $p14), [1, 2, 3, 4]))->toBe(['S7', 'S8', 'S9', 'S10'])
        ->and(NumerotationSemestres::libelle(8, $p14))->toBe('Semestre 8')
        ->and(NumerotationSemestres::numero(2, $p14))->toBe(8);
});

it('refuse en base une numérotation inconnue', function (): void {
    $p14 = promotionNumerotation(14, '2026-2027');

    expect(fn () => $p14->update(['numerotation_semestres' => 'licence']))->toThrow(QueryException::class);
});

it('rattache la maquette de M1 à la promotion de l\'année, celle de M2 à la promotion précédente', function (): void {
    $p13 = promotionNumerotation(13, '2025-2026');
    $p14 = promotionNumerotation(14, '2026-2027');
    CampagneCandidature::factory()->create(['nom' => ['fr' => 'Année académique 2026-2027'], 'promotion_numero' => 15]); // sans retenu : pas une promotion
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    $gtfp = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'GTFP'))->firstOrFail();

    expect(NumerotationSemestres::promotionDeMaquette($tc)?->id)->toBe($p14->id)
        ->and(NumerotationSemestres::promotionDeMaquette($gtfp)?->id)->toBe($p13->id)
        ->and(NumerotationSemestres::promotion('2030-2031', 'M1'))->toBeNull();
});

it('libelle les semestres proposés selon la promotion de chaque niveau', function (): void {
    promotionNumerotation(13, '2025-2026')->update(['numerotation_semestres' => NumerotationSemestres::LMD]);
    promotionNumerotation(14, '2026-2027');

    expect(NumerotationSemestres::options('2026-2027', [7, 8, 9, 10]))->toBe([7 => 'Semestre 1', 8 => 'Semestre 2', 9 => 'Semestre 9', 10 => 'Semestre 10'])
        ->and(NumerotationSemestres::options(null, [7, 8]))->toBe([7 => 'Semestre 1', 8 => 'Semestre 2']);
});

it('annonce le semestre hors 30 crédits dans la numérotation de la promotion', function (): void {
    $p14 = promotionNumerotation(14, '2026-2027');
    app(ImportMaquettes::class)->importer(json_decode((string) file_get_contents(resource_path('lmd/maquettes-2026-2027.json')), true), true);
    $tc = Maquette::query()->whereHas('parcours', fn ($q) => $q->where('code', 'TC'))->firstOrFail();
    $tc->ues()->where('semestre', 8)->orderBy('ordre')->firstOrFail()->update(['credits' => 1]);
    $messages = fn (): array => collect(app(ControleMaquette::class)->anomalies($tc->refresh()))->where('code', 'credits_semestre')->pluck('message')->all();

    expect($messages())->toHaveCount(1)
        ->and($messages()[0])->toStartWith('Le semestre 2 compte');

    $p14->update(['numerotation_semestres' => NumerotationSemestres::LMD]);
    expect($messages()[0])->toStartWith('Le semestre 8 compte');
});
