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

    /*
     * Stripe (S6) — compte de la PLATEFORME.
     *  - Abonnement des cabinets : prix par siège (STRIPE_PRICE_SEAT).
     *  - Paiement des factures clients : Stripe Connect, l'argent va au compte
     *    Stripe du cabinet (charges « destination » avec on_behalf_of).
     * Sans STRIPE_SECRET, les écrans de paiement indiquent que Stripe n'est
     * pas configuré (aucune requête n'est envoyée).
     */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_seat' => env('STRIPE_PRICE_SEAT'),
        'trial_days' => (int) env('SUBSCRIPTION_TRIAL_DAYS', 30),
        'platform_fee_percent' => (float) env('STRIPE_PLATFORM_FEE_PERCENT', 0),
    ],

    /*
     * Google Agenda (S7) — OAuth par membre du personnel : les créneaux occupés
     * de son agenda bloquent la réservation, et chaque rendez-vous y est ajouté
     * avec un lien Google Meet. Sans GOOGLE_CLIENT_ID, la fonction est masquée.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8000'), '/') . '/api/google/callback'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
