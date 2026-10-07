<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Traits\VerifiesWithDns;

/**
 * A host an owner is served at (RFC 006): transport, not membership. The
 * owner is any model: a team, a tenant, or one of the application's own.
 *
 * Only custom hosts are stored. A platform subdomain is the owner's slug under
 * `neev.platform_domain` and is derived, never written here. A host is unique
 * across every owner, so resolving one is deterministic.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $host
 * @property string $status
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $verification_failed_at
 * @property-read Model $owner
 */
class Hostname extends Model
{
    use VerifiesWithDns;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'host',
        'status',
        'verification_token',
        'verified_at',
        'verification_failed_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'verification_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // TenantResolver caches which owner a host resolves to. A changed host
        // leaves its old name cached too.
        static::saved(function (Hostname $hostname) {
            Cache::forget(static::cacheKey($hostname->host));

            if ($hostname->wasChanged('host') && $hostname->getOriginal('host')) {
                Cache::forget(static::cacheKey($hostname->getOriginal('host')));
            }
        });
        static::deleted(fn (Hostname $hostname) => Cache::forget(static::cacheKey($hostname->host)));
    }

    /**
     * The cache key TenantResolver keeps a host's resolution under.
     */
    public static function cacheKey(string $host): string
    {
        return 'neev:hostname:' . static::canonicalHost($host);
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    protected function hostColumn(): string
    {
        return 'host';
    }

    protected function dnsRecordPrefix(): string
    {
        return '_neev-host';
    }

    public function setHostAttribute(?string $value): void
    {
        $this->canonicaliseHostAttribute($value);
    }

    /**
     * Whether this row is held by the owner it names.
     */
    public function isOwnedBy(Model $owner): bool
    {
        return $this->owner_type === $owner->getMorphClass()
            && (int) $this->owner_id === (int) $owner->getKey();
    }

    /**
     * The owner of a kind (morph type) a verified host serves, if any.
     */
    public static function ownerOf(string $host, string $ownerType): ?Model
    {
        return static::forHost($host)->verified()->where('owner_type', $ownerType)->first()?->owner;
    }

    /**
     * Delete this row, unpointing its owner's primary from it first so the
     * owner falls back to its next host rather than a missing one.
     */
    public function release(): void
    {
        DB::transaction(function () {
            $owner = $this->owner()->withoutGlobalScopes()->first();

            if ($owner !== null && (int) $owner->getAttribute('primary_hostname_id') === (int) $this->getKey()) {
                $owner->forceFill(['primary_hostname_id' => null])->save();
            }

            $this->delete();
        });
    }
}
