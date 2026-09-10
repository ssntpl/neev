<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;

/**
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
 * @property-read Collection<int, DomainRule> $rules
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

    /**
     * Whether the email's domain has been claimed and DNS-verified by
     * a team/tenant (used to skip personal-team auto-creation for
     * federated domains during registration).
     *
     * The verified row is what the question is about, so it is asked for in
     * the query. Reading the first row of any kind and then testing it would
     * answer "no" whenever an unverified claim by another team happened to
     * sort first — several teams may hold pending claims on one domain.
     */
    public static function isVerifiedForEmail(string $email): bool
    {
        $emailDomain = strrchr($email, '@');

        if ($emailDomain === false) {
            return false;
        }

        return static::query()
            ->where('domain', substr($emailDomain, 1))
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * The DNS zones this installation owns, normalised for comparison.
     *
     * @return array<int, string>
     */
    public static function platformDomains(): array
    {
        $configured = config('neev.platform_domains');

        if ($configured === null || $configured === '') {
            return [];
        }

        return collect(is_array($configured) ? $configured : [$configured])
            ->map(fn ($domain) => static::canonicalHost((string) $domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * One canonical spelling of a host, so that the verification decision, the
     * uniqueness reservation and the resolution lookup all compare the same
     * value.
     *
     * `acme.otper.com.` is the fully qualified form of `acme.otper.com` and
     * `ACME.otper.com` is the same name again; stored as written they are three
     * distinct strings, so a second team could claim an alias of a host another
     * team already holds and the reservation would not notice.
     */
    public static function canonicalHost(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }

    /**
     * Canonicalise on the way in, whichever code path writes the row.
     */
    public function setDomainAttribute(?string $value): void
    {
        $this->attributes['domain'] = $value === null ? null : static::canonicalHost($value);
    }

    /**
     * Whether this host is the one the platform issues to that owner.
     *
     * A tenant's subdomain is its slug: team `acme` is handed `acme.otper.com`
     * and nothing else. Anchoring the claim to the claimant's own identity is
     * what stops a team taking a host that was never theirs — the installation's
     * operational names (`app.`, `www.`, `api.`) included, since no team can
     * hold those slugs while they are in `neev.slug.reserved`.
     */
    public static function isPlatformSubdomainFor(string $host, ?string $slug): bool
    {
        $slug = strtolower(trim((string) $slug));

        if ($slug === '') {
            return false;
        }

        $host = static::canonicalHost($host);

        foreach (static::platformDomains() as $platform) {
            if ($host === $slug . '.' . $platform) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a host sits inside one of this installation's own DNS zones.
     *
     * Only hosts strictly below a platform domain qualify. The apex is
     * excluded deliberately: `otper.com` is the installation's own name, not a
     * tenant's, and nobody should be able to claim it. The leading dot is what
     * makes the boundary real — without it `evil-otper.com` would pass as a
     * host inside `otper.com`.
     */
    public static function isPlatformSubdomain(string $host): bool
    {
        $host = static::canonicalHost($host);

        if ($host === '') {
            return false;
        }

        foreach (static::platformDomains() as $platform) {
            if (str_ends_with($host, '.' . $platform)) {
                return true;
            }
        }

        return false;
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
     * Find a verified domain by host, among one kind of owner.
     */
    public static function findByHostForOwnerType(string $host, string $ownerType): ?self
    {
        return static::where('domain', $host)
            ->where('owner_type', $ownerType)
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
                event(new DomainVerified($this));
            } elseif ($wasFailingVerification) {
                event(new DomainReverified($this));
            }

            return true;
        }

        if ($this->verified_at !== null && $this->verification_failed_at === null) {
            event(new DomainVerificationFailed($this));
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
