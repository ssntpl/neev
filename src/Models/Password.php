<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

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
        'password' => 'hashed',
    ];

    public const UPDATED_AT = null;

    public static function checkPasswordWarning($user)
    {
        $currentPassword = static::latestForUser($user);
        if (!$currentPassword) {
            return false;
        }

        $softExpiryDays = config('neev.password_soft_expiry_days');
        if ($softExpiryDays && now()->gte($currentPassword->created_at->addDays((int) $softExpiryDays))) {
            return [
                'message' => 'Please change the password otherwise your account would be blocked. You have changed your password '.$currentPassword->created_at->diffForHumans(),
            ];
        }

        return $currentPassword ? [
            'message' => 'You have changed your password '.$currentPassword->created_at->diffForHumans(),
        ] : false;
    }

    public static function isLoginBlock($user)
    {
        $currentPassword = static::latestForUser($user);
        if (!$currentPassword) {
            return false;
        }

        $hardExpiryDays = config('neev.password_hard_expiry_days');

        return $hardExpiryDays && now()->gte($currentPassword->created_at->addDays((int) $hardExpiryDays));
    }

    protected static function latestForUser($user): ?self
    {
        if (!$user) {
            return null;
        }

        return static::where('user_id', $user->id)->orderByDesc('id')->first();
    }
}
