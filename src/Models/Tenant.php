<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Ssntpl\Neev\Database\Factories\TenantFactory;
use Ssntpl\Neev\Events\TenantCreated;
use Ssntpl\Neev\Scopes\TeamTenantScope;
use Ssntpl\Neev\Scopes\TenantScope;
use Ssntpl\Neev\Support\SlugHelper;
use Ssntpl\Neev\Traits\HasEmailDomains;
use Ssntpl\Neev\Traits\HasHostnames;
use Ssntpl\Neev\Traits\RetiresSlugs;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $activated_at
 * @property string|null $inactive_reason
 * @property Carbon|null $created_at
 * @property int|null $primary_hostname_id
 * @property Carbon|null $updated_at
 * @property-read TenantAuthSettings|null $authSettings
 * @property-read Collection<int, Team> $teams
 * @property-read Collection<int, Domain> $domains
 * @property-read Collection<int, EmailDomain> $emailDomains
 * @property-read Collection<int, Hostname> $hostnames
 * @property-read Hostname|null $primaryHostname
 */
class Tenant extends Model implements ContextContainerInterface, IdentityProviderOwnerInterface, HasMembersInterface, ResolvableContextInterface
{
    use HasEmailDomains;
    use HasFactory;
    use HasHostnames;
    use RetiresSlugs;

    protected $fillable = [
        'name',
        'slug',
        'activated_at',
        'inactive_reason',
    ];

    protected $casts = [
        'activated_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::created(fn (Tenant $tenant) => event(new TenantCreated($tenant)));
    }

    protected static function newFactory()
    {
        return TenantFactory::new();
    }

    /** @return static */
    public static function model()
    {
        $class = config('neev.tenant_model', Tenant::class);
        return new $class();
    }

    public static function getClass(): string
    {
        return config('neev.tenant_model', Tenant::class);
    }

    // -----------------------------------------------------------------
    // Slugs (RetiresSlugs)
    // -----------------------------------------------------------------

    /**
     * A tenant saved without a slug gets one from its name. It is chosen in
     * save(), before the slug is locked.
     */
    protected function generateSlug(): ?string
    {
        return SlugHelper::generateForTenant($this->name);
    }

    /**
     * A tenant slug is unique across the installation. The unique index holds
     * that too; checking here as well gives a held slug the same
     * SlugUnavailableException as a retired one.
     */
    protected function slugPeers(): ?Builder
    {
        return static::query();
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function teams(): HasMany
    {
        return $this->hasMany(Team::getClass())->withoutGlobalScope(TeamTenantScope::class);
    }

    public function authSettings(): HasOne
    {
        return $this->hasOne(TenantAuthSettings::class);
    }

    /**
     * Get cached auth settings (30-minute TTL).
     */
    public function getCachedAuthSettings(): ?TenantAuthSettings
    {
        // Cache the raw attributes, never the model: a serialized object cannot be
        // rehydrated when the app restricts cache.serializable_classes.
        $attributes = Cache::remember(
            "neev:auth_settings:{$this->getContextType()}:{$this->getContextId()}",
            1800,
            fn (): array => $this->authSettings?->getAttributes() ?? []
        );

        if ($attributes === []) {
            return null;
        }

        return (new TenantAuthSettings())->newFromBuilder($attributes);
    }

    /**
     * Rows of the `domains` table, read-only for this release.
     *
     * @deprecated Use emailDomains() or hostnames() (RFC 006).
     */
    public function domains(): MorphMany
    {
        return $this->morphMany(Domain::class, 'owner');
    }

    /**
     * Get users directly scoped to this tenant via tenant_id on users table.
     * Only available when tenant mode is enabled.
     */
    public function members(): Relation
    {
        return $this->hasMany(User::getClass(), 'tenant_id');
    }

    /**
     * Check if the tenant is active.
     */
    public function isActive(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Activate the tenant.
     */
    public function activate(): void
    {
        $this->update([
            'activated_at' => now(),
            'inactive_reason' => null,
        ]);
    }

    /**
     * Deactivate the tenant with a reason.
     */
    public function deactivate(?string $reason = null): void
    {
        $this->update([
            'activated_at' => null,
            'inactive_reason' => $reason,
        ]);
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
        return 'tenant';
    }

    // -----------------------------------------------------------------
    // HasMembersInterface
    // -----------------------------------------------------------------

    /**
     * Whether the user belongs to this tenant through tenant_id on the users
     * table. Membership of the tenant's teams does not count.
     */
    public function hasMember($user): bool
    {
        // Read unscoped, as Team::hasUser() does: the answer must not depend
        // on which tenant, if any, the current request resolved.
        return $this->members()->withoutGlobalScope(TenantScope::class)
            ->where('users.id', $user->id)
            ->exists();
    }

    // -----------------------------------------------------------------
    // IdentityProviderOwnerInterface
    // -----------------------------------------------------------------

    public function getAuthMethod(): string
    {
        return $this->getCachedAuthSettings()?->auth_method
            ?? 'password';
    }

    public function requiresSSO(): bool
    {
        return $this->getAuthMethod() === 'sso';
    }

    public function hasSSOConfigured(): bool
    {
        return $this->getCachedAuthSettings()?->hasSSOConfigured() ?? false;
    }

    public function getSSOProvider(): ?string
    {
        return $this->getCachedAuthSettings()?->sso_provider;
    }

    public function getSocialiteConfig(): ?array
    {
        return $this->getCachedAuthSettings()?->getSocialiteConfig();
    }

    public function allowsAutoProvision(): bool
    {
        return $this->getCachedAuthSettings()?->auto_provision
            ?? false;
    }

    public function getAutoProvisionRole(): ?string
    {
        return $this->getCachedAuthSettings()?->auto_provision_role
            ?? null;
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
        return Hostname::ownerOf($domain, 'tenant');
    }
}
