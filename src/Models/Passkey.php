<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string $public_key
 * @property string|null $name
 * @property string|null $ip
 * @property array<string, mixed>|null $location
 * @property \Carbon\Carbon|null $last_used
 * @property string $aaguid
 * @property array<int, string>|null $transports
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Passkey extends Model
{
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'name',
        'ip',
        'location',
        'last_used',
        'aaguid',
        'transports',
    ];

    protected $hidden = [
        'public_key',
    ];

    protected $casts = [
        'transports' => 'array',
        'location' => 'array',
        'last_used' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }
}
