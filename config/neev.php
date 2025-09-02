<?php

use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\User;

return [
    'team' => false,
    'roles' => false,
    'email_verified' => false,
    'domain_federation' => false,
    
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'support_username' => false,
    'domain_rules' => [
        DomainRule::mfa(),
        DomainRule::passkey(),
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

    'home_url' => env('APP_URL'),
    'dashboard_url' => env('APP_URL').'/dashboard',

    'multi_factor_auth' => [
        MultiFactorAuth::authenticator(),
        MultiFactorAuth::email(),
    ],

    'recovery_codes' => 16,

    'oauth' => [
        User::google(),
        User::github(),
    ],

    'magicauth' => true,

    //Login History
    'last_login_history_in_days' => 30,
    'geo_ip_db' => 'app/geoip/GeoLite2-City.mmdb',
    'edition' => env('MAXMIND_EDITION', 'GeoLite2-City'),
    'maxmind_license_key' => env('MAXMIND_LICENSE_KEY'),

    'password' => [
        'min_length' => 4,
        'max_length' => 16,
        'combination_types' => ['number'], //['alphabet', 'number', 'symbols'],
        'check_user_columns' => ['name', 'email'],
        'old_passwords' => 5,
        
        'soft_fail_attempts' => 5,
        'hard_fail_attempts' => 20,
        'login_block_minutes' => 1,
        'password_expiry_soft_days' => 30,
        'password_expiry_hard_days' => 90,
    ],
];
