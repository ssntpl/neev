<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ssntpl\Neev\Contracts\ContextContainerInterface;
use Ssntpl\Neev\Contracts\HasMembersInterface;
use Ssntpl\Neev\Contracts\IdentityProviderOwnerInterface;
use Ssntpl\Neev\Contracts\ResolvableContextInterface;
use Ssntpl\Neev\Database\Factories\TenantFactory;

class Tenant extends Model implements ContextContainerInterface, IdentityProviderOwnerInterface, HasMembersInterface, ResolvableContextInterface
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'managed_by_tenant_id',
        'platform_team_id',
    ];

    protected static function newFactory()
    {
        return TenantFactory::new();
    }

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
    // Relationships
    // -----------------------------------------------------------------

    public function teams(): HasMany
    {
        return $this->hasMany(Team::getClass());
    }

    public function managedBy(): BelongsTo
    {
        return $this->belongsTo(Tenant::getClass(), 'managed_by_tenant_id');
    }

    public function managedTenants(): HasMany
    {
        return $this->hasMany(Tenant::getClass(), 'managed_by_tenant_id');
    }

    public function platformTeam(): BelongsTo
    {
        return $this->belongsTo(Team::getClass(), 'platform_team_id');
    }

    public function authSettings(): HasOne
    {
        return $this->hasOne(TenantAuthSettings::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    /**
     * Get users directly scoped to this tenant via tenant_id on users table.
     * Only available when identity_strategy is 'isolated' and the
     * add_tenant_id_to_users_table migration has been published.
     */
    public function members(): Relation
    {
        return $this->hasMany(User::getClass(), 'tenant_id');
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

    public function hasMember($user): bool
    {
        return $this->teams()
            ->whereHas('allUsers', function ($query) use ($user) {
                $query->where('users.id', $user->id)
                    ->where('joined', true);
            })
            ->exists();
    }

    // -----------------------------------------------------------------
    // IdentityProviderOwnerInterface
    // -----------------------------------------------------------------

    public function getAuthMethod(): string
    {
        return $this->authSettings?->auth_method
            ?? config('neev.tenant_auth_options.default_method', 'password');
    }

    public function requiresSSO(): bool
    {
        return $this->getAuthMethod() === 'sso';
    }

    public function hasSSOConfigured(): bool
    {
        return $this->authSettings?->hasSSOConfigured() ?? false;
    }

    public function getSSOProvider(): ?string
    {
        return $this->authSettings?->sso_provider;
    }

    public function getSocialiteConfig(): ?array
    {
        return $this->authSettings?->getSocialiteConfig();
    }

    public function allowsAutoProvision(): bool
    {
        return $this->authSettings?->auto_provision
            ?? config('neev.tenant_auth_options.auto_provision', false);
    }

    public function getAutoProvisionRole(): ?string
    {
        return $this->authSettings?->auto_provision_role
            ?? config('neev.tenant_auth_options.auto_provision_role');
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
        return Domain::findByHostWithTenant($domain)?->tenant;
    }
}
