<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Exceptions\DomainAlreadyVerifiedException;
use Ssntpl\Neev\Traits\CanonicalisesHost;

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
    use CanonicalisesHost;

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

        // Rows hold the canonical spelling, so `Alice@ACME.com` has to be
        // compared as `acme.com` to find the claim on it.
        return static::query()
            ->forHost(substr($emailDomain, 1))
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * The DNS zone this installation owns, normalised for comparison.
     *
     * Null when none is configured, in which case nothing auto-verifies.
     */
    public static function platformDomain(): ?string
    {
        $configured = config('neev.platform_domain');

        if (!is_string($configured)) {
            return null;
        }

        return static::canonicalHost($configured) ?: null;
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

        $platform = static::platformDomain();

        return $platform !== null && static::canonicalHost($host) === $slug . '.' . $platform;
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
        $platform = static::platformDomain();

        return $host !== '' && $platform !== null && str_ends_with($host, '.' . $platform);
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
        return static::forHost($host)
            ->whereNotNull('verified_at')
            ->first();
    }

    /**
     * Find a verified domain by host, among one kind of owner.
     */
    public static function findByHostForOwnerType(string $host, string $ownerType): ?self
    {
        return static::forHost($host)
            ->where('owner_type', $ownerType)
            ->whereNotNull('verified_at')
            ->first();
    }

    /**
     * Find the primary verified domain by host.
     */
    public static function findPrimaryByHost(string $host): ?self
    {
        return static::forHost($host)
            ->whereNotNull('verified_at')
            ->where('is_primary', true)
            ->first();
    }

    /**
     * Find a verified domain by host for a specific owner.
     */
    public static function findByHostForOwner(string $host, string $ownerType, int $ownerId): ?self
    {
        return static::forHost($host)
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
     * A fresh verification token. The one generator for every path that
     * issues a token, so the TXT record has one format whichever route set it.
     */
    public static function newVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a verification token for this domain.
     */
    public function generateVerificationToken(): string
    {
        $token = static::newVerificationToken();
        $this->verification_token = $token;
        $this->save();

        return $token;
    }

    /**
     * Replace the token of a domain already claimed. The claim is unproven
     * until the new record is published, so it is unverified, and a failure
     * recorded against the old token no longer applies.
     */
    public function regenerateVerificationToken(): string
    {
        $this->verified_at = null;
        $this->verification_failed_at = null;

        return $this->generateVerificationToken();
    }

    /**
     * Delete this domain and, when it was the primary, hand the flag on.
     *
     * Deleting the primary leaves the owner with none, and whatever reads the
     * primary stops working, so it goes to a verified domain, or failing that
     * any remaining one, the oldest first so the choice does not depend on row
     * order. A team domain also gives back the accounts it deactivated,
     * whether or not a new token has unverified it since: with the domain
     * gone nothing manages those members, and nothing would be left to
     * reactivate them. One transaction, so a failed step does not leave the
     * domain deleted and the rest undone.
     */
    public function deleteAndPromote(): void
    {
        /** @var Team|Tenant|null $owner */
        $owner = $this->owner;

        DB::transaction(function () use ($owner) {
            if ($owner instanceof Team) {
                $owner->reactivateMembersOn($this->domain);
            }

            $this->rules()->delete();
            $this->delete();

            if ($this->is_primary && $owner) {
                /** @var Domain|null $next */
                $next = $owner->domains()->whereNotNull('verified_at')->orderBy('id')->first()
                    ?? $owner->domains()->orderBy('id')->first();
                $next?->markAsPrimary();
            }
        });
    }

    /**
     * Verify the domain via DNS TXT record lookup.
     * Returns true if the DNS record matches the verification token.
     *
     * @throws DomainAlreadyVerifiedException when another owner of the same
     *         kind has verified the host first
     */
    public function verify(): bool
    {
        // A pending claim on a host someone else already holds cannot win,
        // whatever DNS says. That is not a DNS failure, so the row is left as
        // it is. A row already verified is being re-checked, not claimed.
        if ($this->verified_at === null && static::findByHostForOwnerType($this->domain, $this->owner_type)) {
            throw new DomainAlreadyVerifiedException((string) $this->owner_type);
        }

        $records = @dns_get_record($this->getDnsRecordName(), DNS_TXT) ?: [];
        $matched = collect($records)->contains(fn ($r) => ($r['txt'] ?? '') === $this->verification_token);

        if ($matched) {
            $wasFailingVerification = $this->verification_failed_at !== null;

            // Whether this is the first verification is read from the locked
            // row, not this model, which may predate a new token.
            if ($this->saveVerified()) {
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
     * Record this claim as verified, without looking at DNS.
     *
     * The check at the top of verify() runs before the DNS lookup, so two
     * pending claims on one host could both pass it and both be saved. The
     * rule is decided again here with every claim on the host locked, so the
     * second of two concurrent claims waits for the first and then sees it.
     *
     * Fires DomainVerified when the claim was pending, as a DNS match does.
     *
     * @throws DomainAlreadyVerifiedException when another owner of the same
     *         kind has verified the host first
     */
    public function markVerified(): void
    {
        if ($this->saveVerified()) {
            event(new DomainVerified($this));
        }
    }

    /**
     * Save the claim as verified under the first-owner rule, and say whether
     * it was pending until now.
     *
     * Whether the claim is pending is read from its locked row rather than
     * this model: a model loaded while verified and then reset by a new token
     * would otherwise skip the check, and a second owner would be saved
     * verified beside the first.
     *
     * @throws DomainAlreadyVerifiedException
     */
    private function saveVerified(): bool
    {
        return DB::transaction(function () {
            $claims = static::where('domain', $this->domain)
                ->where('owner_type', $this->owner_type)
                ->lockForUpdate()
                ->get();

            $wasPending = $claims->firstWhere('id', $this->id)?->verified_at === null;

            if ($wasPending && $claims->contains(fn (Domain $claim) => $claim->id !== $this->id && $claim->verified_at !== null)) {
                throw new DomainAlreadyVerifiedException((string) $this->owner_type);
            }

            $this->verified_at = now();
            $this->verification_failed_at = null;
            $this->save();

            return $wasPending;
        });
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
