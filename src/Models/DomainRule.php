<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class DomainRule extends Model
{ 
    private static $ruleTypes = [
        'mfa' => 'bool',
        'passkey' => 'bool',
        'pass_min_len' => 'number',
        'pass_max_len' => 'number',
        'pass_old' => 'number',
        'pass_soft_fail_attempts' => 'number',
        'pass_hard_fail_attempts' => 'number',
        'pass_block_user_mins' => 'number',
        'pass_combinations' => 'select',
        'oauth' => 'select',
        'pass_columns' => 'array',
    ];
    
    private static $ruleTypesUI = [
        'oauth' => 'OAuth',
        'mfa' => 'Multi-factor Auth',
        'passkey' => 'Passkey',
        'pass_min_len' => 'Password minimum length',
        'pass_max_len' => 'Password maximum length',
        'pass_old' => 'Old Passwords Check',
        'pass_soft_fail_attempts' => 'Fail attempts allowed for temporary block',
        'pass_hard_fail_attempts' => 'Fail attempts allowed for permanent block',
        'pass_block_user_mins' => 'Block user if wrong login attempts in minutes',
        'pass_combinations' => 'Password combination types',
        'pass_columns' => 'User columns should not contain in password',
    ];
    
    private static $ruleConfigKey = [
        'oauth' => 'oauth',
        'mfa' => 'multi_factor_auth',
        'pass_min_len' => 'password.min_length',
        'pass_max_len' => 'password.max_length',
        'pass_old' => 'password.old_passwords',
        'pass_soft_fail_attempts' => 'password.soft_fail_attempts',
        'pass_hard_fail_attempts' => 'password.hard_fail_attempts',
        'pass_block_user_mins' => 'password.login_block_minutes',
        'pass_combinations' => 'password.combination_types',
        'pass_columns' => 'password.check_user_columns',
    ];
    
    protected $fillable = [
        'team_id',
        'name',
        'value',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public static function oauth() {
        return 'oauth';
    }

    public static function mfa() {
        return 'mfa';
    }

    public static function passkey() {
        return 'passkey';
    }

    public static function pass_min_len() {
        return 'pass_min_len';
    }

    public static function pass_max_len() {
        return 'pass_max_len';
    }

    public static function pass_old() {
        return 'pass_old';
    }

    public static function pass_soft_fail_attempts() {
        return 'pass_soft_fail_attempts';
    }

    public static function pass_hard_fail_attempts() {
        return 'pass_hard_fail_attempts';
    }

    public static function pass_block_user_mins() {
        return 'pass_block_user_mins';
    }

    public static function pass_combinations() {
        return 'pass_combinations';
    }

    public static function pass_columns() {
        return 'pass_columns';
    }

    public static function ruleType($name) {
        return self::$ruleTypes[$name];
    }

    public static function option($name) {
        $options = [
            'pass_combinations' => ['alphabet', 'number', 'symbols'],
            'oauth' => config('neev.oauth'),
        ];
        return $options[$name];
    }

    public static function ruleTypeUI($name) {
        return self::$ruleTypesUI[$name];
    }

    public static function ruleDefaultValue($name) {
        if ($name === self::passkey()) {
            return true;
        }
        $key = self::$ruleConfigKey[$name];
        $value = config("neev.$key") ?? null;
        if ($value === null) {
            return null;
        }
        if ($value && self::ruleType($name) === 'bool') {
            $value = (bool) $value;
        } elseif ($value && self::ruleType($name) === 'number') {
            $value = (int) $value;
        } else {
            $value = json_encode($value);
        }
        return $value;
    }
}
