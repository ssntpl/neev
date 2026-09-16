<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Database\Factories\MagicLinkTokenFactory;
use Ssntpl\Neev\Traits\BelongsToTenant;

/**
 * Stateful, single-use magic-link token.
 *
 * Only the SHA-256 hash of the opaque token is persisted (column: token).
 * Single-use is enforced by deleting the row on consumption, so there are no
 * consumed/revoked timestamps. Auxiliary data (e.g. the browser/device binding
 * fingerprint) lives in the JSON `meta_data` column.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $tenant_id
 * @property string $token  SHA-256 hash of the plain token.
 * @property string $channel
 * @property array<string, mixed>|null $meta_data
 * @property string|null $user_agent
 * @property string|null $created_ip
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class MagicLinkToken extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected static function newFactory()
    {
        return MagicLinkTokenFactory::new();
    }

    protected $fillable = [
        'user_id',
        'tenant_id',
        'token',
        'channel',
        'meta_data',
        'user_agent',
        'created_ip',
        'expires_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'meta_data' => 'array',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    // -----------------------------------------------------------------
    // Token helpers
    // -----------------------------------------------------------------

    /**
     * Create a new opaque, high-entropy plain-text token.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Deterministically hash a plain token for storage and lookup.
     */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Find a token row by its plain-text value (matches on the stored hash).
     */
    public static function findByToken(string $plain): ?self
    {
        return static::query()->where('token', static::hashToken($plain))->first();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return !$this->isExpired();
    }

    /**
     * The stored browser/device binding fingerprint, if any.
     */
    public function fingerprint(): ?string
    {
        return $this->meta_data['fingerprint'] ?? null;
    }

    /**
     * Scope: tokens that are still active (not yet expired). Consumed/revoked
     * tokens no longer exist — they are deleted on use.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
