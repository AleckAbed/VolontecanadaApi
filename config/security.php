<?php

return [

    /*
    | Analyse antivirus de chaque fichier reçu (ClamAV, démon clamd en TCP).
    | En production : CLAMAV_HOST renseigné et CLAMAV_REQUIRED=true. Si l'antivirus est
    | injoignable, le téléversement est alors refusé plutôt qu'accepté sans analyse.
    */
    'antivirus' => [
        'host' => env('CLAMAV_HOST'),
        'port' => (int) env('CLAMAV_PORT', 3310),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 30),
        'required' => (bool) env('CLAMAV_REQUIRED', false),
        // Taille maximale envoyée à clamd (StreamMaxLength de clamd, 25 Mo par défaut).
        'max_bytes' => (int) env('CLAMAV_MAX_BYTES', 25 * 1024 * 1024),
    ],

    /*
    | En-têtes de sécurité de l'API. HSTS seulement en HTTPS (production).
    */
    'headers' => [
        'hsts' => (bool) env('SECURITY_HSTS', env('APP_ENV') === 'production'),
    ],

    /*
    | Double authentification exigée par défaut pour le personnel des NOUVEAUX cabinets.
    | Le cabinet peut ensuite la retirer dans ses réglages de sécurité.
    */
    'two_factor_default' => (bool) env('SECURITY_REQUIRE_2FA_NEW_CABINETS', true),

    /*
    | Ouverture des cabinets : sur demande (formulaire public, validée dans la console
    | plateforme) par défaut. L'inscription libre et immédiate (/api/cabinets/register)
    | ne s'ouvre qu'avec CABINET_SELF_SIGNUP=true.
    */
    'cabinet_self_signup' => (bool) env('CABINET_SELF_SIGNUP', false),

];
