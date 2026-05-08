<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SmsOtp;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;

/**
 * Génération et validation des codes OTP à 6 chiffres pour les candidats.
 *
 * - generate() invalide tous les OTP précédents non consommés du même
 *   (phone, purpose), puis crée un nouveau code aléatoire et persiste son hash.
 * - validate() vérifie le dernier OTP actif (non expiré, non utilisé,
 *   non annulé) et applique le compteur d'attempts pour empêcher le brute force.
 * - invalidatePending() est utilisé après un reset PIN réussi pour neutraliser
 *   les OTP dormants (cf. ajout 4 plan PR B).
 * - purgeExpired() purge les OTP > 7 jours via la commande artisan otp:purge.
 */
final class OtpService
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * Génère un OTP à 6 chiffres et persiste son hash.
     *
     * @return string Le code en clair, à transmettre au SMS provider.
     */
    public function generate(
        string $phone,
        string $purpose,
        int $ttlMinutes = 10,
        ?string $ip = null,
        ?string $userAgent = null,
    ): string {
        return $this->db->transaction(function () use ($phone, $purpose, $ttlMinutes, $ip, $userAgent): string {
            // Annule les OTP encore actifs du même couple (phone, purpose)
            // — limite la fenêtre où plusieurs codes simultanés seraient valides.
            SmsOtp::query()
                ->where('phone_e164', $phone)
                ->where('purpose', $purpose)
                ->whereNull('used_at')
                ->whereNull('cancelled_at')
                ->update(['cancelled_at' => now()]);

            $code = (string) random_int(100000, 999999);

            SmsOtp::create([
                'phone_e164' => $phone,
                'code_hash' => Hash::make($code),
                'purpose' => $purpose,
                'expires_at' => now()->addMinutes($ttlMinutes),
                'ip' => $ip,
                'user_agent' => $userAgent,
            ]);

            return $code;
        });
    }

    /**
     * Valide un code soumis par le candidat.
     *
     * Codes de retour :
     * - ok         : OTP correct et consommé (used_at = now()).
     * - wrong_code : OTP existe mais code faux (attempts incrémenté).
     * - expired    : OTP expiré (TTL dépassé).
     * - exhausted  : nombre max de tentatives sur le même OTP atteint.
     * - already_used : OTP déjà consommé (rejeu).
     * - not_found  : aucun OTP actif pour ce phone+purpose.
     */
    public function validate(string $phone, string $code, string $purpose): string
    {
        return $this->db->transaction(function () use ($phone, $code, $purpose): string {
            $latest = SmsOtp::query()
                ->where('phone_e164', $phone)
                ->where('purpose', $purpose)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($latest === null) {
                return 'not_found';
            }

            if ($latest->isUsed()) {
                return 'already_used';
            }

            if ($latest->isCancelled()) {
                return 'not_found';
            }

            if ($latest->isExpired()) {
                return 'expired';
            }

            if ($latest->isExhausted()) {
                return 'exhausted';
            }

            if (! Hash::check($code, $latest->code_hash)) {
                $latest->increment('attempts');

                return 'wrong_code';
            }

            $latest->update(['used_at' => now()]);

            return 'ok';
        });
    }

    /**
     * Invalide tous les OTP encore actifs (non utilisés, non annulés, non expirés)
     * pour un phone+purpose donné. Appelé après un reset PIN réussi.
     */
    public function invalidatePending(string $phone, string $purpose): int
    {
        return SmsOtp::query()
            ->where('phone_e164', $phone)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now()]);
    }

    /**
     * Supprime les OTP expirés depuis plus de 7 jours. Appelé par cron.
     */
    public function purgeExpired(int $olderThanDays = 7): int
    {
        return SmsOtp::query()
            ->where('expires_at', '<', now()->subDays($olderThanDays))
            ->delete();
    }
}
