<?php

namespace Ssntpl\Neev\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\LaravelAcl\Models\RoleAssignment;

/**
 * Role names for a whole list at once.
 *
 * `HasRoles::getRole()` answers for one subject and one resource, builds a
 * fresh query every call and caches nothing, so a page that names the role on
 * every row pays a query per row — several, where the row asks more than once.
 * These two helpers ask the same question for the whole list in one query:
 * one resource and many subjects (a team's member list), or one subject and
 * many resources (a user's team list).
 *
 * A subject or resource with no live assignment is simply absent from the map,
 * which is the caller's cue to fall back the way it always did.
 */
class TeamRoles
{
    /**
     * Each subject's role name on one resource, keyed by subject id.
     *
     * The subjects are assumed to be of one class — they come from a single
     * relation — so the morph class of the first stands for all of them.
     *
     * @param  iterable<Model>  $subjects
     * @return array<int|string, string>
     */
    public static function forSubjects(Model $resource, iterable $subjects): array
    {
        $ids = [];
        $subjectType = null;

        foreach ($subjects as $subject) {
            $subjectType ??= $subject->getMorphClass();
            $ids[] = $subject->getKey();
        }

        if ($ids === []) {
            return [];
        }

        $assignments = static::assignmentsTable();

        return static::query()
            ->where($assignments . '.subject_type', $subjectType)
            ->whereIn($assignments . '.subject_id', $ids)
            ->where($assignments . '.resource_type', get_class($resource))
            ->where($assignments . '.resource_id', $resource->getKey())
            ->pluck(static::rolesTable() . '.name', $assignments . '.subject_id')
            ->all();
    }

    /**
     * One subject's role name on each of several resources, keyed by resource id.
     *
     * The resources are assumed to be of one class — they come from a single
     * relation — so the class of the first stands for all of them.
     *
     * @param  iterable<Model>  $resources
     * @return array<int|string, string>
     */
    public static function forResources(Model $subject, iterable $resources): array
    {
        $ids = [];
        $resourceClass = null;

        foreach ($resources as $resource) {
            $resourceClass ??= get_class($resource);
            $ids[] = $resource->getKey();
        }

        if ($ids === []) {
            return [];
        }

        $assignments = static::assignmentsTable();

        return static::query()
            ->where($assignments . '.subject_type', $subject->getMorphClass())
            ->where($assignments . '.subject_id', $subject->getKey())
            ->where($assignments . '.resource_type', $resourceClass)
            ->whereIn($assignments . '.resource_id', $ids)
            ->pluck(static::rolesTable() . '.name', $assignments . '.resource_id')
            ->all();
    }

    /** Live assignments joined to the role that names them. */
    protected static function query(): Builder
    {
        $assignments = static::assignmentsTable();

        return RoleAssignment::query()
            ->join(static::rolesTable(), static::rolesTable() . '.id', '=', $assignments . '.role_id')
            ->where(fn ($query) => $query->whereNull($assignments . '.expires_at')
                ->orWhere($assignments . '.expires_at', '>', now()));
    }

    protected static function assignmentsTable(): string
    {
        return (new RoleAssignment())->getTable();
    }

    protected static function rolesTable(): string
    {
        return (new Role())->getTable();
    }
}
