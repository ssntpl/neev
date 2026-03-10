<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Validation\Rule;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Traits\BelongsToTenant;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $email
 * @property bool $is_primary
 * @property \Carbon\Carbon|null $verified_at
 * @property-read User|null $user
 * @property-read OTP|null $otp
 */
class Email extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'email',
        'is_primary',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'is_primary' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function otp(): MorphOne
    {
        return $this->morphOne(OTP::class, 'owner');
    }

    /**
     * Find an email record by address, respecting tenant isolation.
     * The TenantScope global scope handles tenant filtering automatically.
     */
    public static function findByEmail(string $email): ?static
    {
        return static::query()->where('email', $email)->first();
    }

    /**
     * Get a unique validation rule that respects tenant isolation.
     * Laravel's unique rule bypasses Eloquent global scopes,
     * so we must add the tenant_id constraint explicitly.
     *
     * @param int|null $ignoreId  Row ID to ignore (for updates)
     */
    public static function uniqueRule(?int $ignoreId = null): \Illuminate\Contracts\Validation\Rule|string
    {
        $rule = Rule::unique('emails', 'email');

        if ($ignoreId) {
            $rule->ignore($ignoreId);
        }

        if (app()->bound(TenantResolver::class)) {
            $resolver = app(TenantResolver::class);
            if ($resolver->hasTenant()) {
                $rule->where('tenant_id', $resolver->currentId());
            }
        }

        return $rule;
    }
}
