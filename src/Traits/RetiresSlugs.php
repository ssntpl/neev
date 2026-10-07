<?php

namespace Ssntpl\Neev\Traits;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Ssntpl\Neev\Events\SlugChanged;
use Ssntpl\Neev\Exceptions\SlugUnavailableException;
use Ssntpl\Neev\Models\RetiredSlug;

/**
 * Slugs are mutable but never recycled (RFC 006 §4.4). A platform subdomain is
 * derived from the slug, so a slug reissued to another owner would hand them
 * a host that credentials and links still trust.
 *
 * Any model with a slug may use this: Neev's Team and Tenant do, and an
 * application's own models can. Renaming retires the old slug for good and
 * fires SlugChanged. Deleting retires the slug the model held, soft deletes
 * included, since the subdomain is just as trusted after its owner is gone. Saving a slug another model of the same kind has retired
 * throws SlugUnavailableException. Retirements are keyed by the model's morph
 * class, so each kind of model has its own slugs.
 *
 * A model can also have the save refuse a slug another one holds now, by
 * returning its peers from slugPeers(), for when a unique index cannot do it.
 * A save that sets a slug holds a cache lock on that slug, and a rename on
 * the old one too, and a delete holds one on the slug it retires, so two
 * saves involving the same slug cannot both pass the checks; saves on different slugs do not wait for each other. A slug the
 * model generates is chosen before locking, and chosen again if another save
 * takes it first. The locks end when the save returns; a save inside a
 * caller's transaction is not visible to the next one until that transaction
 * commits.
 *
 * Only model saves and deletes are guarded. A query-builder update or delete,
 * or a database cascade, bypasses all of this.
 */
trait RetiresSlugs
{
    public static function bootRetiresSlugs(): void
    {
        static::saving(function (self $owner) {
            $column = $owner->getSlugColumn();
            $slug = $owner->getAttribute($column);

            if ($slug === null || $slug === '' || ! $owner->isDirty($column)) {
                return;
            }

            if ($owner->slugUnavailable($slug)) {
                throw new SlugUnavailableException($slug);
            }
        });

        static::updated(function (self $owner) {
            $column = $owner->getSlugColumn();
            $old = $owner->getOriginal($column);
            $new = $owner->getAttribute($column);

            if (! $owner->wasChanged($column) || $old === null || $old === '') {
                return;
            }

            RetiredSlug::create([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'slug' => $old,
            ]);

            // Taking back its own old slug makes that slug live again.
            if ($new !== null && $new !== '') {
                RetiredSlug::where('owner_type', $owner->getMorphClass())
                    ->where('owner_id', $owner->getKey())
                    ->where('slug', $new)
                    ->delete();

                event(new SlugChanged($owner, $old, $new));
            }
        });

        // A deleted owner's slug is retired like a renamed one's, so it is
        // never issued to another owner. firstOrCreate: a soft-deleted model
        // force-deleted later is deleted twice.
        static::deleted(function (self $owner) {
            $slug = $owner->getOriginal($owner->getSlugColumn());

            if ($slug === null || $slug === '') {
                return;
            }

            RetiredSlug::firstOrCreate([
                'owner_type' => $owner->getMorphClass(),
                'owner_id' => $owner->getKey(),
                'slug' => $slug,
            ]);
        });
    }

    /**
     * Delete the model holding a lock on its slug, so no save can take the
     * slug between the row going and its retirement being recorded.
     */
    public function delete()
    {
        return $this->withSlugLocks([$this->getOriginal($this->getSlugColumn())], fn () => parent::delete());
    }

    public function save(array $options = [])
    {
        $column = $this->getSlugColumn();

        if ($this->exists && ! $this->isDirty($column)) {
            return parent::save($options);
        }

        $generated = ! $this->exists && blank($this->getAttribute($column));

        for ($attempt = 1; ; $attempt++) {
            if ($generated) {
                $this->setAttribute($column, $this->generateSlug());
            }

            $slugs = [$this->getAttribute($column), $this->exists ? $this->getOriginal($column) : null];

            try {
                return $this->withSlugLocks($slugs, fn () => parent::save($options));
            } catch (SlugUnavailableException $e) {
                // Another save took the generated slug between choosing and
                // locking it; choose again.
                if (! $generated || $attempt === 5) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Run the callback holding a lock on each slug, taken in sorted order so
     * two saves cannot each hold one the other is waiting for.
     *
     * @param  array<int, string|null>  $slugs
     */
    protected function withSlugLocks(array $slugs, Closure $callback): mixed
    {
        $slugs = array_values(array_unique(array_filter($slugs, fn ($slug) => ! blank($slug))));
        sort($slugs);

        foreach (array_reverse($slugs) as $slug) {
            $inner = $callback;
            $callback = fn () => Cache::lock('neev:slugs:' . $this->getMorphClass() . ':' . $slug, 10)
                ->block(10, $inner);
        }

        return $callback();
    }

    /**
     * A slug for a new model saved without one, or null to save it without.
     */
    protected function generateSlug(): ?string
    {
        return null;
    }

    /**
     * The column holding the slug.
     */
    public function getSlugColumn(): string
    {
        return 'slug';
    }

    /**
     * The models whose live slugs this one must not repeat, or null to leave
     * that to a unique index.
     */
    protected function slugPeers(): ?Builder
    {
        return null;
    }

    /**
     * Narrow the retirements this model's slug is checked against. All of
     * them by default.
     *
     * @param  Builder<RetiredSlug>  $retired
     * @return Builder<RetiredSlug>
     */
    protected function narrowRetiredSlugs(Builder $retired): Builder
    {
        return $retired;
    }

    /**
     * Whether another model of this kind holds the slug now (among its
     * slugPeers()) or has retired it.
     */
    protected function slugUnavailable(string $slug): bool
    {
        $peers = $this->slugPeers();

        $held = $peers !== null && $peers->where($this->getSlugColumn(), $slug)
            ->when($this->exists, fn (Builder $q) => $q->whereKeyNot($this->getKey()))
            ->exists();

        return $held || $this->narrowRetiredSlugs(
            RetiredSlug::heldAgainst($this->getMorphClass(), $slug, $this->exists ? $this->getKey() : null)
        )->exists();
    }
}
