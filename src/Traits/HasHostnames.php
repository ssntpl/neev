<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\PlatformHost;

/**
 * The hosts an owner is served at (RFC 006 §4.4): custom hostnames it holds,
 * the one marked primary (`primary_hostname_id` on the owner), and its
 * platform subdomain, derived from its slug.
 *
 * Every host here is proven by DNS: claimHost() makes a pending claim, and
 * only verify() on it makes it serve.
 */
trait HasHostnames
{
    /**
     * An owner's hosts go with it: a row always has an owner.
     */
    public static function bootHasHostnames(): void
    {
        static::deleting(function ($owner) {
            $owner->hostnames()->get()->each->delete();
        });
    }

    /**
     * @return MorphMany<Hostname, $this>
     */
    public function hostnames(): MorphMany
    {
        return $this->morphMany(Hostname::class, 'owner');
    }

    /**
     * @return BelongsTo<Hostname, $this>
     */
    public function primaryHostname(): BelongsTo
    {
        return $this->belongsTo(Hostname::class, 'primary_hostname_id');
    }

    /**
     * Where this owner is served: its verified primary hostname, else its
     * oldest verified hostname, else its platform subdomain. A custom host the
     * owner has proven wins over the subdomain. Null when it is served nowhere.
     */
    public function canonicalHost(): ?string
    {
        $primary = $this->primaryHostname;

        if ($primary !== null && $primary->isVerified() && $primary->isOwnedBy($this)) {
            return $primary->host;
        }

        return $this->hostnames()->verified()->orderBy('id')->value('host')
            ?? $this->platformHost();
    }

    /**
     * This owner's platform subdomain, when it has one: only the owner kind the
     * mode routes on does (TenantResolver::platformHost()).
     */
    public function platformHost(): ?string
    {
        if (! $this instanceof ContextContainerInterface || ! app()->bound(TenantResolver::class)) {
            return null;
        }

        return app(TenantResolver::class)->platformHost($this);
    }

    /**
     * Claim a custom host, or return this owner's row for it as it is. The
     * claim is pending until its TXT record is published; the token is on the
     * returned row.
     *
     * @throws HostnameTakenException when another owner holds the host
     * @throws InvalidArgumentException for a host under the platform domain,
     *                                  or a team's host in isolated mode
     */
    public function claimHost(string $host): Hostname
    {
        // In isolated mode a team is a path inside its tenant, not a host
        // (RFC 006 Q5): the resolver would never route a host it held.
        if ($this instanceof ContextContainerInterface
            && $this->getContextType() === 'team'
            && config('neev.tenant', false)) {
            throw new InvalidArgumentException('A team cannot hold a host when tenants are enabled; add it to the tenant instead.');
        }

        $host = Hostname::canonicalHost($host);

        if ($host === '') {
            throw new InvalidArgumentException('A host is required.');
        }

        if (PlatformHost::covers($host)) {
            throw new InvalidArgumentException('A host under the platform domain follows a slug and cannot be claimed.');
        }

        try {
            return DB::transaction(function () use ($host) {
                $held = Hostname::forHost($host)->first();

                if ($held !== null && $held->isOwnedBy($this)) {
                    return $held;
                }

                // A host is unique across every owner (RFC 006 §2.1), so a
                // claim by another owner, proven or not, holds it.
                if ($held !== null) {
                    throw new HostnameTakenException();
                }

                $hostname = $this->hostnames()->create(['host' => $host]);
                $hostname->generateVerificationToken();

                return $hostname;
            });
        } catch (UniqueConstraintViolationException) {
            // Another claim on the host was saved between the lookup and ours:
            // the unique index, not a lock, settles the race.
            throw new HostnameTakenException();
        }
    }

    /**
     * Stop serving this owner at a host. Returns false when it holds no such
     * host. Its platform subdomain is not a row and cannot be released.
     */
    public function releaseHost(string $host): bool
    {
        $hostname = $this->hostnames()->forHost($host)->first();

        if ($hostname === null) {
            return false;
        }

        $hostname->release();

        return true;
    }

    /**
     * Point this owner's primary at one of its verified hostnames.
     *
     * @throws InvalidArgumentException when the host is not this owner's, or
     *         not verified
     */
    public function makePrimaryHostname(Hostname $hostname): void
    {
        if (! $hostname->isOwnedBy($this) || ! $hostname->isVerified()) {
            throw new InvalidArgumentException('Only a verified host of this owner can be its primary.');
        }

        $this->forceFill(['primary_hostname_id' => $hostname->getKey()])->save();
        $this->setRelation('primaryHostname', $hostname);
    }
}
