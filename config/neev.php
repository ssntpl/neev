<?php

use Ssntpl\Neev\Models\DomainRule;

return [
    'team' => false,
    'email_verified' => false,
    'domain_federation' => false,
    
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,

    'support_username' => false,
    'domain_rules' => [
        DomainRule::mfa(), // allow compalsory mfa
        DomainRule::passkey(), // member may add passkeys
        DomainRule::oauth(),
        DomainRule::pass_min_len(),
        DomainRule::pass_max_len(),
        DomainRule::pass_old(),
        DomainRule::pass_soft_fail_attempts(),
        DomainRule::pass_block_user_mins(),
        DomainRule::pass_hard_fail_attempts(),
        DomainRule::pass_combinations(),
        DomainRule::pass_columns(),
    ],

    'dashboard_url' => env('APP_URL').'/dashboard',

    'multi_factor_auth' => [
        'authenticator',
        'email',
    ],

    'recovery_codes' => 16,

    'oauth' => [
        // 'google',
        // 'github',
        // 'microsoft',
        // 'apple',
    ],

    'magicauth' => true,

    //Login History
    'last_login_history_in_days' => 30,
    'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),

    'password' => [
        'min_length' => 8,
        'max_length' => 72,
        'combination_types' => [], //['alphabet', 'number', 'symbols'],
        'check_user_columns' => ['name', 'email'],
        'old_passwords' => 5,
        
        'soft_fail_attempts' => 5,
        'hard_fail_attempts' => 20,
        'login_block_minutes' => 1,
        'password_expiry_soft_days' => 30,
        'password_expiry_hard_days' => 90,
    ],
];
