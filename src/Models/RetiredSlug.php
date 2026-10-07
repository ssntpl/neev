<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A slug its owner has renamed away from (RFC 006 §6 Q1). The owner is any
 * model using RetiresSlugs: a team, a tenant, or one of the application's own.
 *
 * A platform subdomain is derived from the slug, so a slug handed to someone
 * else would hand them a host that SSO redirect URIs, OAuth allowlists, emailed
 * links and password managers still trust. The row is therefore kept forever:
 * the old slug is never issued to another owner. Its own owner may take it
 * back, which deletes the row. `created_at` is when it was retired, and the
 * old host serves for `neev.slug.retired_host_days` after that; the
 * reservation itself never ends.
 *
 * Retirements are per kind of owner (its morph class). A model narrows them
 * further through its owners, as a team does to its tenant in isolated mode;
 * nothing about that scope is stored here.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property string $slug
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Model $owner
 */
class RetiredSlug extends Model
{
    protected $fillable = [
        'owner_type',
        'owner_id',
        'slug',
    ];

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Retirements of this slug held by owners of this kind other than the
     * given one.
     */
    public function scopeHeldAgainst(Builder $query, string $ownerType, string $slug, ?int $exceptOwnerId = null): Builder
    {
        return $query->where('owner_type', $ownerType)
            ->where('slug', $slug)
            ->when($exceptOwnerId !== null, fn (Builder $q) => $q->where('owner_id', '!=', $exceptOwnerId));
    }

    /**
     * Retirements held by the given owners only.
     */
    public function scopeAmongOwners(Builder $query, Builder $owners): Builder
    {
        return $query->whereIn('owner_id', $owners->select($owners->getModel()->getQualifiedKeyName()));
    }
}
