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
    'candidature_app_url' => rtrim((string) env('CANDIDATURE_APP_URL', 'https://candidature.pssfp.net'), '/'),

    // Frais de dossier (FCFA) payables en agence CREMINCAM (cf. spec §8).
    'frais_dossier_fcfa' => (int) env('FRAIS_DOSSIER_FCFA', 50000),
];
