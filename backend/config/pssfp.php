<?php

declare(strict_types=1);

return [
    'filament' => [
        'require_2fa' => filter_var(env('FILAMENT_REQUIRE_2FA', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'admin_seeder' => [
        'email' => env('FILAMENT_ADMIN_EMAIL'),
        'password' => env('FILAMENT_ADMIN_PASSWORD'),
    ],

    // URL publique de l'app candidature Next.js — utilisée dans les emails
    // pour renvoyer le candidat vers son dossier / suivi.
    'candidature_app_url' => rtrim((string) env('CANDIDATURE_APP_URL', 'https://apply.pssfp.org'), '/'),

    // Frais de dossier (FCFA) payables en agence CREMINCAM (cf. spec §8).
    'frais_dossier_fcfa' => (int) env('FRAIS_DOSSIER_FCFA', 50000),

    // Campagne Promotion 14 en production (LOT B.1). Les dates sont une
    // décision de la direction — le seeder refuse de tourner sans elles.
    'campagne_p14' => [
        'opens_at' => env('CAMPAGNE_P14_OPENS_AT'),
        'closes_at' => env('CAMPAGNE_P14_CLOSES_AT'),
        'results_at' => env('CAMPAGNE_P14_RESULTS_AT'),
    ],

    // Garde-fous OTP SMS (LOT B.4) : protègent le crédit SMS d'un numéro
    // ciblé depuis des IP tournantes (le throttle IP ne suffit pas, et le
    // Turnstile est fail-open sur panne Cloudflare).
    'otp' => [
        'cooldown_seconds' => (int) env('OTP_COOLDOWN_SECONDS', 60),
        'max_per_phone_per_hour' => (int) env('OTP_MAX_PER_PHONE_PER_HOUR', 5),
    ],
];
