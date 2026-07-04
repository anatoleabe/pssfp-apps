<?php

declare(strict_types=1);

return [
    /*
     * CORS — autorise les 3 apps Next.js (frontend, library, candidature)
     * en local et leurs équivalents production sur les sous-domaines pssfp.org.
     * Cf. ADR-0005 — Sanctum tokens scoped + cookies cross-domain.
     */

    'paths' => ['v1/*', 'sanctum/csrf-cookie', 'login', 'logout'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter([
        env('APP_ENV') !== 'production' ? 'http://localhost:6001' : null,
        env('APP_ENV') !== 'production' ? 'http://localhost:6002' : null,
        env('APP_ENV') !== 'production' ? 'http://localhost:6003' : null,
        env('APP_ENV') !== 'production' ? 'http://127.0.0.1:6001' : null,
        env('APP_ENV') !== 'production' ? 'http://127.0.0.1:6002' : null,
        env('APP_ENV') !== 'production' ? 'http://127.0.0.1:6003' : null,
        'https://pssfp.org',
        'https://www.pssfp.org',
        'https://bibliotheque.pssfp.org',
        'https://apply.pssfp.org',
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Idempotency-Key', 'X-Request-Id'],

    'max_age' => 86400,

    'supports_credentials' => true,
];
