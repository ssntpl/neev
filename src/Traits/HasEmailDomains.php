<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Ssntpl\Neev\Exceptions\EmailDomainEnforcedException;
use Ssntpl\Neev\Models\EmailDomain;

/**
 * Email domains an owner has claimed (RFC 006): users at them belong to it.
 */
trait HasEmailDomains
{
    /**
     * An owner's email domains go with it: a row always has an owner. They go
     * as any removed claim does, giving back the accounts they deactivated,
     * while the members are still attached; after this nothing could.
     */
    public static function bootHasEmailDomains(): void
    {
        static::deleting(function ($owner) {
            $owner->emailDomains()->get()->each(
                fn (EmailDomain $domain) => $domain->setRelation('owner', $owner)->deleteAndReactivate()
            );
        });
    }

    /**
     * @return MorphMany<EmailDomain, $this>
     */
    public function emailDomains(): MorphMany
    {
        return $this->morphMany(EmailDomain::class, 'owner');
    }

    /**
     * Claim an email domain, or re-issue the token of one already held. It is
     * pending until its TXT record is published; the token is on the returned
     * row. Other owners may hold the same domain (RFC 006 §2.1), but only one
     * may enforce it.
     *
     * $enforce applies to a new claim only. Re-issuing the token of one already
     * held leaves its `enforce` as it is: turning enforcement on or off is a
     * change of its own, not a side effect of asking for a token.
     *
     * @throws InvalidArgumentException when the owner's row is disabled
     * @throws EmailDomainEnforcedException when asked to enforce a new claim on
     *         a domain another owner enforces
     */
    public function federateDomain(string $host, bool $enforce): EmailDomain
    {
        try {
            return $this->federateDomainOnce($host, $enforce);
        } catch (UniqueConstraintViolationException) {
            // A request for the same domain, a double submit, created the row
            // between our lookup and insert: re-issue the token on that row,
            // as a second request after it would.
            return $this->federateDomainOnce($host, $enforce);
        }
    }

    protected function federateDomainOnce(string $host, bool $enforce): EmailDomain
    {
        /** @var EmailDomain $domain */
        $domain = $this->emailDomains()->firstOrNew(['domain' => EmailDomain::canonicalHost($host)]);

        if ($domain->status === EmailDomain::STATUS_DISABLED) {
            throw new InvalidArgumentException('This domain is disabled.');
        }

        if (! $domain->exists) {
            $domain->enforce = $enforce;
        }

        $domain->generateVerificationToken();
        $this->unsetRelation('emailDomains');

        return $domain;
    }
}
