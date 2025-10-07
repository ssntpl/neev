<?php

use Illuminate\Validation\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;

return [
    'team' => false,
    'email_verified' => false,
    'domain_federation' => false,
    
    'team_model' => Ssntpl\Neev\Models\Team::class,
    'user_model' => Ssntpl\Neev\Models\User::class,
    'support_username' => false,

    'domain_rules' => [
       'mfa', // allow compalsory mfa
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

    'username' => [
        'required',
        'string',
        'min:3',
        'max:20',
        'regex:/^(?![._])(?!.*[._]{2})[a-zA-Z0-9._]+(?<![._])$/',
        'unique:users,username',
    ],

    'password' => [
        'required',
        'confirmed',
        Password::min(8)
                ->max(72)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols(),
        PasswordHistory::notReused(5),
        PasswordUserData::notContain(['name', 'email']),
    ],
    
    'login_soft_attempts' => 5,
    'login_hard_attempts' => 20,
    'login_block_minutes' => 1,
    'password_soft_expiry_days' => 30,
    'password_hard_expiry_days' => 90,
];
