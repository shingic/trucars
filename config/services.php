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

    // The $150 reservation deposit (App\Support\Deposits). The publishable key
    // goes to the browser; the secret and webhook signing secret never do.
    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Until Platform settings → Payments says otherwise. Demo takes no money.
        'default_mode'   => env('PAYMENTS_DEFAULT_MODE', 'demo'),
    ],

    // CARFAX Canada vehicle history on the vehicle page (App\Services\Carfax).
    // Each dealership's own CARFAX account id is set in Command Centre ›
    // Dealer › Integrations. These are the platform's API credentials.
    // driver: 'null' (off), 'fake' (sample data, local only), 'carfax_canada'.
    'carfax' => [
        'driver'   => env('CARFAX_DRIVER', 'null'),
        'base_url' => env('CARFAX_BASE_URL'),
        'api_key'  => env('CARFAX_API_KEY'),
        'timeout'  => (int) env('CARFAX_TIMEOUT', 4),
    ],

    // Address suggestions at checkout (App\Support\AddressLookup), via Google
    // Places API (New). Without a key the address fields are plain inputs.
    'google_places' => [
        'key' => env('GOOGLE_PLACES_KEY'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
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

];
