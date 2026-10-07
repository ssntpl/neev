<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
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
                $team = $invitation?->teamFor($user);
                if (!$invitation
                    || $user->email !== $invitation->email
                    || !$user->hasVerifiedEmail()
                    || !$team) {
                    return response()->json([
                        'message' => 'Invitation not found',
                    ], 400);
                }
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
            // deactivate every user on the team's enforced domains.
            if (!$team->hasMember($actor) || !$team->hasMember($user)) {
                return response()->json([
                    'message' => 'You cannot perform this action on this team.',
                ], 403);
            }

            // A member on a domain the team enforces is managed by it:
            // deactivate or reactivate them rather than remove them. Verifying
            // is not exclusive, so a domain verified but not enforced manages
            // nobody's account, and the member leaves or is removed as any
            // other would be.
            if ($team->managesAccountOf((string) $user->email)) {
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

            // A domain not enforced manages nobody, so the member is removed as
            // any other would be. One this team's domain deactivated — enforced
            // until it stopped, or verified until its record lapsed — gets
            // their account back as they go: detached and still deactivated,
            // they would be locked out of the whole application with nothing
            // left to undo it.
            $reactivate = $team->reactivatesOnRemoval($user);

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
}
