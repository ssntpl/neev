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
     * The relying party this credential authenticates against.
     *
     * A null column means the row predates per-domain relying parties, and
     * such a credential can only have been issued under the configured value.
     */
    public function effectiveRpId(): string
    {
        return $this->rp_id ?? (string) config('neev.relying_party_id');
    }

    /**
     * Whether this credential belongs to the given relying party. A passkey
     * is valid for exactly one, so a mismatch is not an authentication that
     * merely fails later — it is the wrong credential entirely.
     */
    public function matchesRelyingParty(string $rpId): bool
    {
        return $this->effectiveRpId() === $rpId;
    }

    /**
     * Restrict to the credentials a ceremony for this relying party may use.
     *
     * Legacy rows join the configured relying party, where they were issued.
     * They are deliberately absent from every other one: a credential minted
     * for the platform domain is not offered on a tenant's own domain.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Passkey>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Passkey>
     */
    public function scopeForRelyingParty($query, string $rpId)
    {
        return $query->where(function ($query) use ($rpId) {
            $query->where('rp_id', $rpId);

            if ($rpId === (string) config('neev.relying_party_id')) {
                $query->orWhereNull('rp_id');
            }
        });
    }
}
