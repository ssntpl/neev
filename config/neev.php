<?php

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
