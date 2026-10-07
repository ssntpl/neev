<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Events\EmailDomainEnforceDropped;
use Ssntpl\Neev\Exceptions\EmailDomainEnforcedException;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Traits\VerifiesWithDns;

/**
 * An email domain an owner has claimed (RFC 006): membership policy, not
 * transport. Users at this domain belong to this owner, which is any model: a
 * team, a tenant, or one of the application's own.
 *
 * Deliberately not unique across owners: two subsidiaries may both verify
 * `acme.com`, each by its own TXT record. It is unique per owner only.
 *
 * Enforcing is exclusive: only one owner's verified row may enforce a domain.
 * Asking to enforce one another owner enforces throws
 * EmailDomainEnforcedException; a row that enforces when it becomes verified
 * while another owner already enforces stops enforcing instead, and
 * EmailDomainEnforceDropped fires: the first to verify and enforce keeps it.
 *
 * `verification_strategy` says how it was proven: `dns`, or `manual` when an
 * operator vouched for it from the CLI. A manual row has no record to re-check,
 * so VerifyDomainJob leaves it alone; a new token makes it `dns` again.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $domain
 * @property string $status
 * @property string $verification_strategy
 * @property string|null $verification_token
 * @property Carbon|null $verified_at
 * @property Carbon|null $verification_failed_at
 * @property bool $enforce
 * @property-read Model $owner
 */
class EmailDomain extends Model
{
    use VerifiesWithDns;

    public const STRATEGY_DNS = 'dns';

    public const STRATEGY_MANUAL = 'manual';

    protected $fillable = [
        'owner_type',
        'owner_id',
        'domain',
        'status',
        'verification_strategy',
        'verification_token',
        'verified_at',
        'verification_failed_at',
        'enforce',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'enforce' => 'boolean',
        'verified_at' => 'datetime',
        'verification_failed_at' => 'datetime',
    ];

    /**
     * Whether the last save turned `enforce` off because another owner
     * already enforces the domain.
     */
    protected bool $enforceDropped = false;

    protected static function booted(): void
    {
        // A new token is proven by its record, whoever vouched for the old one,
        // unless the same save sets the strategy itself.
        static::saving(function (EmailDomain $domain) {
            $domain->enforceDropped = false;

            if ($domain->isDirty('verification_token') && ! $domain->isDirty('verification_strategy')) {
                $domain->verification_strategy = self::STRATEGY_DNS;
            }

            if ($domain->enforce && $domain->isDirty(['enforce', 'verified_at']) && $domain->enforcedElsewhere()) {
                if ($domain->isDirty('enforce')) {
                    throw new EmailDomainEnforcedException();
                }

                // Becoming verified: the first owner to verify and enforce
                // keeps enforcing; this one is verified without it.
                $domain->enforce = false;
                $domain->enforceDropped = true;
            }
        });

        static::saved(function (EmailDomain $domain) {
            if ($domain->enforceDropped) {
                event(new EmailDomainEnforceDropped($domain));
            }
        });
    }

    /**
     * Whether the last save turned `enforce` off because another owner's
     * verified row already enforces the domain. Verifying still succeeds, so
     * callers check this to warn the owner.
     */
    public function enforceWasDropped(): bool
    {
        return $this->enforceDropped;
    }

    /**
     * A save that may start enforcing holds a lock on the domain, so two
     * owners verifying or enforcing it at once cannot both pass the
     * enforcedElsewhere() check in `saving` and both enforce it.
     */
    public function save(array $options = []): bool
    {
        if (! $this->enforce || ! $this->isDirty(['enforce', 'verified_at'])) {
            return parent::save($options);
        }

        return Cache::lock('neev:email-domain-enforce:' . $this->domain, 10)
            ->block(10, fn () => parent::save($options));
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether another owner's verified row enforces this domain.
     */
    protected function enforcedElsewhere(): bool
    {
        return static::forHost($this->domain)->verified()
            ->where('enforce', true)
            ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
            ->exists();
    }

    protected function hostColumn(): string
    {
        return 'domain';
    }

    protected function dnsRecordPrefix(): string
    {
        return '_neev-email';
    }

    public function setDomainAttribute(?string $value): void
    {
        $this->canonicaliseHostAttribute($value);
    }

    /**
     * Whether an owner in the current tenant has verified the email's domain,
     * which makes a sign-up at it federated: it gets no personal team.
     *
     * Only an email domain counts. A host the app is served at says nothing
     * about who has addresses there (RFC 006 §3 (a)). Under tenant isolation a
     * claim counts only inside its own tenant: the tenant's, or one of its
     * teams' (the team lookup carries TeamTenantScope).
     */
    public static function isVerifiedForEmail(string $email): bool
    {
        $domain = static::domainOfEmail($email);

        if ($domain === null) {
            return false;
        }

        $resolver = app(TenantResolver::class);

        if (! $resolver->isIsolated()) {
            return static::forHost($domain)->verified()->exists();
        }

        $tenant = $resolver->currentTenant();

        return static::forHost($domain)->verified()
            ->where(function ($query) use ($tenant) {
                $query->whereHasMorph('owner', [Team::getClass()]);

                if ($tenant !== null) {
                    $query->orWhere(fn ($q) => $q
                        ->where('owner_type', $tenant->getMorphClass())
                        ->where('owner_id', $tenant->getKey()));
                }
            })
            ->exists();
    }

    /**
     * The domain part of an address, or null when it has none.
     */
    public static function domainOfEmail(string $email): ?string
    {
        $at = strrchr($email, '@');

        return $at === false || $at === '@' ? null : static::canonicalHost(substr($at, 1));
    }

    /**
     * Delete this claim. A team's claim gives back the accounts it
     * deactivated first, whether or not it has been unverified since:
     * with the claim gone nothing manages those members, and nothing would be
     * left to reactivate them. One transaction, so a failed step does not
     * leave the claim deleted and the rest undone.
     */
    public function deleteAndReactivate(): void
    {
        // Read unscoped: VerifyDomainJob runs with no tenant resolved, where
        // TeamTenantScope would hide a team inside a tenant.
        $owner = ($this->relationLoaded('owner') ? $this->owner : null)
            ?? $this->owner()->withoutGlobalScopes()->first();

        DB::transaction(function () use ($owner) {
            if ($owner instanceof Team) {
                $owner->reactivateMembersOn($this->domain);
            }

            $this->delete();
        });
    }
}
