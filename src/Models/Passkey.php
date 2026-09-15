<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $credential_id
 * @property string|null $rp_id
 * @property string $public_key
 * @property string|null $name
 * @property string|null $ip
 * @property array<string, mixed>|null $location
 * @property Carbon|null $last_used
 * @property string $aaguid
 * @property array<int, string>|null $transports
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Passkey extends Model
{
    protected $fillable = [
        'user_id',
        'credential_id',
        'rp_id',
        'public_key',
        'name',
        'ip',
        'location',
        'last_used',
        'aaguid',
        'transports',
    ];

    protected $hidden = [
        'public_key',
    ];

    protected $casts = [
        'transports' => 'array',
        'location' => 'array',
        'last_used' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(), 'user_id');
    }

    /**
     * The relying party this credential authenticates against. A null column
     * predates per-domain relying parties, so it was issued under the
     * configured value.
     */
    public function effectiveRpId(): string
    {
        return $this->rp_id ?? static::configuredRpId();
    }

    /**
     * The configured relying party, canonicalised the way the resolver spells
     * it, so `App.Example.com.` still matches `app.example.com`.
     */
    public static function configuredRpId(): string
    {
        return Domain::canonicalHost((string) config('neev.relying_party_id'));
    }

    /** Whether this credential belongs to the given relying party. */
    public function matchesRelyingParty(string $rpId): bool
    {
        return $this->effectiveRpId() === $rpId;
    }

    /**
     * Restrict to the credentials a ceremony for this relying party may use.
     * Legacy (null) rows join the configured relying party only.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Passkey>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Passkey>
     */
    public function scopeForRelyingParty($query, string $rpId)
    {
        return $query->where(function ($query) use ($rpId) {
            $query->where('rp_id', $rpId);

            if ($rpId === static::configuredRpId()) {
                $query->orWhereNull('rp_id');
            }
        });
    }
}
