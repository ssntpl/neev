<?php

use Ssntpl\Neev\Models\MultiFactorAuth;

return [
    'team' => false,
    'roles' => false,
    'email_verified' => false,
    'wrong_password_attempts' => 5,
    'home_url' => env('APP_URL'),
    'dashboard_url' => env('APP_URL').'/dashboard',
    'app_owner' => [
        'abhishek.sharma@ssntpl.in',
    ],

    'multi_factor_auth' => [
        MultiFactorAuth::authenticator(),
        MultiFactorAuth::email(),
    ],

    'recovery_codes' => 16,

    'oauth' => [
        'google' => true,
        'github' => true,
        'microsoft' => false,
        'apple' => false,
    ],

    'magicauth' => true,

    //Login History
    'last_login_history_in_days' => 30,
    'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),
];
