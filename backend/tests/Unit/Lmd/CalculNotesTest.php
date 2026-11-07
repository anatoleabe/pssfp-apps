<?php

declare(strict_types=1);

use App\Models\MaquetteEcue;
use App\Models\MaquetteUe;
use App\Models\NoteEcue;
use App\Models\RegleEvaluation;
use App\Models\UniteEnseignement;
use App\Services\Lmd\CalculNotes;

uses()->group('lmd');

/** UE de deux ECUE (3 et 1 crédits), pondérations 30 / 15 / 55, en mémoire. */
function ueEssai(): MaquetteUe
{
    $ue = new MaquetteUe(['credits' => 4, 'pcc' => '0.3000', 'ptp' => '0.1500', 'pexam' => '0.5500']);
    $ue->setRelation('ue', new UniteEnseignement(['code' => 'UE1', 'intitule' => 'Essai']));
    $a = (new MaquetteEcue(['code' => 'A', 'credits' => 3]))->forceFill(['id' => 1]);
    $b = (new MaquetteEcue(['code' => 'B', 'credits' => 1]))->forceFill(['id' => 2]);
    $ue->setRelation('ecues', collect([$a, $b]));

    return $ue;
}

function note(?float $cc, ?float $ass, ?float $exam, ?string $presence = null): NoteEcue
{
    $s = fn (?float $v): ?string => $v === null ? null : number_format($v, 2, '.', '');

    return (new NoteEcue)->forceFill(['cc' => $s($cc), 'assiduite' => $s($ass), 'examen' => $s($exam), 'presence_examen' => $presence ?? ($exam === null ? null : 'present')]);
}

function regle(array $valeurs = []): RegleEvaluation
{
    return new RegleEvaluation([...RegleEvaluation::valeursParDefaut('2026-2027'), ...$valeurs]);
}

it('calcule la moyenne d\'un ECUE et la note d\'UE pondérée par les crédits', function (): void {
    // A : 14×0,30 + 16×0,15 + 12×0,55 = 13,2 ; B : 10×0,30 + 10×0,15 + 8×0,55 = 8,9 ; UE : (13,2×3 + 8,9) / 4 = 12,125
    $r = app(CalculNotes::class)->ue(ueEssai(), regle(), [1 => note(14, 16, 12), 2 => note(10, 10, 8)]);

    expect($r->ecues[0]->moyenne)->toBe(13.2)
        ->and($r->ecues[1]->moyenne)->toBe(8.9)
        ->and($r->note)->toBe(12.125)
        ->and($r->examen)->toBe(11.0)
        ->and($r->complete)->toBeTrue()
        ->and($r->acquise)->toBeTrue()
        ->and($r->eligibleRattrapage)->toBeFalse();
});

it('refuse une UE à 12 de moyenne avec un examen d\'UE sous 7 (note éliminatoire)', function (): void {
    // examen d'UE = (6×3 + 6) / 4 = 6 ; moyenne = (20×0,30 + 20×0,15 + 6×0,55) = 12,3
    $r = app(CalculNotes::class)->ue(ueEssai(), regle(), [1 => note(20, 20, 6), 2 => note(20, 20, 6)]);

    expect($r->note)->toBe(12.3)->and($r->eliminatoire)->toBeTrue()->and($r->acquise)->toBeFalse()->and($r->eligibleRattrapage)->toBeTrue();
    expect(app(CalculNotes::class)->ue(ueEssai(), regle(['note_plancher' => null]), [1 => note(20, 20, 6), 2 => note(20, 20, 6)])->acquise)->toBeTrue();
});

it('porte la note éliminatoire sur chaque ECUE quand la règle le dit', function (): void {
    $notes = [1 => note(14, 14, 14), 2 => note(14, 14, 6)];

    expect(app(CalculNotes::class)->ue(ueEssai(), regle(), $notes)->eliminatoire)->toBeFalse()
        ->and(app(CalculNotes::class)->ue(ueEssai(), regle(['note_plancher_porte' => RegleEvaluation::PLANCHER_EXAMEN_ECUE]), $notes)->eliminatoire)->toBeTrue();
});

it('compte 0 pour un absent à l\'examen, et marque incomplète une note manquante', function (): void {
    $r = app(CalculNotes::class)->ue(ueEssai(), regle(), [1 => note(14, 16, null, 'absent_non_justifie')]);

    expect($r->ecues[0]->moyenne)->toBe(6.6)
        ->and($r->ecues[0]->complete)->toBeTrue()
        ->and($r->ecues[1]->complete)->toBeFalse()
        ->and($r->complete)->toBeFalse()
        ->and($r->acquise)->toBeFalse()
        ->and($r->eligibleRattrapage)->toBeFalse();
    expect(app(CalculNotes::class)->ue(ueEssai(), regle(), [1 => note(14, 16, null, 'absent_non_justifie')], [], true)->eligibleRattrapage)->toBeTrue();
});

it('applique les trois modes de rattrapage', function (string $mode, float $moyenneA, bool $acquise): void {
    // Normale : A = 10×0,30 + 10×0,15 + 8×0,55 = 8,9 ; rattrapage de A : 14. B = 13,2 inchangé.
    $r = app(CalculNotes::class)->ue(ueEssai(), regle(['mode_rattrapage' => $mode]),
        [1 => note(10, 10, 8), 2 => note(14, 16, 12)], [1 => note(null, null, 14)]);

    expect($r->ecues[0]->moyenneRetenue)->toBe($moyenneA)
        ->and($r->ecues[1]->moyenneRetenue)->toBe(13.2)
        ->and($r->rattrapageSaisi)->toBeTrue()
        ->and($r->acquiseEnNormale)->toBeFalse()
        ->and($r->acquise)->toBe($acquise);
})->with([
    'remplace la moyenne' => [RegleEvaluation::RATTRAPAGE_REMPLACE_MOYENNE, 14.0, true],    // (14×3 + 13,2) / 4 = 13,8
    'remplace l\'examen' => [RegleEvaluation::RATTRAPAGE_REMPLACE_EXAMEN, 12.2, true],      // 3 + 1,5 + 7,7 = 12,2 ; UE 12,45
    'meilleure note' => [RegleEvaluation::RATTRAPAGE_MEILLEURE_NOTE, 12.2, true],
]);

it('n\'acquiert pas une UE dont le rattrapage reste sous le seuil', function (): void {
    $r = app(CalculNotes::class)->ue(ueEssai(), regle(), [1 => note(10, 10, 8), 2 => note(10, 10, 8)], [1 => note(null, null, 11), 2 => note(null, null, 11)]);

    expect($r->note)->toBe(11.0)->and($r->acquise)->toBeFalse();
});
