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
    'admin_candidature_url' => rtrim((string) env('ADMIN_CANDIDATURE_URL', 'https://api.pssfp.org/admin/candidatures'), '/'),

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
    // Plateforme FOAD (Moodle) : adresse rappelée aux auditeurs avec leurs identifiants.
    'foad_url' => rtrim((string) env('FOAD_URL', 'https://ecampus.pssfp.org'), '/'),

    // Création des comptes eCampus par les services web de Moodle. Sans jeton,
    // rien n'est appelé : les comptes passent par l'export Moodle.
    'ecampus' => [
        'jeton' => (string) env('ECAMPUS_JETON', ''),
        'delai_secondes' => (int) env('ECAMPUS_DELAI_SECONDES', 8),
        // Temps maximal consacré aux comptes dans une requête de l'admin.
        'budget_secondes' => (int) env('ECAMPUS_BUDGET_SECONDES', 20),
    ],

    // Secret partagé avec le portail candidature : authentifie l'en-tête qui
    // porte l'adresse IP réelle du visiteur (cf. IpVisiteurDuPortail).
    'portail_secret' => (string) env('PSSFP_PORTAIL_SECRET', ''),

    'otp' => [
        'cooldown_seconds' => (int) env('OTP_COOLDOWN_SECONDS', 60),
        'max_per_phone_per_hour' => (int) env('OTP_MAX_PER_PHONE_PER_HOUR', 5),
    ],
];
