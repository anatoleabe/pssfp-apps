<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;

/**
 * Solvabilité d'un auditeur sur tout son parcours (décisions du chef de projet, 17/11/2026) :
 *
 * - **échu impayé** : reste des droits d'inscription (dus dès l'inscription) et des tranches dont l'échéance est
 *   passée, toutes années confondues (les arriérés sont échus) ;
 * - tout l'argent saisi compte, la part à confirmer comprise (comme le tableau de bord) ;
 * - **en règle** : rien d'échu impayé ; **partiellement insolvable** : un échu impayé, mais un versement au moins ;
 *   **totalement insolvable** : un échu impayé et aucun versement.
 *
 * Lit les sommes préchargées des inscriptions (`withSum` des imputations, barème) : aucune requête par ligne.
 */
final class Solvabilite
{
    public const EN_REGLE = 'en_regle';

    public const PARTIELLE = 'partiellement_insolvable';

    public const TOTALE = 'totalement_insolvable';

    public const LIBELLES = [
        self::EN_REGLE => 'En règle',
        self::PARTIELLE => 'Partiellement insolvable',
        self::TOTALE => 'Totalement insolvable',
    ];

    public static function couleur(string $categorie): string
    {
        return match ($categorie) {
            self::EN_REGLE => 'success',
            self::PARTIELLE => 'warning',
            default => 'danger',
        };
    }

    /** Échu impayé d'une inscription : droits d'inscription restants, et tranches échues restantes. */
    public static function echuImpaye(SituationFinanciere $situation): int
    {
        // Année suspendue sur demande de l'auditeur : son reste n'est plus réclamé, donc jamais échu.
        if ($situation->suspendue) {
            return 0;
        }
        $echu = 0;
        foreach ($situation->paliers as $palier) {
            if ($palier->cle === Palier::INSCRIPTION || $palier->enRetard) {
                $echu += $palier->reste;
            }
        }

        return $echu;
    }

    /**
     * @param  iterable<InscriptionAnnuelle>  $inscriptions  toutes les inscriptions de l'auditeur
     * @param  int  $verseTotal  versements valides de l'auditeur (avance comprise)
     * @return array{categorie: string, du: int, verse: int, a_confirmer: int, reste: int, echu_impaye: int}
     */
    public static function classer(iterable $inscriptions, int $verseTotal): array
    {
        $du = $impute = $aConfirmer = $reste = $echu = 0;
        foreach ($inscriptions as $inscription) {
            $s = $inscription->situation();
            $du += $s->duTotal;
            $impute += $s->verse;
            $aConfirmer += $s->aConfirmer;
            $reste += $s->reste;
            $echu += self::echuImpaye($s);
        }
        $verse = max($verseTotal, $impute);

        return [
            'categorie' => match (true) {
                $echu === 0 => self::EN_REGLE,
                $verse > 0 => self::PARTIELLE,
                default => self::TOTALE,
            },
            'du' => $du, 'verse' => $verse, 'a_confirmer' => $aConfirmer, 'reste' => $reste, 'echu_impaye' => $echu,
        ];
    }
}
