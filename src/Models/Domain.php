<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Ssntpl\Neev\Support\PlatformHost;
use Ssntpl\Neev\Traits\CanonicalisesHost;

/**
 * A row of the old `domains` table, kept for one release so an application can
 * read its rows while copying them into `hostnames` and `email_domains`
 * (RFC 006). The table is read-only: saving or deleting a Domain throws. The
 * static helpers answer from the new models. Both are removed in the next
 * release.
 *
 * @deprecated Use Hostname for a host an owner is served at, and EmailDomain
 *             for a domain whose users join it.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property int|null $owner_id
 * @property bool $enforce
 * @property string $domain
 * @property string|null $verification_token
 * @property bool $is_primary
 * @property Carbon|null $verified_at
 * @property Carbon|null $verification_failed_at
 * @property-read Model|null $owner
 */
class Domain extends Model
{
    use CanonicalisesHost;

    protected static function booted(): void
    {
        // Neev reads hostnames and email_domains only, so a row written here
        // would serve and federate nothing. Refused rather than ignored.
        $refuse = function () {
            throw new LogicException(
                'The domains table is read-only (RFC 006). Write a Hostname or an EmailDomain instead.'
            );
        };

        static::saving($refuse);
        static::deleting($refuse);
    }

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

    protected function hostColumn(): string
    {
        return 'domain';
    }

    public function setDomainAttribute(?string $value): void
    {
        $this->canonicaliseHostAttribute($value);
    }

    /**
     * @deprecated Use EmailDomain::isVerifiedForEmail(), which this calls. It
     *             reads email_domains: a verified host no longer federates.
     */
    public static function isVerifiedForEmail(string $email): bool
    {
        return EmailDomain::isVerifiedForEmail($email);
    }

    /**
     * @deprecated Use PlatformHost::zone().
     */
    public static function platformDomain(): ?string
    {
        return PlatformHost::zone();
    }

    /**
     * @deprecated Compare against PlatformHost::for($slug) or $owner->platformHost().
     */
    public static function isPlatformSubdomainFor(string $host, ?string $slug): bool
    {
        $issued = PlatformHost::for($slug);

        return $issued !== null && static::canonicalHost($host) === $issued;
    }

    /**
     * Whether a host sits strictly below the platform zone, at any depth.
     *
     * @deprecated Use PlatformHost::covers(), which also covers the zone itself.
     */
    public static function isPlatformSubdomain(string $host): bool
    {
        return PlatformHost::covers($host) && static::canonicalHost($host) !== PlatformHost::zone();
    }

    /**
     * The verified hostname for a host, from `hostnames`.
     *
     * @deprecated Use Hostname::forHost($host)->verified()->first().
     */
    public static function findByHost(string $host): ?Hostname
    {
        return Hostname::forHost($host)->verified()->first();
    }

    /**
     * The verified hostname for a host, among one kind of owner.
     *
     * @deprecated Use Hostname::ownerOf() or query Hostname directly.
     */
    public static function findByHostForOwnerType(string $host, string $ownerType): ?Hostname
    {
        return Hostname::forHost($host)->verified()->where('owner_type', $ownerType)->first();
    }

    /**
     * The verified hostname for a host, held by one owner.
     *
     * @deprecated Use $owner->hostnames()->forHost($host)->verified()->first().
     */
    public static function findByHostForOwner(string $host, string $ownerType, int $ownerId): ?Hostname
    {
        return Hostname::forHost($host)->verified()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasVerificationFailure(): bool
    {
        return $this->verification_failed_at !== null;
    }

    public function isVerificationStale(int $days): bool
    {
        return $this->verified_at !== null && $this->verified_at->diffInDays(now()) > $days;
    }

    /**
     * The record this row was proven by. A Hostname or EmailDomain copied from
     * this row with its token still accepts it while
     * `neev.dns_verification.legacy_record` is on.
     */
    public function getDnsRecordName(): string
    {
        return '_neev-verification.' . $this->domain;
    }
}
