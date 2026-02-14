<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Password extends Model
{
    protected $fillable = [
        'user_id',
        'password',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'password' => 'hashed'
    ];

    const UPDATED_AT = null;

    public static function checkPasswordWarning($user) {
        $password = config('neev');
        if (!$user || !$password) {
            return false;
        }

        $currentPassword = Password::where('user_id', $user->id)->orderByDesc('id')->first();
        if ($currentPassword && isset($password['password_soft_expiry_days']) && $password['password_soft_expiry_days']) {
            $softLimit = Carbon::parse($currentPassword->created_at)->addDays((int) $password['password_soft_expiry_days']);
            if (now()->greaterThanOrEqualTo($softLimit)) {
                return [
                    'message' => 'Please change the password otherwise your account would be blocked. You have changed your password '.$currentPassword->created_at->diffForHumans(),
                ];
            } else {
                return [
                    'message' => 'You have changed your password '.$currentPassword->created_at->diffForHumans()
                ];
            }
        }

        return false;
    }

    public static function isLoginBlock($user) {
        $password = config('neev');
        if (!$user || !$password) {
            return false;
        }

        $currentPassword = Password::where('user_id', $user->id)->orderByDesc('id')->first();
        if ($currentPassword && isset($password['password_hard_expiry_days']) && $password['password_hard_expiry_days']) {
            $hardLimit = Carbon::parse($currentPassword->created_at)->addDays((int) $password['password_hard_expiry_days']);
            if (now()->greaterThanOrEqualTo($hardLimit)) {
                return true;
            }
        }

        return false;
    }
}
