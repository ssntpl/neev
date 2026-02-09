<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Support\SlugHelper;
use Ssntpl\Neev\Traits\HasTenantAuth;

class Team extends Model
{
    use HasTenantAuth;

    protected static function booted()
    {
        // Auto-generate slug if not provided
        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = SlugHelper::generate($team->name);
            }
        });
    }

    public static function model() {
        $class = config('neev.team_model', Team::class);
        return new $class;
    }
    
    public static function getClass() {
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
        'is_public' => 'bool',
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
     * Get the computed subdomain for this team.
     * Only returns a value if tenant_isolation is enabled and subdomain_suffix is configured.
     */
    public function getSubdomainAttribute(): ?string
    {
        if (!config('neev.tenant_isolation', false)) {
            return null;
        }

        $suffix = config('neev.tenant_isolation_options.subdomain_suffix');

        if (!$suffix || !$this->slug) {
            return null;
        }

        return $this->slug . '.' . ltrim($suffix, '.');
    }

    /**
     * Get the web domain for this team.
     * Returns primary custom domain if verified, otherwise computed subdomain.
     */
    public function getWebDomainAttribute(): ?string
    {
        // Check for primary custom domain first
        $primary = $this->primaryDomain;
        if ($primary && $primary->verified_at) {
            return $primary->domain;
        }

        // Fall back to computed subdomain
        return $this->subdomain;
    }

    public function owner()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    public function allUsers()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership');

        return $relation;
    }

    public function users()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined'])
            ->withTimestamps()
            ->as('membership')->where('joined', true);

        return $relation;
    }
    
    public function joinRequests()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_from_user'
            ]);

        return $relation;
    }
    
    public function invitedUsers()
    {
        $relation = $this->belongsToMany(User::getClass(), Membership::class)
            ->withPivot(['role', 'joined', 'action'])
            ->withTimestamps()
            ->as('membership')->where([
                'joined' => false,
                'action' => 'request_to_user'
            ]);

        return $relation;
    }

    public function removeUser($user)
    {
        if ($this->owner()?->id === $user?->id) {
            throw new Exception('cannot remove owner.');
        }

        $this->users()?->detach($user);
    }

    /**
     * Get all domains claimed by this team.
     */
    public function domains()
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * Get the primary domain for this team (used for email federation).
     * @deprecated Use primaryDomain() instead
     */
    public function domain()
    {
        return $this->primaryDomain();
    }

    /**
     * Get the primary domain for this team.
     */
    public function primaryDomain()
    {
        return $this->hasOne(Domain::class)->where('is_primary', true);
    }

    /**
     * Get custom domains for this team (web-serving domains).
     * These are verified domains that can be used for tenant routing.
     */
    public function customDomains()
    {
        return $this->hasMany(Domain::class)->whereNotNull('verified_at');
    }

    public function invitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function hasUser($user): bool
    {
        return $this->users?->contains($user);
    }
}
