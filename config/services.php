<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
     * Ingredient images (TECHNICAL-DESIGN §10): Wikimedia Commons for generic photos and
     * Open Food Facts for packaged products. Neither needs an API key. Searches only run when
     * the user asks for them; the chosen image is copied locally with its attribution.
     */
    'ingredient_images' => [
        'enabled' => (bool) env('INGREDIENT_IMAGES_ENABLED', true),
        // Identifies the app to the providers (User-Agent), as both require. No personal data.
        'contact' => env('INGREDIENT_IMAGES_CONTACT', env('APP_URL', 'http://localhost')),
        'timeout' => (int) env('INGREDIENT_IMAGES_TIMEOUT', 6),
        'max_bytes' => 2 * 1024 * 1024,
    ],

];
