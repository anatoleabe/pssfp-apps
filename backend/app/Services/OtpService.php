<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\OtpCooldownException;
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
     * Garde-fous par numéro (LOT B.4, indépendants du throttle IP) :
     * cooldown entre deux envois + plafond horaire glissant. Protège le
     * crédit SMS d'un numéro ciblé depuis des IP tournantes.
     *
     * @return string Le code en clair, à transmettre au SMS provider.
     *
     * @throws OtpCooldownException numéro en cooldown ou au plafond horaire.
     */
    public function generate(
        string $phone,
        string $purpose,
        int $ttlMinutes = 10,
        ?string $ip = null,
        ?string $userAgent = null,
    ): string {
        return $this->db->transaction(function () use ($phone, $purpose, $ttlMinutes, $ip, $userAgent): string {
            $this->assertNotThrottled($phone);

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
     * Cooldown + plafond horaire par numéro (tous purposes confondus —
     * c'est le crédit SMS du numéro qu'on protège, pas un flux précis).
     *
     * @throws OtpCooldownException
     */
    private function assertNotThrottled(string $phone): void
    {
        $cooldownSeconds = max(0, (int) config('pssfp.otp.cooldown_seconds', 60));
        $maxPerHour = max(1, (int) config('pssfp.otp.max_per_phone_per_hour', 5));

        if ($cooldownSeconds > 0) {
            $lastCreatedAt = SmsOtp::query()
                ->where('phone_e164', $phone)
                ->latest('id')
                ->value('created_at');

            if ($lastCreatedAt !== null) {
                $elapsed = (int) abs(now()->diffInSeconds($lastCreatedAt));
                if ($elapsed < $cooldownSeconds) {
                    throw new OtpCooldownException($cooldownSeconds - $elapsed, 'cooldown');
                }
            }
        }

        $lastHourCount = SmsOtp::query()
            ->where('phone_e164', $phone)
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($lastHourCount >= $maxPerHour) {
            throw new OtpCooldownException(3600, 'hourly_cap');
        }
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
