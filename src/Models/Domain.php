<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property bool $enforce
 * @property string $domain
 * @property string|null $verification_token
 * @property bool $is_primary
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon|null $verification_failed_at
 * @property-read Model|null $owner
 */
class Domain extends Model
{
    protected static function booted(): void
    {
        static::saved(function (Domain $domain) {
            Cache::forget("neev:domain:{$domain->domain}");
        });

        static::deleted(function (Domain $domain) {
            Cache::forget("neev:domain:{$domain->domain}");
        });
    }

    protected $fillable = [
        'owner_id',
        'owner_type',
        'enforce',
        'domain',
        'verification_token',
        'is_primary',
        'verified_at',
        'verification_failed_at',
    ];

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'enforce' => 'boolean',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'verification_failed_at' => 'datetime',
    ];

    public function owner()
    {
        return $this->morphTo();
    }

    public function rules()
    {
        return $this->hasMany(DomainRule::class);
    }

    public function rule($name)
    {
        return $this->rules()->where('name', $name)->first();
    }

    /**
     * Check if this domain is verified.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Find a verified domain by host.
     * Used for tenant routing - matches any verified domain.
     */
    public static function findByHost(string $host): ?self
    {
        return static::where('domain', $host)
            ->whereNotNull('verified_at')
            ->first();
    }

    /**
     * Find the primary verified domain by host.
     */
    public static function findPrimaryByHost(string $host): ?self
    {
        return static::where('domain', $host)
            ->whereNotNull('verified_at')
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Find a verified domain by host for a specific owner.
     */
    public static function findByHostForOwner(string $host, string $ownerType, int $ownerId): ?self
    {
        return static::where('domain', $host)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->whereNotNull('verified_at')
            ->first();
    }

    /**
     * Mark this domain as the primary domain for its owner.
     */
    public function markAsPrimary(): void
    {
        // Unset other primary domains for this owner
        static::where('owner_type', $this->owner_type)
            ->where('owner_id', $this->owner_id)
            ->where('id', '!=', $this->id)
            ->update(['is_primary' => false]);

        $this->is_primary = true;
        $this->save();
    }

    /**
     * Generate a verification token for this domain.
     */
    public function generateVerificationToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->verification_token = $token;
        $this->save();

        return $token;
    }

    /**
     * Verify the domain via DNS TXT record lookup.
     * Returns true if the DNS record matches the verification token.
     */
    public function verify(): bool
    {
        $records = @dns_get_record($this->getDnsRecordName(), DNS_TXT) ?: [];
        $matched = collect($records)->contains(fn ($r) => ($r['txt'] ?? '') === $this->verification_token);

        if ($matched) {
            $wasFailingVerification = $this->verification_failed_at !== null;
            $isFirstVerification = $this->verified_at === null;

            $this->verified_at = now();
            $this->verification_failed_at = null;
            $this->save();

            if ($isFirstVerification) {
                event(new \Ssntpl\Neev\Events\DomainVerified($this));
            } elseif ($wasFailingVerification) {
                event(new \Ssntpl\Neev\Events\DomainReverified($this));
            }

            return true;
        }

        if ($this->verified_at !== null && $this->verification_failed_at === null) {
            event(new \Ssntpl\Neev\Events\DomainVerificationFailed($this));
        }

        $this->verification_failed_at = now();
        $this->save();

        return false;
    }

    /**
     * Check if this domain has a verification failure.
     */
    public function hasVerificationFailure(): bool
    {
        return $this->verification_failed_at !== null;
    }

    /**
     * Check if the domain verification is stale (older than the given number of days).
     */
    public function isVerificationStale(int $days): bool
    {
        return $this->verified_at !== null && $this->verified_at->diffInDays(now()) > $days;
    }

    /**
     * Get the DNS TXT record name for domain verification.
     */
    public function getDnsRecordName(): string
    {
        return '_neev-verification.' . $this->domain;
    }
}
