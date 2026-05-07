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
];
