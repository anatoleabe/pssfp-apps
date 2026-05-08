<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Compteurs anti-bruteforce pour l'auth candidat (cf. ADR-0007 §M4 + ajout 2 plan PR B).
 *
 * Quatre compteurs distincts :
 * - login.ip       : 3 tentatives login / 10 min / IP            -> 429
 * - login.phone    : 10 échecs login   / 30 min / phone           -> 423 lockout
 * - login.ip.daily : 50 échecs cumulés / 24h / IP                 -> 403 ban
 * - otp.phone      : 5 tentatives verify-otp / 60 min / phone     -> 429
 *
 * Toutes les méthodes `assert*` lèvent une AuthThrottledException si la limite
 * est dépassée — capturée par l'ExceptionHandler et traduite en réponse HTTP.
 */
final class CandidatAuthThrottle
{
    private const LOGIN_IP_MAX = 3;

    private const LOGIN_IP_DECAY = 600;          // 10 min

    private const LOGIN_PHONE_MAX = 10;

    private const LOGIN_PHONE_DECAY = 1800;       // 30 min

    private const LOGIN_IP_DAILY_MAX = 50;

    private const LOGIN_IP_DAILY_DECAY = 86400;   // 24h

    private const OTP_PHONE_MAX = 5;

    private const OTP_PHONE_DECAY = 3600;         // 60 min

    /** @return array{kind: string, retry_after: int}|null */
    public function checkLogin(string $phoneE164, string $ip): ?array
    {
        if (RateLimiter::tooManyAttempts($this->loginIpDailyKey($ip), self::LOGIN_IP_DAILY_MAX)) {
            return ['kind' => 'ip_banned', 'retry_after' => RateLimiter::availableIn($this->loginIpDailyKey($ip))];
        }

        if (RateLimiter::tooManyAttempts($this->loginPhoneKey($phoneE164), self::LOGIN_PHONE_MAX)) {
            return ['kind' => 'phone_locked', 'retry_after' => RateLimiter::availableIn($this->loginPhoneKey($phoneE164))];
        }

        if (RateLimiter::tooManyAttempts($this->loginIpKey($ip), self::LOGIN_IP_MAX)) {
            return ['kind' => 'ip_throttled', 'retry_after' => RateLimiter::availableIn($this->loginIpKey($ip))];
        }

        return null;
    }

    public function recordLoginFailure(string $phoneE164, string $ip): void
    {
        RateLimiter::hit($this->loginIpKey($ip), self::LOGIN_IP_DECAY);
        RateLimiter::hit($this->loginPhoneKey($phoneE164), self::LOGIN_PHONE_DECAY);
        RateLimiter::hit($this->loginIpDailyKey($ip), self::LOGIN_IP_DAILY_DECAY);
    }

    public function clearLoginOnSuccess(string $phoneE164, string $ip): void
    {
        RateLimiter::clear($this->loginIpKey($ip));
        RateLimiter::clear($this->loginPhoneKey($phoneE164));
        // login.ip.daily n'est PAS clear : un attaquant qui réussit ne doit pas
        // remettre le compteur 24h à zéro pour repartir attaquer d'autres comptes.
    }

    /** @return array{kind: string, retry_after: int}|null */
    public function checkOtpVerify(string $phoneE164): ?array
    {
        if (RateLimiter::tooManyAttempts($this->otpPhoneKey($phoneE164), self::OTP_PHONE_MAX)) {
            return ['kind' => 'otp_throttled', 'retry_after' => RateLimiter::availableIn($this->otpPhoneKey($phoneE164))];
        }

        return null;
    }

    public function recordOtpAttempt(string $phoneE164): void
    {
        RateLimiter::hit($this->otpPhoneKey($phoneE164), self::OTP_PHONE_DECAY);
    }

    public function clearOtpCounters(string $phoneE164): void
    {
        RateLimiter::clear($this->otpPhoneKey($phoneE164));
    }

    private function loginIpKey(string $ip): string
    {
        return "candidat:login:ip:{$ip}";
    }

    private function loginPhoneKey(string $phone): string
    {
        return 'candidat:login:phone:'.sha1($phone);
    }

    private function loginIpDailyKey(string $ip): string
    {
        return "candidat:login:ip:{$ip}:24h";
    }

    private function otpPhoneKey(string $phone): string
    {
        return 'candidat:otp:phone:'.sha1($phone);
    }
}
