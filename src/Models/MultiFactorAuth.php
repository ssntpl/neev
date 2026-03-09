<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string|null $secret
 * @property string|null $otp
 * @property bool $preferred
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class MultiFactorAuth extends Model
{
    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'otp',
        'expires_at',
        'last_used',
        'preferred',
    ];

    protected $hidden = [
        'secret',
        'otp',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_used' => 'datetime',
        'secret' => 'encrypted',
        'otp' => 'hashed',
        'preferred' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }
}
