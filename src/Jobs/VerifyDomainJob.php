<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Ssntpl\Neev\Events\DomainRemoved;
use Ssntpl\Neev\Events\DomainUnverified;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Support\PlatformHost;

/**
 * Re-checks an email domain's or custom host's DNS record, as
 * VerifyAllDomainsJob schedules: a verified row, or one already unverified for
 * its missing record.
 *
 * Any other row is skipped: it is a pending claim, verified through verify(),
 * neev:hostname:verify or neev:email-domain:verify instead. So is an email
 * domain an operator verified by hand (`manual`): it has no record to check.
 *
 * Both kinds follow one rule, `neev.dns_verification.unverify_after_failed_days`
 * (N). A record missing for N days unverifies the row and DomainUnverified
 * fires: a host stops serving, an email domain stops federating and enforcing,
 * so a lapsed registration cannot hand its membership power to whoever
 * registers the name next. Still missing at 2N, the row is deleted and
 * DomainRemoved fires; until then its owner can restore it by publishing the
 * record again. The owner stays.
 */
class VerifyDomainJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * A row deleted after the job was queued has nothing left to check.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public EmailDomain|Hostname $domain)
    {
    }

    public function handle(): void
    {
        // This job re-checks a verified row, or one it unverified earlier. A
        // new token issued after it was queued turned the row back into a
        // pending claim, waiting for a record its owner may not have published
        // yet; checking it now would only mark that fresh claim failed.
        if (! $this->domain->isVerified() && ! $this->domain->isUnverifiedByFailure()) {
            return;
        }

        if ($this->domain instanceof EmailDomain && $this->domain->verification_strategy === EmailDomain::STRATEGY_MANUAL) {
            return;
        }

        // A host under the platform zone follows a slug and has no record to
        // check. None can be claimed, but one copied from `domains` may remain.
        if ($this->domain instanceof Hostname && PlatformHost::covers($this->domain->host)) {
            return;
        }

        if ($this->domain->verify()) {
            return;
        }

        $days = (int) config('neev.dns_verification.unverify_after_failed_days', 7);
        $failedAt = $this->domain->verification_failed_at;

        if ($days <= 0 || $failedAt === null) {
            return;
        }

        if ($failedAt->lte(now()->subDays($days * 2))) {
            $this->remove();
        } elseif ($this->domain->isVerified() && $failedAt->lte(now()->subDays($days))) {
            $this->domain->unverifyFailed();
            event(new DomainUnverified($this->domain));
        }
    }

    /**
     * Delete the row. A host is unpointed from its owner's primary first; an
     * email domain gives back the accounts it deactivated, since nothing would
     * be left to reactivate them.
     */
    protected function remove(): void
    {
        if ($this->domain instanceof Hostname) {
            $this->domain->release();
        } else {
            $this->domain->deleteAndReactivate();
        }

        event(new DomainRemoved($this->domain));
    }
}
