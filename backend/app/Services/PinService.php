<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonInterface;

/**
 * Politique de PIN candidat (ADR-0007).
 *
 * Règles obligatoires à l'inscription et au reset :
 * 1. Format strict [0-9]{6}.
 * 2. Pas dans la blacklist (config/pin_blacklist.php — ~50 PIN courants).
 * 3. Pas les 6 derniers chiffres du numéro de téléphone du candidat.
 * 4. Pas la date de naissance au format DDMMYY ou YYMMDD (si fournie).
 *
 * Le service retourne un array {ok, reasons[]} pour permettre au FormRequest
 * d'agréger plusieurs erreurs en un seul retour à l'UI.
 */
final class PinService
{
    /**
     * @return array{ok: bool, reasons: string[]}
     */
    public function validate(string $pin, string $phoneE164, ?CarbonInterface $dateNaissance = null): array
    {
        $reasons = [];

        if (! preg_match('/^\d{6}$/', $pin)) {
            $reasons[] = 'pin.invalid_format';

            // Si le format est invalide, les autres vérifications n'apportent rien.
            return ['ok' => false, 'reasons' => $reasons];
        }

        if ($this->isBlacklisted($pin)) {
            $reasons[] = 'pin.blacklisted';
        }

        if ($this->matchesPhoneSuffix($pin, $phoneE164)) {
            $reasons[] = 'pin.matches_phone_suffix';
        }

        if ($dateNaissance !== null && $this->matchesDateOfBirth($pin, $dateNaissance)) {
            $reasons[] = 'pin.matches_date_of_birth';
        }

        return [
            'ok' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    private function isBlacklisted(string $pin): bool
    {
        $blacklist = (array) config('pin_blacklist', []);

        return in_array($pin, $blacklist, true);
    }

    private function matchesPhoneSuffix(string $pin, string $phoneE164): bool
    {
        // Conserve uniquement les chiffres pour gérer formats type +237 691 234 567.
        $digits = preg_replace('/\D/', '', $phoneE164) ?? '';

        if (strlen($digits) < 6) {
            return false;
        }

        return substr($digits, -6) === $pin;
    }

    private function matchesDateOfBirth(string $pin, CarbonInterface $dob): bool
    {
        $ddmmyy = $dob->format('dmy');
        $yymmdd = $dob->format('ymd');

        return $pin === $ddmmyy || $pin === $yymmdd;
    }
}
