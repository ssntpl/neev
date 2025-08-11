<?php

use Ssntpl\Neev\Models\MultiFactorAuth;

return [
    'team' => false,
    'roles' => false,
    'stack' => 'ui',
    'email_verified' => false,
    
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

    'password' => [
        'min_length' => 4,
        'max_length' => 8,
        'combination_types' => ['number'], //['alphabet', 'number', 'symbols'],
        'check_user_columns' => ['name', 'email'],
        'old_passwords' => 5,
        
        'soft_fail_attempts' => 2,
        'hard_fail_attempts' => 20,
        'login_block_minutes' => 5,
        'password_expiry_soft_days' => 60,
        'password_expiry_hard_days' => 90,
    ],
];
