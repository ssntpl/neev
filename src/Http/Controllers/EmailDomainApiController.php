<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Ssntpl\Neev\Exceptions\EmailDomainEnforcedException;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Rules\Hostname as HostnameRule;

/**
 * A team's email domains (RFC 006): users at them belong to the team, each
 * proven by a TXT record at `_neev-email.<domain>`. Members read them; only
 * the owner changes them. Other teams may hold the same domain.
 */
class EmailDomainApiController extends Controller
{
    /**
     * Each enforced, verified domain carries `outside_members`: how many
     * members are on none of the team's verified domains.
     */
    public function index(Request $request, Team $team): JsonResponse
    {
        if (!$team->hasMember($request->user())) {
            return $this->forbidden();
        }

        $domains = $team->emailDomains->sortBy('id')->values();
        $outside = $team->membersOutsideEmailDomains();

        foreach ($domains as $domain) {
            if ($domain->enforce && $domain->isVerified()) {
                $domain->setAttribute('outside_members', $outside);
            }
        }

        return response()->json(['data' => $domains]);
    }

    /**
     * Claim a domain (`201`), or re-issue the token of one the team holds
     * (`200`). A disabled domain, or enforcing one another owner enforces, is
     * refused with `422`.
     */
    public function store(Request $request, Team $team): JsonResponse
    {
        if (!$this->owns($request, $team)) {
            return $this->forbidden();
        }

        $request->validate([
            'domain' => ['bail', 'required', 'string', 'max:255', new HostnameRule()],
            'enforce' => ['sometimes', 'boolean'],
        ]);

        try {
            $domain = $team->federateDomain($request->domain, $request->boolean('enforce'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        } catch (EmailDomainEnforcedException $e) {
            throw ValidationException::withMessages(['enforce' => $e->getMessage()]);
        }

        return $domain->wasRecentlyCreated
            ? $this->withRecord('Email domain added.', $domain, 201)
            : $this->withRecord('Verification token issued.', $domain);
    }

    public function show(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        $team = $emailDomain->owner;
        if (!$team instanceof Team || !$team->hasMember($request->user())) {
            return $this->forbidden();
        }

        return response()->json(['data' => $emailDomain]);
    }

    public function update(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if (!$this->ownsDomain($request, $emailDomain)) {
            return $this->forbidden();
        }

        $request->validate(['enforce' => ['required', 'boolean']]);

        try {
            $emailDomain->update(['enforce' => $request->boolean('enforce')]);
        } catch (EmailDomainEnforcedException $e) {
            throw ValidationException::withMessages(['enforce' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Email domain updated.', 'data' => $emailDomain]);
    }

    /**
     * Delete the domain, giving back the accounts it deactivated.
     */
    public function destroy(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if (!$this->ownsDomain($request, $emailDomain)) {
            return $this->forbidden();
        }

        $emailDomain->deleteAndReactivate();

        return response()->json(['message' => 'Email domain deleted.']);
    }

    public function verify(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if (!$this->ownsDomain($request, $emailDomain)) {
            return $this->forbidden();
        }

        if (!$emailDomain->verify()) {
            return response()->json(['message' => 'DNS verification failed. Please check your DNS record.'], 400);
        }

        return response()->json(['message' => 'Email domain verified.', 'data' => $emailDomain]);
    }

    /**
     * A new token. The domain stops counting until its new record is checked.
     */
    public function token(Request $request, EmailDomain $emailDomain): JsonResponse
    {
        if (!$this->ownsDomain($request, $emailDomain)) {
            return $this->forbidden();
        }

        if ($emailDomain->generateVerificationToken() === null) {
            return response()->json(['message' => 'This domain is disabled.'], 400);
        }

        return $this->withRecord('Verification token issued.', $emailDomain);
    }

    protected function owns(Request $request, Team $team): bool
    {
        return $team->user_id === $request->user()?->getKey();
    }

    protected function ownsDomain(Request $request, EmailDomain $emailDomain): bool
    {
        return $emailDomain->owner instanceof Team && $this->owns($request, $emailDomain->owner);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You do not have permission to do this.'], 403);
    }

    protected function withRecord(string $message, EmailDomain $domain, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $domain,
            'dns_record' => [
                'type' => 'TXT',
                'name' => $domain->getDnsRecordName(),
                'value' => $domain->verification_token,
            ],
        ], $status);
    }
}
