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
        'key'          => env('RESEND_API_KEY'),
        'api_key'      => env('RESEND_API_KEY'),
        'from_address' => env('RESEND_FROM_ADDRESS', 'noreply@example.com'),
        'from_name'    => env('RESEND_FROM_NAME', 'Wedding Vendor'),
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

    'openai' => [
        'key' => env('LLM_API_KEY', env('OPENAI_API_KEY', '')),
    ],

    'wa_gateway' => [
        'url'             => env('WA_GATEWAY_URL', 'http://wa-gateway:3001'),
        'secret'          => env('WA_INTERNAL_SECRET', ''),
        'internal_secret' => env('WA_INTERNAL_SECRET', ''),
        // Base URL for callbacks from wa-gateway back to Laravel. Inside docker, wa-gateway
        // cannot resolve APP_URL (e.g. http://localhost:8080); it must hit nginx on the
        // docker network instead.
        'callback_base'   => env('WA_GATEWAY_CALLBACK_BASE', 'http://nginx'),
    ],

    'calendar' => [
        'provider' => env('CALENDAR_PROVIDER', 'null'),
    ],

    'google_calendar' => [
        'base_url' => env('GOOGLE_CALENDAR_BASE_URL', 'https://www.googleapis.com/calendar/v3'),
    ],

    'google_oauth' => [
        'client_id'     => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri'  => env('GOOGLE_REDIRECT_URI', ''),
        'scopes'        => ['https://www.googleapis.com/auth/calendar.events'],
    ],

];
