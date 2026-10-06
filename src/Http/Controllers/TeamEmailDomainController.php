<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Ssntpl\Neev\Exceptions\EmailDomainEnforcedException;
use Ssntpl\Neev\Models\EmailDomain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\Hostname as HostnameRule;

/**
 * The Blade page for a team's email domains (RFC 006): users at them belong to
 * the team. Members see them; only the owner changes them. Every domain is
 * proven by its `_neev-email` record.
 */
class TeamEmailDomainController extends Controller
{
    public function index(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$team->hasMember($user)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }

        $domains = $team->emailDomains;
        $outside = $team->membersOutsideEmailDomains();

        $outsideMembers = [];
        foreach ($domains as $domain) {
            $outsideMembers[$domain->id] = $domain->enforce && $domain->verified_at
                ? $outside
                : 0;
        }

        return view('neev::team.email-domains', [
            'user' => $user,
            'team' => $team,
            'domains' => $domains,
            'outsideMembers' => $outsideMembers,
        ]);
    }

    public function store(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to federate domain.']);
        }

        $request->validate([
            'domain' => [
                // Stop at the first failure: a value already refused need not
                // be judged as a host name too.
                'bail',
                'required',
                'string',
                'max:255',
                new HostnameRule(),
            ],
        ]);

        try {
            $domain = $team->federateDomain((string) $request->domain, (bool) $request->enforce);

            return back()->with('token', $domain->verification_token)->with('dns_record_name', $domain->getDnsRecordName());
        } catch (InvalidArgumentException|EmailDomainEnforcedException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to federate domain.']);
        }
    }

    public function update(Request $request, EmailDomain $domain)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$domain->owner instanceof Team || $domain->owner->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to update domain.']);
        }
        try {
            if ($request->verify) {
                if ($domain->status === EmailDomain::STATUS_DISABLED) {
                    return back()->withErrors(['message' => 'This domain is disabled.']);
                }

                if ($domain->verify()) {
                    // Only the first owner to verify and enforce a domain
                    // keeps enforcing.
                    return back()->with('status', $domain->enforceWasDropped()
                        ? 'Domain verified. Another owner already enforces this domain, so enforce was turned off.'
                        : 'Domain verified successfully!');
                }
                return back()->withErrors(['message' => 'DNS record not found. Please try again later.']);
            }

            if ($request->token) {
                // Only the token changes; the daily re-check holds a
                // verified domain to the new record.
                $token = $domain->generateVerificationToken();
                if ($token === null) {
                    return back()->withErrors(['message' => 'This domain is disabled.']);
                }
                return back()->with('token', $token)->with('dns_record_name', $domain->getDnsRecordName());
            }

            $domain->enforce = (bool) $request->enforce;
            $domain->save();
            return back()->with('status', 'domain has been updated.');
        } catch (EmailDomainEnforcedException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update domain.']);
        }
    }

    public function destroy(Request $request, EmailDomain $domain)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$domain->owner instanceof Team || $domain->owner->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to delete domain.']);
        }
        try {
            $domain->deleteAndReactivate();

            return back()->with('status', 'Domain has been deleted.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to delete domain.']);
        }
    }
}
