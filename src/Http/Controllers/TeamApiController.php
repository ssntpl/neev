<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Exceptions\DomainAlreadyVerifiedException;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Rules\Hostname;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation as TeamInvitationModel;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;

class TeamApiController extends Controller
{
    public function getInvitations(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }

        // Only a verified address, and only invitations that can still be
        // accepted: an unverified address cannot act on one, so listing it
        // would only tell whoever typed that address what it was invited to.
        $invitations = $user->hasVerifiedEmail()
            ? TeamInvitationModel::where('email', $user->email)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->with('team')
                ->get()
            : TeamInvitationModel::query()->whereRaw('1 = 0')->get();

        // Get pending join requests user sent to teams
        $joinRequests = $user->sendRequests;
        $teamRequests = $user->teamRequests;

        return response()->json([
            'data' => [
                'invitations' => $invitations,
                'teamRequests' => $teamRequests,
                'join_requests' => $joinRequests
            ]
        ]);
    }

    public function setDefaultTeam(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }

        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$team || !$team->hasUser($user)) {
            return response()->json([
                'message' => 'Team not found',
            ], 400);
        }

        $user->setDefaultTeam($team);

        $team->load('owner', 'users');

        return response()->json([
            'message' => 'Default team updated successfully.',
            'data' => $team,
        ]);
    }

    public function teams(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);

        $teams = $user?->teams()->with('owner')->get();

        return response()->json([
            'data' => $teams,
        ]);
    }

    public function getTeam(Request $request, $id)
    {
        /** @var Team|null $team */
        $team = Team::model()->find($id);

        return $this->teamResponse($request, $team);
    }

    public function getTeamBySlug(Request $request, string $slug)
    {
        /** @var Team|null $team */
        $team = Team::model()->where('slug', $slug)->first();

        return $this->teamResponse($request, $team);
    }

    /**
     * Return a team the caller belongs to.
     *
     * A team the caller is not a member of is reported as missing rather than
     * forbidden, so the endpoint cannot be used to probe which teams exist.
     */
    private function teamResponse(Request $request, ?Team $team)
    {
        if (!$team || !$team->hasUser($request->user())) {
            return response()->json([
                'message' => 'Team not found',
            ], 400);
        }

        $team->load('owner', 'users', 'joinRequests', 'invitedUsers', 'invitations');

        return response()->json([
            'data' => $team,
        ]);
    }

    public function createTeam(Request $request)
    {
        $user = $request->user();
        try {
            /** @var User|null $user */
            $user = User::model()->find($user?->id);
            if (!$user) {
                return response()->json([
                    'message' => 'User not found',
                ], 400);
            }
            $team = $user->ownedTeams()->forceCreate([
                'name' => $request->name,
                'is_public' => (bool) $request->public,
            ]);

            $team->addMember($user);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }

        $team->load('owner', 'users');

        return response()->json([
            'data' => $team,
        ]);
    }

    public function updateTeam(Request $request)
    {
        $actor = User::model()->find($request->user()?->id);
        $team = Team::model()->find($request->team_id);
        if (!$team || !$actor || !$team->hasMember($actor)) {
            return response()->json([
                'message' => 'You cannot perform this action on this team.',
            ], 403);
        }

        try {
            if (isset($request->name)) {
                $team->name = $request->name;
            }
            if (isset($request->public)) {
                $team->is_public = (bool) $request->public;
            }
            $team->save();

            return response()->json([
                'message' => 'Team has been updated.',
                'data' => $team,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function deleteTeam(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || count($user->ownedTeams) < 2) {
                return response()->json([
                    'message' => 'You cannot delete this team.',
                ], 400);
            }
            $user->removeRole($team);
            $team->delete();

            return response()->json([
                'message' => 'Team has been deleted.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function changeTeamOwner(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $member */
            $member = User::model()->find($request->user_id);
            if (!$user || !$team || !$member) {
                return response()->json([
                    'message' => 'not found',
                ], 400);
            }
            if (!$team->hasUser($member)) {
                return response()->json([
                    'message' => 'This user is not the member in this team.',
                ], 400);
            }
            if ($team->owner->id === $user->id) {
                $team->user_id = $member->id;
                $team->save();
                return response()->json([
                    'message' => $member->name . ' is now the owner of the team.',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }

        return response()->json([
            'message' => 'You cannot change owner.',
        ], 400);
    }

    public function inviteMember(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id) {
                return response()->json([
                    'message' => 'You cannot invite member in this team.',
                ], 400);
            }
            // Enforcement applies to every verified domain the team federates,
            // not only the primary one. When any of them is enforced, the
            // invitee must be on one of the team's verified domains.
            if ($team->enforcesDomain() && !$team->hasVerifiedDomainFor((string) $request->email)) {
                return response()->json([
                    'message' => 'You cannot invite member in this team.',
                ], 400);
            }
            $member = User::findByEmail($request->email);
            if (!$member) {
                $expiry = now()->addDays(7);

                // Returned once, for the link; the row keeps only its hash.
                // Re-inviting the same address issues a fresh secret, which is
                // also how an invitation sent before this existed is replaced.
                $plainToken = TeamInvitationModel::generateToken();

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry, 'token' => $plainToken]
                );

                $invitation->role = $request->role;
                $invitation->save();

                $signedUrl = app(EmailLinks::class)->invitationUrl($invitation->id, $plainToken, $expiry);

                Mail::to($request->email)->send(new TeamInvitation($team->name, 'there', $signedUrl, $expiry, false));
                return response()->json([
                    'message' => 'Invite link sent successfully.',
                    'data' => $invitation
                ]);
            }
            if ($team->users->contains($member)) {
                return response()->json([
                    'message' => 'User already added.',
                ], 400);
            }

            try {
                DB::transaction(function () use ($team, $member, $request) {
                    if (!$team->allUsers->contains($member)) {
                        $team->users()->attach($member);
                        if ($request->role) {
                            $member->assignRole($request->role, $team);
                        }
                    }

                    $invitation = $team->invitations()->where('email', $request->email)->first();
                    if ($invitation) {
                        $invitation->delete();
                    }
                });
            } catch (InvalidArgumentException $e) {
                return response()->json([
                    'message' => 'Role not found.',
                ], 400);
            }

            // Only announce the membership once it is actually committed.
            Mail::to($member->email)->send(new TeamInvitation($team->name, $member->name));

            return response()->json([
                'message' => 'Invite link sent successfully.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function inviteAction(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            if ($request->invitation_id) {
                $invitation = TeamInvitationModel::find($request->invitation_id);

                // The emailed link proves inbox possession for an address with
                // no account yet. For an account already signed in, a verified
                // address is that same proof — an unverified one is a string
                // somebody typed, and registration hands out a token for it
                // straight away, so without this an attacker could register
                // an invited address and accept in its name.
                if (!$invitation
                    || $user->email !== $invitation->email
                    || !$user->hasVerifiedEmail()) {
                    return response()->json([
                        'message' => 'Invitation not found',
                    ], 400);
                }
                $team = $invitation->team;
                if ($request->action == 'reject') {
                    $invitation->delete();
                    return response()->json([
                        'message' => 'Invitation Revoked Successfully',
                    ]);
                } elseif ($request->action == 'accept') {
                    // The mail promises seven days; honour it here too.
                    if ($invitation->isExpired()) {
                        return response()->json(['message' => 'This invitation has expired.'], 400);
                    }
                    if ($team->users->contains($user)) {
                        return response()->json(['message' => 'Already Added.'], 400);
                    }

                    try {
                        DB::transaction(function () use ($team, $user, $invitation) {
                            if (!$team->allUsers->contains($user)) {
                                $team->allUsers()->attach($user, ['joined' => true]);
                                if ($invitation->role) {
                                    $user->assignRole($invitation->role, $team);
                                }
                            }
                            $invitation->delete();
                        });
                    } catch (InvalidArgumentException $e) {
                        return response()->json([
                            'message' => 'Role not found.',
                        ], 400);
                    }

                    return response()->json([
                        'message' => 'Invitation Accepted Successfully',
                    ]);
                }
            } else {
                /** @var Team|null $team */
                $team = Team::model()->find($request->team_id);
                if ($request->action == 'reject') {
                    // Only an invitation not yet accepted can be rejected. A
                    // joined member leaves through leave(), which decides
                    // whether their domain lets them.
                    if (!$team || !$team->hasPendingMember($user)) {
                        return response()->json([
                            'message' => 'Invitation not found',
                        ], 400);
                    }
                    $team->allUsers()->detach($user);
                    $user->removeRole($team);
                    return response()->json([
                        'message' => 'Invitation Rejected Successfully',
                    ]);
                } elseif ($request->action == 'accept') {
                    $membership = $team->invitedUsers->where('id', $user->id)->first()?->membership;
                    if (!$membership) {
                        return response()->json([
                            'message' => 'Invitation not found',
                        ], 400);
                    }
                    $membership->joined = true;
                    $membership->save();
                    return response()->json([
                        'message' => 'Invitation Accepted Successfully',
                    ]);
                }
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }

        return response()->json([
            'message' => 'Invalid Action.',
        ], 400);
    }

    public function leave(Request $request)
    {
        try {
            /** @var User|null $user */
            $user = User::model()->find($request->user_id ?? $request->user()?->id);
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $actor */
            $actor = User::model()->find($request->user()?->id);

            if (!$team || !$user || !$actor) {
                return response()->json([
                    'message' => 'You cannot perform this action on this team.',
                ], 403);
            }

            // Revoking an invitation is its own action: the invitee has not
            // joined, and the subject is the invitation rather than a member,
            // so the membership and owner rules below do not apply to it.
            if ($request->has('invitation_id')) {
                $invitation = $team->invitations()->whereKey($request->invitation_id)->first();
                if (!$invitation) {
                    return response()->json([
                        'message' => 'Invitation not found.',
                    ], 400);
                }

                // A member of the team may revoke it; the invitee may decline
                // their own. Holding an id is not enough for anyone else.
                $isMember = $team->hasMember($actor);
                $isInvitee = hash_equals((string) $invitation->email, (string) $actor->email);

                if (!$isMember && !$isInvitee) {
                    return response()->json([
                        'message' => 'You cannot perform this action on this team.',
                    ], 403);
                }

                $invitation->delete();

                return response()->json([
                    'message' => 'Invitation Revoked Successfully',
                ]);
            }

            // The owner holds the team, so they are not a member who can be
            // taken out of it.
            if ($user->id == $team->user_id) {
                return response()->json([
                    'message' => 'You cannot perform this action on this team.',
                ], 403);
            }

            // A membership not yet joined — an invitation not accepted, a join
            // request not answered — is withdrawn by a member, or by the user
            // it names. It is only ever detached: nothing has been joined, so
            // there is no account for the domain to deactivate.
            if ($team->hasPendingMember($user)) {
                if ($user->id !== $actor->id && !$team->hasMember($actor)) {
                    return response()->json([
                        'message' => 'You cannot perform this action on this team.',
                    ], 403);
                }

                DB::transaction(function () use ($team, $user) {
                    $team->allUsers()->detach($user);
                    $user->removeRole($team);
                });

                return response()->json([
                    'message' => 'Removed Successfully',
                ]);
            }

            // Leaving, or removing a member. The subject must have joined:
            // deactivation is account-wide, and without this any member could
            // deactivate every user on the team's verified domains.
            if (!$team->hasMember($actor) || !$team->hasMember($user)) {
                return response()->json([
                    'message' => 'You cannot perform this action on this team.',
                ], 403);
            }

            // A member on any of the team's verified domains is managed by the
            // domain, not only one on the primary: deactivate them rather than
            // remove them.
            $onVerifiedDomain = $team->hasVerifiedDomainFor((string) $user->email);

            if ($onVerifiedDomain) {
                // Deactivating is account-wide: a member leaving on their own
                // would lock themselves out of everything, not just this team.
                if ($user->id === $actor->id) {
                    return response()->json([
                        'message' => 'You cannot leave a team your email domain manages.',
                    ], 403);
                }
                if ($user->active) {
                    $user->deactivate();
                    return response()->json([
                        'message' => 'User Deactivated Successfully',
                    ]);
                } else {
                    $user->activate();
                    return response()->json([
                        'message' => 'User Activated Successfully',
                    ]);
                }
            }

            // An unverified domain manages nobody, so the member is removed as
            // any other would be. One deactivated through a domain the team
            // still holds — verified until a new token unverified it — gets
            // their account back as they go: detached and still deactivated,
            // they would be locked out of the whole application with nothing
            // left to undo it.
            $reactivate = !$user->active && $team->holdsDomainFor((string) $user->email);

            DB::transaction(function () use ($team, $user, $reactivate) {
                $team->users()->detach($user);
                $user->removeRole($team);
                if ($reactivate) {
                    $user->activate();
                }
            });

            return response()->json([
                'message' => 'Removed Successfully',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function request(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            $team = $this->requestedTeam($request);
            $team?->loadMissing('owner');
            if ($team && $team->acceptsJoinRequests()) {
                if ($team->users->contains($user)) {
                    return response()->json([
                        'message' => 'Already Added.',
                    ], 400);
                }
                if (!$team->allUsers->contains($user)) {
                    $team->allUsers()->attach($user, ['action' => Membership::REQUEST_FROM_USER]);
                }

                Mail::to($team->owner->email)->send(new TeamJoinRequest($team->name, $user->name, $team->owner->name, $team->id));

                return response()->json([
                    'message' => 'Request sent successfully.',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }

        return response()->json([
            'message' => 'Team not found.',
        ], 400);
    }

    /**
     * Find the team a join request names, by team_id or by slug.
     */
    private function requestedTeam(Request $request): ?Team
    {
        if ($request->team_id) {
            /** @var Team|null */
            return Team::model()->find($request->team_id);
        }

        if ($request->slug) {
            /** @var Team|null */
            return Team::model()->where('slug', $request->slug)->first();
        }

        return null;
    }

    public function requestAction(Request $request)
    {
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $member */
            $member = User::model()->find($request->user_id);
            /** @var User|null $actor */
            $actor = User::model()->find($request->user()?->id);

            if (!$team || !$member || !$actor || !$team->hasMember($actor)) {
                return response()->json([
                    'message' => 'You cannot perform this action on this team.',
                ], 403);
            }

            if ($request->action == 'reject') {
                // Only a membership not yet joined can be rejected. A joined
                // member is removed through leave(), which keeps the owner and
                // deactivates a member the team's domain manages.
                if (!$team->hasPendingMember($member)) {
                    return response()->json([
                        'message' => 'Request not found',
                    ], 400);
                }

                DB::transaction(function () use ($team, $member) {
                    $team->allUsers()->detach($member);
                    $member->removeRole($team);
                });

                return response()->json([
                    'message' => 'Rejected Successfully',
                ]);
            } elseif ($request->action == 'accept') {
                $membership = $team->joinRequests->where('id', $member->id)->first()?->membership;
                if (!$membership) {
                    return response()->json([
                        'message' => 'Request not found',
                    ], 400);
                }
                $membership->joined = true;
                $membership->save();
                if ($request->role) {
                    $member->assignRole($request->role, $team);
                }

                return response()->json([
                    'message' => 'Accepted Successfully',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }

        return response()->json([
            'message' => 'Invalid Action.',
        ], 400);
    }

    public function getDomains(Request $request)
    {
        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$team) {
            return response()->json([
                'message' => 'Team not found.',
            ], 400);
        }

        $actor = User::model()->find($request->user()?->id);
        if (!$actor || !$team->hasMember($actor)) {
            return response()->json([
                'message' => 'You cannot perform this action on this team.',
            ], 403);
        }

        $domains = $team->domains->load('rules');

        // Eager load users with their emails to avoid N+1 queries
        $team->loadMissing('users');

        // A member on any of the team's verified domains is inside its
        // boundary, as on the web domain page. Counting per domain flagged
        // members of one federated domain as outside every other one.
        $outside = $team->users
            ->reject(fn ($member) => $team->hasVerifiedDomainFor((string) $member->email))
            ->count();

        foreach ($domains as $domain) {
            if ($domain->enforce && $domain->verified_at) {
                $domain->outside_members = $outside;
            }
        }

        return response()->json([
            'message' => 'Domains fetched successfully.',
            'data' => $domains,
        ]);
    }

    public function domainFederate(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$user || !$team) {
            return response()->json([
                'message' => 'Not found.',
            ], 400);
        }

        if ($team->user_id !== $user->id) {
            //  || !str_ends_with(strtolower($user->email), '@' . strtolower($request->domain))
            return response()->json([
                'message' => 'You do not have the required permissions to federate domain.',
            ], 400);
        }

        $request->validate([
            'domain' => [
                // Stop at the first failure: a value already refused need not
                // be judged as a host name too.
                'bail',
                'required',
                'string',
                'max:255',
                new Hostname(),
            ],
        ]);

        // Compare the stored spelling, not whatever was typed: `ACME.com.` and
        // `acme.com` are one host, and the row keeps only the canonical form.
        $name = Domain::canonicalHost((string) $request->domain);
        $held = $team->domains()->where('domain', $name)->first();

        if (!$held && Domain::findByHostForOwnerType($name, 'team')) {
            return response()->json([
                'message' => 'This domain is already verified by another team.',
            ], 400);
        }

        // Re-submitting issues a new token and unverifies the domain. A
        // platform subdomain is verified by the platform, and its owner cannot
        // publish a record in the platform's zone, so it could never verify
        // again.
        if ($held && Domain::isPlatformSubdomain($name)) {
            return response()->json([
                'message' => 'A platform subdomain does not use a verification token.',
            ], 400);
        }

        try {
            $token = Str::random(32);
            /** @var Domain $domain */
            $domain = $team->domains()->updateOrCreate([
                'domain' => $name
            ], [
                'enforce' => (bool) $request->enforce,
                'verification_token' => $token,
                'verified_at' => null,
                'verification_failed_at' => null,
                // A domain already held keeps its own flag; `$team->domain` is
                // that very row when it is the primary, so recomputing it here
                // would take the primary flag away.
                'is_primary' => $held ? $held->is_primary : !$team->domain,
            ]);

            return response()->json([
                'message' => 'Domain federated successfully.',
                'token' => $token,
                'dns_record' => [
                    'type' => 'TXT',
                    'name' => $domain->getDnsRecordName(),
                    'value' => $token,
                ],
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function updateDomain(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || $domain->owner?->user_id !== $user->id) {
            return response()->json([
                'message' => 'You do not have the required permissions to update domain.',
            ], 400);
        }
        try {
            if ($request->verify) {
                if ($domain->verify()) {
                    $domain_rules = ["mfa"];
                    foreach ($domain_rules as $rule) {
                        // Verifying again, after a new token, finds the rule
                        // already there; keep its value rather than fail on the
                        // unique (name, domain_id) index.
                        $domain->rules()->firstOrCreate(['name' => $rule], ['value' => false]);
                    }

                    return response()->json([
                        'message' => 'Domain verified successfully!',
                    ]);
                }

                return response()->json([
                    'message' => 'DNS record not found. Please try again later.',
                ], 400);
            }

            if ($request->token) {
                // A new token unverifies the domain until the record is
                // published, and nobody can publish one in the platform's zone.
                if (Domain::isPlatformSubdomain($domain->domain)) {
                    return response()->json([
                        'message' => 'A platform subdomain does not use a verification token.',
                    ], 400);
                }

                $token = Str::random(32);
                $domain->verification_token = $token;
                $domain->verified_at = null;
                $domain->verification_failed_at = null;
                $domain->save();

                return response()->json([
                    'message' => 'Domain verification token has been updated.',
                    'token' => $token,
                    'dns_record' => [
                        'type' => 'TXT',
                        'name' => $domain->getDnsRecordName(),
                        'value' => $token,
                    ],
                ]);
            }

            if (isset($request->enforce)) {
                $domain->enforce = (bool) $request->enforce;
            }
            $domain->save();

            return response()->json([
                'message' => 'Domain has been updated.',
                'data' => $domain
            ]);
        } catch (DomainAlreadyVerifiedException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function deleteDomain(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || $domain->owner?->user_id !== $user->id) {
            return response()->json([
                'message' => 'You do not have the required permissions to delete domain.',
            ], 400);
        }
        try {
            /** @var Team|null $owner */
            $owner = $domain->owner;
            $wasPrimary = $domain->is_primary;

            // Deleting the primary leaves the team with none, and whatever
            // reads the primary stops working. Hand it to a verified domain,
            // or failing that any remaining one, the oldest first so the
            // choice does not depend on row order. One transaction, so a
            // failed promotion does not leave the domain deleted and the team
            // without a primary.
            DB::transaction(function () use ($domain, $owner, $wasPrimary) {
                $domain->rules()->delete();
                $domain->delete();

                if ($wasPrimary && $owner) {
                    $next = $owner->domains()->whereNotNull('verified_at')->orderBy('id')->first()
                        ?? $owner->domains()->orderBy('id')->first();
                    $next?->markAsPrimary();
                }
            });

            return response()->json([
                'message' => 'Domain has been deleted.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function updateDomainRule(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || $domain->owner?->user_id !== $user->id) {
            return response()->json([
                'message' => 'You do not have the required permissions to update domain.',
            ], 400);
        }
        try {
            foreach ($domain->rules as $rule) {
                if (isset($request->{$rule->name})) {
                    $rule->value = (bool) $request->{$rule->name};
                }
                $rule->save();
            }

            return response()->json([
                'message' => 'Domain Rules have been updated.',
                'data' => $domain->rules
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function getDomainRule(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);

        $domain = Domain::find($request->domain_id);
        if (!$domain) {
            return response()->json([
                'message' => 'Domain not found.',
            ], 400);
        }

        if (!$user || !$domain->owner?->users->contains($user)) {
            return response()->json([
                'message' => 'You do not have the required permissions to get domain rules.',
            ], 400);
        }

        return response()->json([
            'data' => $domain->rules
        ]);
    }

    public function primaryDomain(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || !$domain->verified_at || !$domain->owner?->users->contains($user)) {
            return response()->json([
                'message' => 'You do not have the required permissions to change primary domain.',
            ], 400);
        }

        $pdomain = $domain->owner?->domain;
        if ($pdomain) {
            if ($pdomain->id == $domain->id) {
                return response()->json([
                    'message' => 'Primary domain is already set.',
                ]);
            }
            $pdomain->is_primary = false;
            $pdomain->save();
        }
        $domain->is_primary = true;
        $domain->save();

        return response()->json([
            'message' => $domain->domain . ' has been set as primary domain.',
        ]);
    }
}
