<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ssntpl\Neev\Traits\CanonicalisesHost;

/**
 * An email domain an owner has claimed (RFC 006): membership policy, not
 * transport. Users at this domain belong to this owner, which is any model: a
 * team, a tenant, or one of the application's own.
 *
 * Deliberately not unique across owners: two subsidiaries may both verify
 * `acme.com`. It is unique per owner only.
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
    use CanonicalisesHost;

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

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'enforce' => 'boolean',
        'verified_at' => 'datetime',
        'verification_failed_at' => 'datetime',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    protected function hostColumn(): string
    {
        return 'domain';
    }

    public function setDomainAttribute(?string $value): void
    {
        $this->canonicaliseHostAttribute($value);
    }
}
