<?php

namespace Ssntpl\Neev\Models;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use OTPHP\TOTP;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string|null $secret
 * @property string|null $otp
 * @property bool $preferred
 * @property string $status
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon|null $last_used
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class MultiFactorAuth extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'user_id',
        'method',
        'secret',
        'otp',
        'expires_at',
        'last_used',
        'preferred',
        'status',
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

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public static function getQrCodeForAuthenticatorSetup(string $secret, string $email): string
    {
        $totp = TOTP::create($secret);
        $totp->setLabel($email);
        $totp->setIssuer(config('app.name', 'Neev'));

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd()
        ));

        return $writer->writeString($totp->getProvisioningUri());
    }

    public static function verifyAuthenticatorOTP(string $secret, string $otp): bool
    {
        return TOTP::create($secret)->verify($otp, null, 29);
    }

    public function verifyOTP(string $otp): bool
    {
        $verified = match ($this->method) {
            'authenticator' => $this->secret !== null
                && self::verifyAuthenticatorOTP($this->secret, $otp),
            'email' => $this->verifyEmailOTP($otp),
            default => false,
        };

        if (!$verified) {
            return false;
        }

        if ($this->method === 'email') {
            $this->otp = null;
            $this->expires_at = null;
        }
        if ($this->status === self::STATUS_PENDING) {
            $this->status = self::STATUS_ACTIVE;
        }
        $this->last_used = now();
        $this->save();

        return true;
    }

    protected function verifyEmailOTP(string $otp): bool
    {
        return $this->otp !== null
            && Hash::check($otp, $this->otp)
            && $this->expires_at !== null
            && now()->lt($this->expires_at);
    }
}
