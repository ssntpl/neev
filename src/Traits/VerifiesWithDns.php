<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Events\DomainReverified;
use Ssntpl\Neev\Events\DomainVerificationFailed;
use Ssntpl\Neev\Events\DomainVerified;

/**
 * Proof by DNS TXT record that an owner controls a name (RFC 006 §4.2), shared
 * by Hostname (`_neev-host.<host>`) and EmailDomain (`_neev-email.<domain>`).
 *
 * `verified_at` is what grants: a row serves or federates while it is set.
 * `status` says why: `pending` (not proven), `verified`, `failed` (proven, but
 * the record has been missing since `verification_failed_at`) or `disabled`
 * (disabled by the app; neither DNS nor a new token brings it back). A new
 * token changes only the token: the re-check holds a verified row to it.
 *
 * A failed row still grants for `neev.dns_verification.unverify_after_failed_days`,
 * then VerifyDomainJob unverifies it (unverifyFailed()): it stays `failed`, so
 * the daily re-check keeps looking, and its record coming back restores it.
 * At twice that it is deleted.
 */
trait VerifiesWithDns
{
    use CanonicalisesHost;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DISABLED = 'disabled';

    /**
     * The label the TXT record sits under, in front of the name it proves.
     */
    abstract protected function dnsRecordPrefix(): string;

    public function scopeVerified($query)
    {
        return $query->whereNotNull($this->qualifyColumn('verified_at'));
    }

    /**
     * The rows the daily re-check covers: verified ones, and failed ones
     * unverified for their missing record, which it either restores or, in
     * time, deletes.
     */
    public function scopeRechecked($query)
    {
        return $query->where(fn ($q) => $q
            ->whereNotNull($this->qualifyColumn('verified_at'))
            ->orWhere($this->qualifyColumn('status'), self::STATUS_FAILED));
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * Whether the row lost its verification to a record missing too long, as
     * opposed to a claim that was never proven.
     */
    public function isUnverifiedByFailure(): bool
    {
        return $this->verified_at === null && $this->status === self::STATUS_FAILED;
    }

    public function getDnsRecordName(): string
    {
        return $this->dnsRecordPrefix() . '.' . $this->getAttribute($this->hostColumn());
    }

    /**
     * Issue a new token and save. Only the token changes: a verified row keeps
     * granting, and the daily re-check holds it to the new token's record,
     * so a record left unpublished fails and, in time, unverifies it as any
     * missing record would. A disabled row gets no token: it is left as it
     * is, and null is returned.
     */
    public function generateVerificationToken(): ?string
    {
        if ($this->status === self::STATUS_DISABLED) {
            return null;
        }

        $this->verification_token = bin2hex(random_bytes(32));
        $this->save();

        return $this->verification_token;
    }

    /**
     * Check the TXT record and record the outcome. A failure is dated from the
     * first check that missed, so `verification_failed_at` says how long the
     * record has been gone. A disabled row is not checked.
     */
    public function verify(): bool
    {
        if ($this->status === self::STATUS_DISABLED) {
            return false;
        }

        if ($this->dnsRecordMatches()) {
            // A row unverified for its missing record was proven before, so
            // its record coming back is a reverification.
            $wasFailing = $this->status === self::STATUS_FAILED
                || ($this->verified_at !== null && $this->verification_failed_at !== null);
            $wasPending = ! $wasFailing && $this->verified_at === null;

            $this->verified_at = now();
            $this->verification_failed_at = null;
            $this->status = self::STATUS_VERIFIED;
            $this->save();

            if ($wasFailing) {
                event(new DomainReverified($this));
            } elseif ($wasPending) {
                event(new DomainVerified($this));
            }

            return true;
        }

        if ($this->verified_at !== null && $this->verification_failed_at === null) {
            $this->status = self::STATUS_FAILED;
            event(new DomainVerificationFailed($this));
        }

        $this->verification_failed_at ??= now();
        $this->save();

        return false;
    }

    /**
     * Stop granting until the same token's record is checked again. A disabled
     * row stays disabled.
     */
    public function markUnverified(): void
    {
        if ($this->status === self::STATUS_DISABLED) {
            return;
        }

        $this->verified_at = null;
        $this->verification_failed_at = null;
        $this->status = self::STATUS_PENDING;
        $this->save();
    }

    /**
     * Stop granting because the record has been missing too long. The row
     * stays `failed` and keeps the start of its failure streak, so the
     * re-check still tries it: the record coming back restores it, and staying
     * missing gets it deleted.
     */
    public function unverifyFailed(): void
    {
        $this->verified_at = null;
        $this->status = self::STATUS_FAILED;
        $this->verification_failed_at ??= now();
        $this->save();
    }

    /**
     * Stop granting for good: DNS cannot bring the row back.
     */
    public function disable(): void
    {
        $this->verified_at = null;
        $this->status = self::STATUS_DISABLED;
        $this->save();
    }

    /**
     * Whether a TXT record carries this row's token. A row copied from
     * `domains` with its token also accepts the record its owner published
     * there, at `_neev-verification.<name>`; that is looked up only when the
     * row's own record misses, and only while
     * `neev.dns_verification.legacy_record` is on.
     *
     * That one record proves both the host and the email domain, which RFC 006
     * §4.1 keeps apart, so the fallback lasts only for the release that
     * deprecates `domains`.
     *
     * @deprecated The `_neev-verification` fallback is removed with `domains`.
     */
    protected function dnsRecordMatches(): bool
    {
        if (! $this->verification_token) {
            return false;
        }

        if ($this->txtRecordMatches($this->getDnsRecordName())) {
            return true;
        }

        if (! config('neev.dns_verification.legacy_record', true)) {
            return false;
        }

        $name = $this->getAttribute($this->hostColumn());

        // `domains` may hold the name in any spelling (`ACME.com.`).
        return Schema::hasTable('domains')
            && DB::table('domains')->where([
                'owner_type' => $this->owner_type,
                'owner_id' => $this->owner_id,
                'verification_token' => $this->verification_token,
            ])->whereIn(DB::raw('lower(domain)'), [$name, $name . '.'])->exists()
            && $this->txtRecordMatches('_neev-verification.' . $name);
    }

    protected function txtRecordMatches(string $recordName): bool
    {
        foreach (@dns_get_record($recordName, DNS_TXT) ?: [] as $record) {
            if (($record['txt'] ?? '') === $this->verification_token) {
                return true;
            }
        }

        return false;
    }
}
