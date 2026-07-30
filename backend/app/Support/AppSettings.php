<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

/**
 * Accès en lecture/écriture aux réglages éditables depuis l'admin.
 *
 * Les valeurs sont mises en cache car elles sont lues à chaque soumission de
 * candidature. Toute écriture invalide l'entrée correspondante.
 */
final class AppSettings
{
    /** Adresses mises en copie cachée de la notification « candidature soumise ». */
    public const KEY_CANDIDATURE_NOTIFICATION_BCC = 'candidature.notification_bcc';

    private const CACHE_PREFIX = 'app_settings:';

    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $value = Cache::rememberForever(
                self::CACHE_PREFIX.$key,
                static fn () => AppSetting::query()->where('key', $key)->value('value'),
            );
        } catch (QueryException) {
            // Table absente (migration pas encore jouée) : on ne casse jamais un
            // envoi d'email pour un réglage optionnel.
            return $default;
        }

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $userId = null): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $userId],
        );

        Cache::forget(self::CACHE_PREFIX.$key);
    }

    public static function forget(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX.$key);
    }

    /**
     * Liste nettoyée des adresses en copie cachée des notifications de
     * candidature. Les entrées invalides sont ignorées silencieusement plutôt
     * que de faire échouer la notification du candidat.
     *
     * @return list<string>
     */
    public static function candidatureNotificationBcc(): array
    {
        $raw = self::get(self::KEY_CANDIDATURE_NOTIFICATION_BCC, []);

        if (! is_array($raw)) {
            return [];
        }

        $emails = [];

        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $email = mb_strtolower(trim($entry));

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $email;
            }
        }

        return array_values(array_unique($emails));
    }
}
