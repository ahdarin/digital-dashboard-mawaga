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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'url' => env('FONNTE_API_URL', 'https://api.fonnte.com/send'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

    // Global Meta App credentials doang - token milik masing-masing client
    // TIDAK disimpan di sini, cuma di api_integrations.access_token
    // (encrypted, per client) hasil OAuth connect.
    'instagram' => [
        'client_id' => env('INSTAGRAM_CLIENT_ID'),
        'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
        'api_version' => env('INSTAGRAM_API_VERSION'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
    ],

    // Global TikTok for Developers App credentials doang - token milik
    // masing-masing client TIDAK di sini, cuma di api_integrations.access_token
    // (encrypted, per client) hasil OAuth connect. Base API selalu
    // https://open.tiktokapis.com/v2/... - TikTok tidak punya konsep
    // "api_version" yang dipilih bebas seperti Meta Graph API, jadi tidak
    // ada key versi di sini (beda dari config('services.instagram.api_version')).
    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'redirect' => env('TIKTOK_REDIRECT_URI'),
    ],

];
