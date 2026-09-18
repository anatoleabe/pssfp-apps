<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Codes d'erreur applicatifs de TechSoft Bulk SMS v3.
 *
 * Extraits de la documentation authentifiée (section Error Reference) et
 * vérifiés contre l'API le 18 septembre 2026. Un code absent de cette table
 * rend `null` : la liste n'est pas garantie exhaustive, et un code inconnu
 * doit être affiché brut plutôt que masqué derrière un libellé inventé.
 */
final class TechSoftCodes
{
    /** @var array<string, string> */
    private const MESSAGES = [
        '104' => 'Solde SMS insuffisant',
        '109' => 'Le jeton API n\'a pas la permission demandée',
        '113' => 'Paramètre de requête invalide',
        '401' => 'Jeton API refusé',
        '403' => 'Accès refusé par la passerelle',
        '404' => 'Ressource introuvable chez la passerelle',
        '405' => 'Méthode HTTP non supportée par la passerelle',
        '422' => 'Requête rejetée par la validation de la passerelle',
        '500' => 'Erreur interne de la passerelle',
    ];

    public static function libelle(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::MESSAGES[$code] ?? null;
    }
}
