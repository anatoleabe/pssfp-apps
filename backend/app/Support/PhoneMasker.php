<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Masque un numéro E.164 pour l'écriture en logs / activity_log.
 *
 * Conserve l'indicatif et les 4 derniers chiffres : `+237***234567` -> `+237***4567`.
 * Permet à un opérateur de distinguer deux candidats sans exposer le numéro complet
 * dans les logs (PII minimisée — cf. spec module 5 §candidature-reviewer).
 */
final class PhoneMasker
{
    public static function mask(string $phoneE164): string
    {
        $digitsOnly = preg_replace('/\D/', '', $phoneE164) ?? '';

        if (strlen($digitsOnly) <= 4) {
            return str_repeat('*', max(0, strlen($digitsOnly)));
        }

        $cc = '';
        if (str_starts_with($phoneE164, '+')) {
            // Récupère l'indicatif jusqu'à 3 chiffres (suffisant pour 99% des pays).
            preg_match('/^\+(\d{1,3})/', $phoneE164, $m);
            $cc = '+'.($m[1] ?? '');
        }

        $last4 = substr($digitsOnly, -4);

        return $cc.'***'.$last4;
    }
}
