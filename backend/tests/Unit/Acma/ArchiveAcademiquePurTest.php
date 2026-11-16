<?php

declare(strict_types=1);

use App\Services\Acma\ComparaisonIdentite;
use App\Services\Acma\NormalisationAcma;
use App\Services\Acma\Reprise\EtapeResultats;

/* Lot 15b : normalisation des identités, comparaison R1 à R6 et calcul selon ACMA (classes pures, données fictives). */

it('normalise sexe, noms, intitulés et semestres', function (): void {
    expect(NormalisationAcma::sexe('H'))->toBe('M')
        ->and(NormalisationAcma::sexe(' f '))->toBe('F')
        ->and(NormalisationAcma::sexe(''))->toBeNull()
        ->and(NormalisationAcma::nomComplet('  essai   alpha béta '))->toBe('ESSAI ALPHA BÉTA')
        ->and(NormalisationAcma::intitule('Finances publiques (Public finance)'))->toBe(['fr' => 'Finances publiques', 'en' => 'Public finance'])
        ->and(NormalisationAcma::intitule('Comptabilité publique'))->toBe(['fr' => 'Comptabilité publique', 'en' => null])
        ->and(NormalisationAcma::semestre('M1', '2'))->toBe(2)
        ->and(NormalisationAcma::semestre('M1', '3'))->toBeNull()
        ->and(NormalisationAcma::semestre('M2', '1'))->toBe(3)
        ->and(NormalisationAcma::semestre('M2', '4'))->toBe(4)
        ->and(NormalisationAcma::semestre('M2', '7'))->toBeNull();
});

it('lit les dates de naissance : valide, aberrante, vide, « né vers »', function (?string $date, ?string $type, ?string $texte, ?string $attendue, ?int $annee, ?string $precision, bool $aVerifier): void {
    $n = NormalisationAcma::dateNaissance($date, $type, $texte);
    expect($n['date'])->toBe($attendue)->and($n['annee'])->toBe($annee)->and($n['precision'])->toBe($precision)->and($n['a_verifier'])->toBe($aVerifier);
})->with([
    'valide' => ['1985-03-12', '1', null, '1985-03-12', 1985, null, false],
    'année 12' => ['0012-01-01', '1', null, null, null, null, true],
    'zéros' => ['0000-00-00', '1', null, null, null, null, true],
    'jour zéro' => ['2021-06-00', '1', null, null, null, null, true],
    'vide' => [null, '1', null, null, null, null, true],
    'trop récente' => ['2015-01-01', '1', null, null, null, null, true],
    'né vers' => ['1975-01-01', '3', '1975', null, 1975, 'vers', false],
]);

it('compare les noms par jetons, sans accents ni ordre, au seuil de 0,66', function (): void {
    $a = NormalisationAcma::jetons('ESSAI ALPHA BÉTA');
    expect($a)->toBe(['ESSAI', 'ALPHA', 'BETA'])
        ->and(NormalisationAcma::jetons('Béta', 'alpha', null))->toBe(['BETA', 'ALPHA'])
        ->and(NormalisationAcma::jetons('N. DE LA ROCHE'))->toBe(['DE', 'LA', 'ROCHE'])
        ->and(ComparaisonIdentite::score($a, ['ALPHA', 'BETA']))->toBe(1.0)
        ->and(ComparaisonIdentite::score(['A1', 'BB', 'CC'], ['BB', 'CC', 'DD']))->toBe(0.667)
        ->and(ComparaisonIdentite::compatibles(0.667))->toBeTrue()
        ->and(ComparaisonIdentite::compatibles(0.5))->toBeFalse()
        ->and(ComparaisonIdentite::score([], ['X']))->toBe(0.0);
});

it('classe les cas R1 à R4 et R6', function (): void {
    expect(ComparaisonIdentite::memeMatricule('1985-03-12', '1985-03-12', 1.0))->toBe('R1')
        ->and(ComparaisonIdentite::memeMatricule(null, '1985-03-12', 0.7))->toBe('R2')
        ->and(ComparaisonIdentite::memeMatricule('1985-03-12', '1985-03-12', 0.3))->toBe('R3')
        ->and(ComparaisonIdentite::memeMatricule(null, '1985-03-12', 0.3))->toBe('R3')
        ->and(ComparaisonIdentite::memeMatricule('1985-03-12', '1986-03-12', 1.0))->toBe('R4')
        ->and(ComparaisonIdentite::autreMatricule('1985-03-12', '1985-03-12', 1.0))->toBeTrue()
        ->and(ComparaisonIdentite::autreMatricule('1985-03-12', '1985-03-12', 0.9))->toBeFalse()
        ->and(ComparaisonIdentite::autreMatricule(null, null, 1.0))->toBeFalse()
        ->and(ComparaisonIdentite::LIENS_AUTOMATIQUES)->toBe(['R1', 'R2']);
});

it('calcule la moyenne selon ACMA : pondérations, rattrapage appliqué, notes hors bornes', function (): void {
    $unite = (object) ['pcc' => '0.3', 'ptp' => '0', 'pexam' => '0.7', 'credits' => 30];

    expect(EtapeResultats::calculer([14.0, 0.0, 13.0, null], 0, $unite))->toMatchArray(['moyenne' => 13.3, 'acquise' => true, 'session' => 'normale', 'credits' => 30])
        ->and(EtapeResultats::calculer([8.0, 0.0, 9.0, 14.0], 0, $unite))->toMatchArray(['moyenne' => 8.7, 'rattrapage_applique' => false])
        ->and(EtapeResultats::calculer([8.0, 0.0, 9.0, 12.5], 1, $unite))->toMatchArray(['moyenne' => 12.5, 'session' => 'rattrapage', 'acquise' => true])
        ->and(EtapeResultats::calculer([25.0, 0.0, 10.0, null], 0, $unite))->toMatchArray(['cc' => null, 'hors_bornes' => true, 'moyenne' => 7.0])
        ->and(EtapeResultats::calculer([null, null, null, null], 0, $unite))->toMatchArray(['moyenne' => null, 'acquise' => null])
        ->and(EtapeResultats::calculer([12.0, 0.0, 12.0, null], 0, $unite)['acquise'])->toBeTrue()
        ->and(EtapeResultats::calculer([20.0, 20.0, 20.0, null], 0, (object) ['pcc' => '0.5', 'ptp' => '0.2', 'pexam' => '0.5', 'credits' => 22])['moyenne'])->toBeNull();
});
