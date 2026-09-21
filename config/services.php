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
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'webhook_secret' => env('PAYMONGO_WEBHOOK_SECRET'),
        'ca_bundle' => env('PAYMONGO_CA_BUNDLE'),
        'verify_ssl' => env('PAYMONGO_VERIFY_SSL', true),
    ],

    // Batch 15, Items 2/3: Firebase Cloud Messaging (push notifications).
    // Two supported sources, checked in this order by FcmService:
    //   1. FIREBASE_CREDENTIALS_JSON — the full service-account JSON as a
    //      single-line env var string. Preferred for Docker/production —
    //      passed through docker-compose.yml's environment whitelist like
    //      PAYMONGO_SECRET_KEY, never baked into the image.
    //   2. 'credentials' file path — for local host development, where
    //      dropping the downloaded JSON file into storage/app/private/
    //      (gitignored) is more convenient than a giant one-line env var.
    'firebase' => [
        'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),
        'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/private/firebase-service-account.json')),
    ],

];
