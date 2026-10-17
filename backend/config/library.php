<?php

declare(strict_types=1);

/*
 * Bibliothèque virtuelle (Module 3) — cf. docs/specs/module-3-bibliotheque-v1-2026-10.md.
 */
return [
    // PDF privés ; jamais d'URL publique directe.
    'documents_disk' => env('LIBRARY_DOCUMENTS_DISK', 'minio_documents'),

    // Couvertures (images publiques).
    'covers_disk' => env('LIBRARY_COVERS_DISK', 'minio_media'),
    'covers_directory' => 'library/covers',

    'signed_url_minutes' => 30,

    /*
     * Débit par IP et par minute (limiteurs nommés, cf. AppServiceProvider::boot()).
     * Chaque limiteur a son propre compteur : une recherche compte dans `library`
     * et dans `search`, jamais deux fois dans le même.
     */
    'rate_limits' => [
        'library' => (int) env('LIBRARY_RATE_LIMIT', 120),
        'search' => (int) env('LIBRARY_SEARCH_RATE_LIMIT', 60),
        'download' => (int) env('LIBRARY_DOWNLOAD_RATE_LIMIT', 30),
        'view' => (int) env('LIBRARY_VIEW_RATE_LIMIT', 20),
    ],

    // IP jamais limitées (liste séparée par des virgules) : en prod, l'IP publique du VPS,
    // d'où partent les appels SSR/ISR du serveur Next.js pour tous les visiteurs.
    'rate_limit_exempt_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('LIBRARY_RATE_LIMIT_EXEMPT_IPS', '')),
    ))),

    'per_page' => 20,
    'max_per_page' => 50,

    // Clés de tri acceptées par l'API → colonne et sens.
    'sorts' => [
        'recent' => ['published_at', 'desc'],
        'ancien' => ['year', 'asc'],
        'titre' => ['slug', 'asc'],
        'populaire' => ['views_count', 'desc'],
    ],
];
