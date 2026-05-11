<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * @property int $id
 * @property int $user_id
 * @property string $code
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class RecoveryCode extends Model
{
    protected $fillable = [
        'user_id',
        'code',
    ];

    protected $hidden = [
        'code',
    ];

    protected $casts = [
        'code' => 'hashed',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function verify(string $otp): bool
    {
        return Hash::check($otp, $this->code);
    }
}
