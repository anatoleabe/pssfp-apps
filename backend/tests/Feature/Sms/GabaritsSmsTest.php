<?php

declare(strict_types=1);

uses()->group('sms');

/**
 * Les gabarits SMS doivent rester en ASCII et sous 160 caractères.
 *
 * Un accent force le type `unicode` chez TechSoft, qui decoupe alors tous les
 * 70 caracteres au lieu de 160 : un message de 135 caracteres passe de un a
 * deux segments, soit 24 au lieu de 12. A credit constant, cela divise par
 * deux le nombre de candidats joignables.
 */
function gabaritsSms(): array
{
    $numero = 'P14026-277';
    $url = 'apply.pssfp.org';
    $cloture = '30 septembre 2026';

    $listener = file_get_contents(base_path('app/Listeners/SendCandidatureSmsNotifications.php'));
    preg_match_all("/'(PSSFP : [^']+)'/", $listener, $m);

    $gabarits = [];
    foreach ($m[1] as $i => $texte) {
        $gabarits["listener #{$i}"] = str_replace('%s', $numero, $texte);
    }

    foreach ((array) config('relance_sms.messages') as $cle => $texte) {
        $gabarits["relance {$cle}"] = str_replace(
            [':url', ':date_cloture'],
            [$url, $cloture],
            (string) $texte,
        );
    }

    return $gabarits;
}

it('n\'utilise aucun caractère accentué dans les gabarits SMS', function (): void {
    $fautifs = [];
    foreach (gabaritsSms() as $nom => $texte) {
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $texte) === 1) {
            $fautifs[] = $nom.' → '.$texte;
        }
    }

    // Un accent forcerait le type unicode et doublerait le coût du message.
    expect($fautifs)->toBe([]);
});

it('garde chaque gabarit SMS sous la limite d\'un segment', function (): void {
    $trop_longs = [];
    foreach (gabaritsSms() as $nom => $texte) {
        if (mb_strlen($texte) > 160) {
            $trop_longs[] = $nom.' → '.mb_strlen($texte).' caractères';
        }
    }

    expect($trop_longs)->toBe([]);
});

it('n\'affiche plus de numéro de téléphone dans les messages aux candidats', function (): void {
    // Retiré le 26/10/2026 à la demande du chef de projet : le candidat est
    // renvoyé vers son espace en ligne, pas vers une ligne téléphonique.
    $avec_numero = [];
    foreach (gabaritsSms() as $nom => $texte) {
        if (preg_match('/\d{3} ?\d{2} ?\d{2} ?\d{2}/', $texte) === 1) {
            $avec_numero[] = $nom;
        }
    }

    expect($avec_numero)->toBe([]);
});
