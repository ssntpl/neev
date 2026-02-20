<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class Domain extends Model
{
    protected $fillable = [
        'team_id',
        'tenant_id',
        'enforce',
        'domain',
        'verification_token',
        'is_primary',
        'verified_at',
    ];

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'enforce' => 'boolean',
        'is_primary' => 'boolean',
        'verified_at' => 'datetime',
        'verification_token' => 'hashed',
    ];

    public function team()
    {
        return $this->belongsTo(Team::getClass(), 'team_id');
    }

    /**
     * Get the tenant that owns this domain (isolated mode).
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::getClass(), 'tenant_id');
    }

    /**
     * Find a verified domain by host and tenant.
     * Used for tenant-level domain resolution in isolated mode.
     */
    public static function findByHostForTenant(string $host, int $tenantId): ?self
    {
        return static::where('domain', $host)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('verified_at')
            ->first();
    }

    /**
     * Find a verified domain by host with tenant_id set.
     * Used for tenant resolution via custom domains in isolated mode.
     */
    public static function findByHostWithTenant(string $host): ?self
    {
        return static::where('domain', $host)
            ->whereNotNull('tenant_id')
            ->whereNotNull('verified_at')
            ->first();
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
     * Mark this domain as the primary domain for its team.
     */
    public function markAsPrimary(): void
    {
        // Unset other primary domains for this team
        static::where('team_id', $this->team_id)
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
     * Verify the domain with the provided token.
     */
    public function verify(string $token): bool
    {
        if (Hash::check($token, $this->verification_token)) {
            $this->verified_at = now();
            $this->verification_token = null;
            $this->save();
            return true;
        }

        return false;
    }

    /**
     * Get the DNS TXT record name for domain verification.
     */
    public function getDnsRecordName(): string
    {
        return '_neev-verification.' . $this->domain;
    }
}
