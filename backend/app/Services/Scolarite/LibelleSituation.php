<?php

declare(strict_types=1);

namespace App\Services\Scolarite;

use App\Models\InscriptionAnnuelle;

/** Libellé et couleur de la situation d'un auditeur, partout où elle s'affiche. */
final class LibelleSituation
{
    public const SANS_INSCRIPTION = 'sans_inscription';

    public const NON_SOLDEE = 'non_soldee';

    public const EN_RETARD = 'en_retard';

    public const A_JOUR = 'a_jour';

    public const SOLDEE = 'soldee';

    public const LIBELLES = [
        self::SANS_INSCRIPTION => 'Barème manquant',
        self::NON_SOLDEE => 'Inscription non soldée',
        self::EN_RETARD => 'En retard',
        self::A_JOUR => 'À jour',
        self::SOLDEE => 'Soldé',
    ];

    public static function cle(?InscriptionAnnuelle $inscription): string
    {
        if ($inscription === null) {
            return self::SANS_INSCRIPTION;
        }
        $s = $inscription->situation();

        return match (true) {
            $s->solde() => self::SOLDEE,
            ! $s->inscriptionSoldee() => self::NON_SOLDEE,
            $s->enRetard() => self::EN_RETARD,
            default => self::A_JOUR,
        };
    }

    public static function libelle(?InscriptionAnnuelle $inscription): string
    {
        return self::LIBELLES[self::cle($inscription)];
    }

    public static function couleur(string $cle): string
    {
        return match ($cle) {
            self::SOLDEE, self::A_JOUR => 'success',
            self::EN_RETARD => 'warning',
            self::NON_SOLDEE => 'danger',
            default => 'gray',
        };
    }

    public static function fcfa(int $montant): string
    {
        return number_format($montant, 0, ',', "\u{202F}").' FCFA';
    }
}
