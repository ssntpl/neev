<?php

namespace Ssntpl\Neev\Models;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use OTPHP\TOTP;
use Ssntpl\Neev\Mail\EmailOTP;

/**
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string|null $name
 * @property array $auth_config
 * @property string|null $secret
 * @property string|null $otp
 * @property string|null $email
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
        'name',
        'status',
        'auth_config',
        'last_used',
        'secret',
        'otp',
        'email',
        'expires_at',
    ];

    protected $hidden = [
        'auth_config',
    ];

    protected $appends = [
        'email',
    ];

    protected $casts = [
        'auth_config' => 'array',
        'last_used' => 'datetime',
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

    /**
     * TOTP secret for the authenticator method. Encrypted at rest inside
     * auth_config; transparently encrypted/decrypted here.
     */
    protected function secret(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->auth_config['secret'])
                ? Crypt::decryptString($this->auth_config['secret'])
                : null,
            set: fn ($value) => $this->mergeAuthConfig([
                'secret' => $value === null ? null : Crypt::encryptString($value),
            ]),
        );
    }

    /**
     * Hashed one-time password for the email method. Stored as a one-way
     * hash; verify with verifyOTP()/Hash::check().
     */
    protected function otp(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->auth_config['otp'] ?? null,
            set: fn ($value) => $this->mergeAuthConfig([
                'otp' => $value === null ? null : Hash::make($value),
            ]),
        );
    }

    /**
     * Destination address for the email method. A user may register an email
     * other than their account email; OTPs are delivered here.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->auth_config['email'] ?? null,
            set: fn ($value) => $this->mergeAuthConfig(['email' => $value]),
        );
    }

    /**
     * Expiry of the current email OTP.
     */
    protected function expiresAt(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->auth_config['expires_at'])
                ? Carbon::parse($this->auth_config['expires_at'])
                : null,
            set: fn ($value) => $this->mergeAuthConfig([
                'expires_at' => $value instanceof \DateTimeInterface
                    ? $value->format('c')
                    : $value,
            ]),
        );
    }

    /**
     * Merge values into auth_config, returning the attribute payload Eloquent
     * expects from an attribute mutator.
     *
     * The value is pre-encoded to JSON because values returned from an
     * attribute mutator are merged straight into the raw attributes array,
     * bypassing the `auth_config` array cast's own serialization. The cast
     * still decodes it on read.
     */
    protected function mergeAuthConfig(array $values): array
    {
        $current = $this->attributes['auth_config'] ?? [];
        if (is_string($current)) {
            $current = json_decode($current, true) ?: [];
        }

        return ['auth_config' => json_encode(array_merge($current, $values))];
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

    /**
     * Generate a fresh OTP for this email instance and mail it to the
     * instance's destination address (falling back to the user's account
     * email for legacy rows that never stored one).
     */
    public function sendEmailOtp(User $user): void
    {
        $length = config('neev.otp_length', 6);
        $otp = (string) random_int(10 ** ($length - 1), (10 ** $length) - 1);
        $expiryMinutes = config('neev.otp_expiry_time', 15);

        $this->otp = $otp;
        $this->expires_at = now()->addMinutes($expiryMinutes);
        $this->save();

        Mail::to($this->email ?? $user->email)->send(new EmailOTP($user->name, $otp, $expiryMinutes));
    }
}
