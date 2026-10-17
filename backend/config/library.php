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
