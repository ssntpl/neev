<?php

namespace Ssntpl\Neev\Commands\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerified;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Shared by the hostname and email-domain commands: the owner named by
 * --owner-type and --owner-id, and verifying an email domain on the
 * operator's word. A hostname is only ever proven by its DNS record.
 */
trait ManagesDomainRows
{
    use ResolvesTenantContext;

    /**
     * The owner the options name, asking for what is missing when the command
     * is interactive. Null, with the error printed, when it cannot be told.
     */
    protected function ownerFromOptions(): Team|Tenant|null
    {
        $type = $this->option('owner-type');

        if (! $type && $this->input->isInteractive()) {
            $type = select(
                label: 'What owns it?',
                options: ['tenant' => 'A tenant', 'team' => 'A team'],
                default: $this->isIsolated() ? 'tenant' : 'team',
            );
        }

        $id = $this->option('owner-id');

        if ($type && ! $id && $this->input->isInteractive()) {
            $id = text(label: "Which {$type}? (ID or slug)", required: true);
        }

        if (! $type || ! $id) {
            $this->error('You must specify --owner-type and --owner-id.');

            return null;
        }

        if (! in_array($type, ['team', 'tenant'], true)) {
            $this->error('--owner-type must be "team" or "tenant".');

            return null;
        }

        return $type === 'tenant' ? $this->resolveTenant((string) $id) : $this->resolveTeam((string) $id);
    }

    /**
     * Narrow a query to the owner --owner-type and --owner-id name, when they
     * name one. --owner-id is an ID, or a slug with --owner-type. False, with
     * the error printed, when the options cannot be used.
     */
    protected function whereOwnerFromOptions(Builder $query): bool
    {
        $type = $this->option('owner-type');
        $id = (string) $this->option('owner-id');

        if ($type !== null && ! in_array($type, ['team', 'tenant'], true)) {
            $this->error('--owner-type must be "team" or "tenant".');

            return false;
        }

        if ($type !== null) {
            $query->where('owner_type', $type);
        }

        if ($id === '') {
            return true;
        }

        if (ctype_digit($id)) {
            $query->where('owner_id', (int) $id);

            return true;
        }

        // A slug names a team or a tenant, looked up in different tables.
        if ($type === null) {
            $this->error('A slug in --owner-id needs --owner-type team or tenant.');

            return false;
        }

        $owner = $type === 'tenant' ? $this->resolveTenant($id) : $this->resolveTeam($id);
        $query->where('owner_id', $owner->getKey());

        return true;
    }

    /**
     * Record an email domain verified without DNS, on the word of the operator
     * running the command. Only the CLI does this: the models offer verify()
     * alone. The row is `manual`, so the daily re-check leaves it alone.
     */
    protected function markVerified(EmailDomain $row): void
    {
        // A row unverified for its missing record was proven before, as verify() treats it.
        $wasFailing = $row->status === EmailDomain::STATUS_FAILED
            || ($row->isVerified() && $row->verification_failed_at !== null);
        $wasPending = ! $wasFailing && ! $row->isVerified();

        $row->forceFill([
            'verified_at' => now(),
            'verification_failed_at' => null,
            'status' => EmailDomain::STATUS_VERIFIED,
            'verification_strategy' => EmailDomain::STRATEGY_MANUAL,
        ])->save();

        if ($wasFailing) {
            event(new DomainReverified($row));
        } elseif ($wasPending) {
            event(new DomainVerified($row));
        }
    }
}
