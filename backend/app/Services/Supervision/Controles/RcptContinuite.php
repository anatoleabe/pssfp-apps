<?php

declare(strict_types=1);

namespace App\Services\Supervision\Controles;

use App\Services\Supervision\Controle;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation continue des pièces (spec de l'espace Finances §10, RCPT_CONTINUITE) : pour chaque année civile, aucun
 * numéro manquant entre 1 et le dernier attribué par le compteur, et un compteur égal au plus grand numéro émis. Reçus
 * (RCPT-AAAA-NNNNNN) et pièces de correction (COR-AAAA-NNNNN). Un écart trahit une écriture hors de l'application.
 */
final class RcptContinuite implements Controle
{
    /** Pièce => [table des compteurs, table des pièces, préfixe]. */
    private const PIECES = [
        'Reçus' => ['compteurs_recus', 'recus_versements', 'RCPT'],
        'Pièces de correction' => ['compteurs_corrections', 'corrections_versements', 'COR'],
    ];

    public function code(): string
    {
        return 'RCPT_CONTINUITE';
    }

    public function libelle(): string
    {
        return 'Numérotation des reçus et des pièces de correction sans trou';
    }

    public function domaine(): string
    {
        return 'Scolarité';
    }

    public function criticite(): string
    {
        return self::BLOQUANT;
    }

    public function delaiJours(): int
    {
        return 1;
    }

    public function responsable(): string
    {
        return 'admin';
    }

    public function cas(): array
    {
        $cas = [];
        foreach (self::PIECES as $libelle => [$compteurs, $table, $prefixe]) {
            foreach (DB::table($compteurs)->orderBy('annee')->get(['annee', 'dernier']) as $compteur) {
                $sequences = DB::table($table)->where('annee_numero', $compteur->annee)->orderBy('sequence')->pluck('sequence')->map(fn ($n): int => (int) $n)->all();
                $manquants = array_values(array_diff(range(1, max(1, (int) $compteur->dernier)), $sequences));
                if ((int) $compteur->dernier === 0) {
                    $manquants = [];
                }
                $plusGrand = $sequences === [] ? 0 : max($sequences);
                if ($manquants === [] && $plusGrand === (int) $compteur->dernier) {
                    continue;
                }
                $cas[$prefixe.'-'.$compteur->annee] = [
                    'resume' => $libelle.' '.$compteur->annee.' : '
                        .($manquants === [] ? '' : count($manquants).' numéro(s) manquant(s), dont '.implode(', ', array_slice($manquants, 0, 5)).' ; ')
                        .'compteur à '.$compteur->dernier.', plus grand numéro émis '.$plusGrand,
                ];
            }
        }

        return $cas;
    }
}
