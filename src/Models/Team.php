<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Ssntpl\Neev\Events\MemberAdded;
use Ssntpl\Neev\Events\MemberRemoved;
use Ssntpl\Neev\Events\TeamCreated;
use Ssntpl\Neev\Events\TeamDeleted;
use Ssntpl\Neev\Scopes\TeamTenantScope;
use Ssntpl\Neev\Scopes\TenantScope;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\SlugHelper;
use Ssntpl\Neev\Traits\HasEmailDomains;
use Ssntpl\Neev\Traits\HasHostnames;
use Ssntpl\Neev\Traits\HasTenantAuth;
use Ssntpl\Neev\Traits\RetiresSlugs;

/**
 * @property int $id
 * @property int|null $tenant_id
 * @property int $user_id
 * @property string $name
 * @property string|null $slug
 * @property bool $is_public
 * @property Carbon|null $activated_at
 * @property string|null $inactive_reason
 * @property Carbon|null $created_at
 * @property int|null $primary_hostname_id
 * @property Carbon|null $updated_at
 * @property-read User|null $owner
 * @property-read Domain|null $primaryDomain
 * @property-read Domain|null $domain
 * @property-read TeamAuthSettings|null $authSettings
 * @property-read Tenant|null $tenant
 * @property-read string|null $webDomain
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, EmailDomain> $emailDomains
 * @property-read Collection<int, Hostname> $hostnames
 * @property-read Hostname|null $primaryHostname
 * @property-read Collection<int, TeamInvitation> $invitations
 */
class Team extends Model implements ContextContainerInterface, IdentityProviderOwnerInterface, HasMembersInterface, ResolvableContextInterface
{
    use HasEmailDomains;
    use HasHostnames;
    use HasTenantAuth;
    use RetiresSlugs;

    protected static function booted()
    {
        static::addGlobalScope(new TeamTenantScope());

        // Team does not use the BelongsToTenant trait: its creating hook would
        // stamp the resolved context's id blindly, and in shared mode that
        // context is a Team. So the tenant_id assignment is done here instead.
        static::creating(function (Team $team) {
            $team->tenant_id = $team->tenantIdToStamp();
        });

        static::created(fn (Team $team) => event(new TeamCreated($team)));
        static::deleted(fn (Team $team) => event(new TeamDeleted($team)));
    }

    /** @return static */
    public static function model()
    {
        $class = config('neev.team_model', Team::class);
        return new $class();
    }

    public static function getClass()
    {
        return config('neev.team_model', Team::class);
    }

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'is_public',
        'activated_at',
        'inactive_reason',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'activated_at' => 'datetime',
    ];

    /**
     * Check if the team is active.
     */
    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Activate the team.
     */
    public function activate(): void
    {
        $this->update([
            'activated_at' => now(),
            'inactive_reason' => null,
        ]);
    }

    /**
     * Deactivate the team with a reason.
     */
    public function deactivate(?string $reason = null): void
    {
        $this->update([
            'activated_at' => null,
            'inactive_reason' => $reason,
        ]);
    }

    /**
     * The team's verified primary hostname, if it has one. canonicalHost()
     * answers where the team is served, platform subdomain included.
     */
    public function getWebDomainAttribute(): ?string
    {
        $primary = $this->primaryHostname;

        return $primary !== null && $primary->isVerified() ? $primary->host : null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function allUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['joined'])
            ->withTimestamps()
            ->as('membership');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['joined'])
            ->withTimestamps()
            ->as('membership')
            ->where('joined', true);
    }

    public function joinRequests(): BelongsToMany
    {
        return $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['joined', 'action'])
            ->withTimestamps()
            ->as('membership')
            ->where(['joined' => false, 'action' => Membership::REQUEST_FROM_USER]);
    }

    public function invitedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['joined', 'action'])
            ->withTimestamps()
            ->as('membership')
            ->where(['joined' => false, 'action' => Membership::REQUEST_TO_USER]);
    }

    public function removeUser($user): void
    {
        if ($this->user_id === $user?->id) {
            throw new Exception('cannot remove owner.');
        }

        // The role is scoped to this team, so it goes with the membership.
        // Left behind, it would silently come back if the user rejoins.
        DB::transaction(function () use ($user) {
            $this->users()->detach($user);
            $user->removeRole($this);
        });

        event(new MemberRemoved($this, $user));
    }

    /**
     * Rows of the `domains` table, read-only for this release.
     *
     * @deprecated Use emailDomains() or hostnames() (RFC 006).
     *
     * @return MorphMany<Domain, $this>
     */
    public function domains(): MorphMany
    {
        return $this->morphMany(Domain::class, 'owner');
    }

    /**
     * @deprecated Use emailDomains() or primaryHostname() (RFC 006).
     */
    public function domain(): MorphOne
    {
        return $this->primaryDomain();
    }

    /**
     * @deprecated Use primaryHostname() (RFC 006).
     */
    public function primaryDomain(): MorphOne
    {
        return $this->morphOne(Domain::class, 'owner')->where('is_primary', true);
    }

    /**
     * @deprecated Use hostnames()->verified() (RFC 006).
     *
     * @return MorphMany<Domain, $this>
     */
    public function customDomains(): MorphMany
    {
        return $this->morphMany(Domain::class, 'owner')->whereNotNull('verified_at');
    }

    /**
     * Whether any of the team's verified email domains is enforced. This and
     * the checks below read the loaded `emailDomains`, so a page asking them
     * for every member queries once.
     */
    public function enforcesDomain(): bool
    {
        return $this->emailDomains->contains(fn (EmailDomain $d) => $d->enforce && $d->isVerified());
    }

    /**
     * Whether the email is on one of the team's verified email domains. Such a
     * member may be invited while a domain is enforced; their account is managed
     * only when the domain is enforced (managesAccountOf()).
     */
    public function hasVerifiedDomainFor(string $email): bool
    {
        $domain = EmailDomain::domainOfEmail($email);

        return $this->emailDomains->contains(fn (EmailDomain $d) => $d->isVerified() && $d->domain === $domain);
    }

    /**
     * Whether the team manages the account of a user at this email: one of its
     * verified email domains for it is enforced. Deactivating is account-wide
     * and verifying is not exclusive, so only enforcing, which one owner at a
     * time may do, lets a team deactivate or reactivate the account.
     */
    public function managesAccountOf(string $email): bool
    {
        $domain = EmailDomain::domainOfEmail($email);

        return $this->emailDomains->contains(fn (EmailDomain $d) => $d->enforce && $d->isVerified() && $d->domain === $domain);
    }

    /**
     * Whether the email is on any email domain the team holds, verified or
     * not. An unverified one manages nobody; this only tells whether a
     * deactivated member removed from the team gets their account back.
     */
    public function holdsDomainFor(string $email): bool
    {
        return $this->emailDomains->contains('domain', EmailDomain::domainOfEmail($email));
    }

    /**
     * Whether users may ask to join. A verified email domain closes the team
     * to requests: its members are the people at that domain.
     */
    public function acceptsJoinRequests(): bool
    {
        return ! $this->emailDomains->contains(fn (EmailDomain $d) => $d->isVerified());
    }

    /**
     * How many members are on none of the team's verified email domains, while
     * one is enforced; 0 when none is. A member on any verified domain is
     * inside the team's boundary, so this is one count for the team, not one
     * per domain.
     */
    public function membersOutsideEmailDomains(): int
    {
        if (! $this->enforcesDomain()) {
            return 0;
        }

        return $this->users->reject(fn ($member) => $this->hasVerifiedDomainFor((string) $member->getAttribute('email')))->count();
    }

    /**
     * Whether removing this deactivated member gives their account back: this
     * team's email domain deactivated them, and no other team of theirs holds
     * that domain and may be the one that did.
     */
    public function reactivatesOnRemoval(User $user): bool
    {
        $email = (string) $user->email;

        return ! $user->active
            && $this->holdsDomainFor($email)
            && ! $this->anotherOfTheirTeamsClaims($user, (string) EmailDomain::domainOfEmail($email));
    }

    /**
     * Reactivate the inactive members on a domain that is going away, since
     * nothing would be left to reactivate them. A member another team of
     * theirs also holds the domain for is left as they are.
     */
    public function reactivateMembersOn(string $domain): void
    {
        $domain = EmailDomain::canonicalHost($domain);

        // Members are read unscoped, as hasMember() does: this also runs from
        // VerifyDomainJob, with no tenant resolved for TenantScope to match.
        foreach ($this->users()->withoutGlobalScope(TenantScope::class)->get() as $member) {
            /** @var User $member */
            if (! $member->active
                && EmailDomain::domainOfEmail((string) $member->email) === $domain
                && ! $this->anotherOfTheirTeamsClaims($member, $domain)) {
                $member->activate();
            }
        }
    }

    /**
     * Whether a team the user belongs to, other than this one, holds the
     * email domain, verified or not.
     */
    protected function anotherOfTheirTeamsClaims(User $user, string $domain): bool
    {
        return EmailDomain::forHost($domain)
            ->where('owner_type', $this->getMorphClass())
            ->where('owner_id', '!=', $this->getKey())
            ->whereIn('owner_id', $user->teams()->withoutGlobalScope(TeamTenantScope::class)->pluck($this->getQualifiedKeyName()))
            ->exists();
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function hasUser($user): bool
    {
        return $this->users()->withoutGlobalScope(TenantScope::class)->where('users.id', $user->id)->exists();
    }

    /**
     * Whether the user holds a membership not yet joined: an invitation they
     * have not accepted, or a join request the team has not answered.
     */
    public function hasPendingMember($user): bool
    {
        return $this->allUsers()->withoutGlobalScope(TenantScope::class)
            ->where('users.id', $user->id)
            ->wherePivot('joined', false)
            ->exists();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::getClass());
    }

    /**
     * The column TenantScope filters on.
     */
    public function getTenantIdColumn(): string
    {
        return 'tenant_id';
    }

    public function getQualifiedTenantIdColumn(): string
    {
        return $this->qualifyColumn($this->getTenantIdColumn());
    }

    /**
     * Query teams across every tenant — for platform-level code only.
     */
    public static function withoutTenantScope()
    {
        return static::query()->withoutGlobalScope(TeamTenantScope::class);
    }

    // -----------------------------------------------------------------
    // ContextContainerInterface
    // -----------------------------------------------------------------

    public function getContextId(): int
    {
        return $this->id;
    }

    public function getContextSlug(): string
    {
        return $this->slug;
    }

    public function getContextType(): string
    {
        return 'team';
    }

    // -----------------------------------------------------------------
    // HasMembersInterface
    // -----------------------------------------------------------------

    public function members(): Relation
    {
        return $this->allUsers();
    }

    public function hasMember($user): bool
    {
        return $this->hasUser($user);
    }

    /**
     * Attach a user to the team.
     *
     * Defaults to a full membership. Pass joined: false to record a pending one
     * instead — an invitation the user has still to accept, or a request still
     * awaiting the owner, told apart by $action.
     *
     * Note that MemberAdded fires and any $role is granted for pending members
     * too, so listeners and permissions take effect before the user has joined.
     */
    public function addMember($user, ?string $role = null, bool $joined = true, string $action = Membership::REQUEST_TO_USER): void
    {
        if (! $this->allUsers()->where('users.id', $user->id)->exists()) {
            $this->allUsers()->attach($user, [
                'joined' => $joined,
                'action' => $action,
            ]);

            event(new MemberAdded($this, $user));
        }

        if ($role) {
            $user->assignRole($role, $this);
        }
    }

    // -----------------------------------------------------------------
    // Slugs (RetiresSlugs)
    // -----------------------------------------------------------------

    /**
     * A team saved without a slug gets one from its name. It is chosen in
     * save(), before the slug is locked (RetiresSlugs).
     */
    protected function generateSlug(): ?string
    {
        return SlugHelper::generateFor($this, $this->name);
    }

    /**
     * A team slug is unique per tenant in isolated mode, where the index on
     * (tenant_id, slug) covers it, and installation-wide in shared mode, where
     * tenant_id is null and the index cannot stop two nulls (RFC 006 §6 Q5).
     */
    protected function slugPeers(): ?Builder
    {
        return config('neev.tenant', false) ? $this->teamsInSameTenant() : static::withoutGlobalScopes();
    }

    /**
     * In isolated mode a team slug retires within its tenant only. Shared mode
     * keeps every retirement, including those of teams since deleted.
     *
     * @param  Builder<RetiredSlug>  $retired
     * @return Builder<RetiredSlug>
     */
    protected function narrowRetiredSlugs(Builder $retired): Builder
    {
        return config('neev.tenant', false) ? $retired->amongOwners($this->teamsInSameTenant()) : $retired;
    }

    /**
     * Every team in this team's tenant, or every platform team when it has none.
     * SlugHelper generates a team slug among these, the teams the save checks.
     */
    public function teamsInSameTenant(): Builder
    {
        // The slug is checked on saving, before creating stamps the tenant on
        // a new team, so a new team is checked against the tenant it will get.
        $tenantId = $this->exists ? $this->tenant_id : $this->tenantIdToStamp();

        return static::withoutGlobalScopes()
            ->when($tenantId === null, fn (Builder $q) => $q->whereNull('tenant_id'), fn (Builder $q) => $q->where('tenant_id', $tenantId));
    }

    /**
     * The tenant a new team is created under: its own tenant_id if set, else
     * the resolved context's in isolated mode. In shared mode the resolved
     * context is itself a Team, which must never become a team's parent.
     */
    private function tenantIdToStamp(): ?int
    {
        if ($this->tenant_id !== null || !app()->bound(TenantResolver::class)) {
            return $this->tenant_id;
        }

        $context = app(TenantResolver::class)->resolvedContext();

        return $context && $context->getContextType() === 'tenant' ? $context->getContextId() : null;
    }

    // -----------------------------------------------------------------
    // ResolvableContextInterface
    // -----------------------------------------------------------------

    public static function resolveBySlug(string $slug): ?static
    {
        return static::where('slug', $slug)->first();
    }

    public static function resolveByDomain(string $domain): ?static
    {
        /** @var static|null */
        return Hostname::ownerOf($domain, 'team');
    }
}
