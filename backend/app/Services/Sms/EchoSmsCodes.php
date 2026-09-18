<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Codes d'erreur applicatifs d'Echo SMS.
 *
 * Extraits du provider pour être réutilisables : le journal des envois doit
 * pouvoir traduire un code stocké sans instancier la passerelle, y compris
 * après la bascule vers TechSoft. Un code inconnu rend `null`.
 */
final class EchoSmsCodes
{
    /** @var array<string, string> */
    private const MESSAGES = [
        '1001' => 'Utilisateur introuvable',
        '1002' => 'Sender ID / masking invalide',
        '1003' => 'API introuvable (mauvais endpoint ou authentification refusée)',
        '1004' => 'Numéro WhatsApp invalide',
        '1005' => 'Numéro émetteur invalide',
        '1007' => 'Solde insuffisant',
        '1008' => 'Message vide',
        '1009' => 'Type de message non défini',
        '1010' => 'Numéro invalide',
        '1011' => 'Crédit insuffisant',
        '1013' => 'Numéro introuvable, contacter l\'administrateur',
        '1014' => 'Passerelle SMS non configurée côté Echo SMS',
        '1015' => 'Message envoyé partiellement',
        '1016' => 'Message envoyé',
        '1017' => 'Aucun forfait actif sur le compte',
        '1018' => 'OTP non activé',
        '1019' => 'Fournisseur inactif, contacter l\'administrateur',
        '1020' => 'Identifiants du fournisseur non configurés',
        '1021' => 'Modèle introuvable',
    ];

    public static function libelle(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::MESSAGES[$code] ?? null;
    }
}
