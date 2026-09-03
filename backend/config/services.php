<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'sms' => [
        // Provider actif : 'fake' (logs) ou 'africas_talking' (Phase prod).
        'provider' => env('SMS_PROVIDER', 'fake'),
    ],

    // Passerelle SMS Echo SMS (https://account.echosms.io).
    // Attention : /sent/compose authentifie par `api_key` en QUERY, pas par
    // Bearer. La clé ne vit que dans .env, jamais dans le dépôt.
    'echosms' => [
        'base_url' => env('ECHOSMS_BASE_URL', 'https://account.echosms.io/api'),
        'api_key' => env('ECHOSMS_API_KEY'),
        // 'sender_id' (masking, ex. PSSFP) ou 'phone_number'.
        'from_type' => env('ECHOSMS_FROM_TYPE', 'sender_id'),
        'sender_id' => env('ECHOSMS_SENDER_ID'),
        'from_number' => env('ECHOSMS_FROM_NUMBER'),
    ],

    'africas_talking' => [
        'username' => env('AFRICAS_TALKING_USERNAME'),
        'api_key' => env('AFRICAS_TALKING_API_KEY'),
        'sender_id' => env('AFRICAS_TALKING_SENDER_ID', 'PSSFP'),
    ],

    // Cloudflare Turnstile (captcha anti-robot RGPD-friendly). Si `secret` est
    // vide, la vérification est désactivée (dev/tests) — cf. TurnstileVerifier.
    'turnstile' => [
        'secret' => env('TURNSTILE_SECRET_KEY'),
    ],

];
