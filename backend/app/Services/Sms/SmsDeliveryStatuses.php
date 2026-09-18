<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Statuts de livraison rendus par les passerelles.
 *
 * Table unique : elle vivait en double, dans le provider et dans la colonne du
 * journal, avec deux jeux de valeurs différents — un message « pending »
 * s'affichait « pending » dans le tableau et « En attente » dans la
 * notification, au même instant et sur le même écran.
 *
 * Un statut absent de la table est rendu tel quel : la liste n'est pas
 * documentée exhaustivement et ne doit pas être devinée.
 */
final class SmsDeliveryStatuses
{
    public const LIVRE = 'delivered';

    /** @var array<string, string> */
    private const LIBELLES = [
        'delivered' => 'Livré',
        'success' => 'Envoyé',
        'failed' => 'Échec',
        'pending' => 'En attente',
    ];

    public static function libelle(?string $brut): string
    {
        $brut = trim((string) $brut);

        if ($brut === '') {
            return 'Inconnu';
        }

        return self::LIBELLES[mb_strtolower($brut)] ?? $brut;
    }

    public static function estLivre(?string $brut): bool
    {
        return mb_strtolower(trim((string) $brut)) === self::LIVRE;
    }

    /** Couleur de badge Filament associée au statut. */
    public static function couleur(?string $brut): string
    {
        return match (mb_strtolower(trim((string) $brut))) {
            'delivered' => 'success',
            'failed' => 'danger',
            '' => 'gray',
            default => 'warning',
        };
    }
}
