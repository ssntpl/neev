<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Support\OwnerRules;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation as TeamInvitationModel;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Support\TeamRoles;

class TeamController extends Controller
{
    public function profile(Request $request, Team $team)
    {
        return view('neev::team.profile', [
            'user' => User::model()->find($request->user()?->id),
            'team' => $team,
        ]);
    }

    public function members(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$team->hasMember($user)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }
        return view('neev::team.members', [
            'user' => $user,
            'team' => $team,
            'teamRoles' => Role::where('resource_type', Team::class)->get(),
            'memberRoles' => TeamRoles::forSubjects($team, $team->users->concat($team->invitedUsers)),
        ]);
    }

    public function settings(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$team->hasMember($user)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }
        return view('neev::team.settings', [
            'user' => $user,
            'team' => $team,
        ]);
    }

    public function switch(Request $request)
    {
        return redirect(route('teams.profile', $request->team_id));
    }

    public function create(Request $request)
    {
        return view('neev::team.create', ['user' => $request->user()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        try {
            /** @var User|null $user */
            $user = User::model()->find($user->id);
            $team = $user->ownedTeams()->forceCreate([
                'name' => $request->name,
                'is_public' => (bool) $request->public,
            ]);

            $team->addMember($user);
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to create team.']);
        }

        return back()->with('status', 'Team Created Successfully.');
    }

    public function update(Request $request)
    {
        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);
        /** @var User|null $actor */
        $actor = User::model()->find($request->user()?->id);
        if (!$team || !$actor || !$team->hasMember($actor)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }

        try {
            $team->name = $request->name;
            $team->is_public = (bool) $request->public;
            $team->save();
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update team.']);
        }

        return back()->with('status', 'Team Updated Successfully.');
    }

    public function delete(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || count($user->ownedTeams) < 2) {
                return back()->withErrors(['message' => 'You cannot delete this team.']);
            }
            $user->removeRole($team);
            $team->delete();
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to delete team.']);
        }

        return redirect(route('teams.profile', $user->ownedTeams[0]->id));
    }

    public function inviteMember(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
            }
            // Enforcement applies to every verified domain the team federates,
            // not only the primary one. When any of them is enforced, the
            // invitee must be on one of the team's verified domains.
            if ($team->enforcesDomain() && !$team->hasVerifiedDomainFor((string) $request->email)) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
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
                return back()->with('status', 'Invite link sent successfully.');
            }
            if ($team->users->contains($member)) {
                return back()->with(['status' => 'User already added.']);
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
                // assignRole() throws this when the role name does not resolve.
                return back()->withErrors(['message' => 'Role not found.']);
            }

            Mail::to($member->email)->send(new TeamInvitation($team->name, $member->name));
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to invite member.']);
        }

        return back()->with('status', 'Invite link sent successfully.');
    }

    public function leave(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user_id ?? $request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $actor */
            $actor = User::model()->find($request->user()?->id);

            if (!$team || !$user || !$actor) {
                return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
            }

            // Revoking an invitation is its own action: the invitee has not
            // joined, and the subject is the invitation rather than a member,
            // so the membership and owner rules below do not apply to it.
            if ($request->has('invitation_id')) {
                $invitation = $team->invitations()->whereKey($request->invitation_id)->first();
                if (!$invitation) {
                    return back()->withErrors(['message' => 'Invitation not found.']);
                }

                // A member of the team may revoke it; the invitee may decline
                // their own. Holding an id is not enough for anyone else.
                $isMember = $team->hasMember($actor);
                $isInvitee = hash_equals((string) $invitation->email, (string) $actor->email);

                if (!$isMember && !$isInvitee) {
                    return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
                }

                $invitation->delete();

                return back()->with('status', 'Invitation Revoked Successfully');
            }

            // The owner holds the team, so they are not a member who can be
            // taken out of it.
            if ($user->id == $team->user_id) {
                return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
            }

            // A membership not yet joined — an invitation not accepted, a join
            // request not answered — is withdrawn by a member, or by the user
            // it names. It is only ever detached: nothing has been joined, so
            // there is no account for the domain to deactivate.
            if ($team->hasPendingMember($user)) {
                if ($user->id !== $actor->id && !$team->hasMember($actor)) {
                    return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
                }

                DB::transaction(function () use ($team, $user) {
                    $team->allUsers()->detach($user);
                    $user->removeRole($team);
                });

                return back()->with('status', 'Removed Successfully');
            }

            // Leaving, or removing a member. The subject must have joined:
            // deactivation is account-wide, and without this any member could
            // deactivate every user on the team's verified domains.
            if (!$team->hasMember($actor) || !$team->hasMember($user)) {
                return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
            }

            // A member on any of the team's verified domains is managed by the
            // domain, not only one on the primary: deactivate them rather than
            // remove them.
            $onVerifiedDomain = $team->hasVerifiedDomainFor((string) $user->email);

            if ($onVerifiedDomain) {
                // Deactivating is account-wide: a member leaving on their own
                // would lock themselves out of everything, not just this team.
                if ($user->id === $actor->id) {
                    return back()->withErrors(['message' => 'You cannot leave a team your email domain manages.']);
                }
                if ($user->active) {
                    $user->deactivate();
                    return back()->with('status', 'User Deactivated Successfully');
                } else {
                    $user->activate();
                    return back()->with('status', 'User Activated Successfully');
                }
            }

            // An unverified domain manages nobody, so the member is removed as
            // any other would be. One this team's domain deactivated — verified
            // until a new token unverified it — gets their account back as they
            // go: detached and still deactivated, they would be locked out of
            // the whole application with nothing left to undo it.
            $reactivate = $team->reactivatesOnRemoval($user);

            DB::transaction(function () use ($team, $user, $reactivate) {
                $team->users()->detach($user);
                $user->removeRole($team);
                if ($reactivate) {
                    $user->activate();
                }
            });
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to leave from team.']);
        }

        if ($request->user()?->id == $request->user_id) {
            return redirect(route('account.teams'));
        }
        return back()->with('status', 'Removed Successfully');
    }

    public function inviteAction(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            if ($request->invitation_id) {
                $invitation = TeamInvitationModel::find($request->invitation_id);

                // As in the API twin: for a signed-in account the proof that
                // the invitation reached this inbox is a verified address.
                if (!$invitation
                    || !$user
                    || $user->email !== $invitation->email
                    || !$user->hasVerifiedEmail()) {
                    return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
                }
                $team = $invitation->team;
                if ($request->action == 'reject') {
                    $invitation->delete();
                    return back()->with('status', 'Invitation Revoked Successfully');
                } elseif ($request->action == 'accept') {
                    // The mail promises seven days; honour it here too.
                    if ($invitation->isExpired()) {
                        return back()->withErrors(['message' => 'This invitation has expired.']);
                    }
                    if ($team->users->contains($user)) {
                        return back()->with('status', 'Already Added.');
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
                        return back()->withErrors(['message' => 'Role not found.']);
                    }

                    return back()->with('status', 'Invitation Accepted');
                }
            } else {
                /** @var Team|null $team */
                $team = Team::model()->find($request->team_id);
                if ($request->action == 'reject') {
                    // Only an invitation not yet accepted can be rejected. A
                    // joined member leaves through leave(), which decides
                    // whether their domain lets them.
                    if (!$team || !$team->hasPendingMember($user)) {
                        return back()->withErrors(['message' => 'Invitation not found.']);
                    }
                    $team->allUsers()->detach($user);
                    $user->removeRole($team);
                    return back()->with('status', 'Rejected Successfully');
                } elseif ($request->action == 'accept') {
                    $membership = $team->invitedUsers->where('id', $user->id)->first()->membership;
                    $membership->joined = true;
                    $membership->save();
                    return back()->with('status', 'Request Accepted');
                }
            }
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to Accept/Reject Request.']);
        }

        return back()->with('status', 'Successfully');
    }

    public function request(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            $team = $this->requestedTeam($request);

            if ($team && $team->acceptsJoinRequests()) {
                $owner = $team->owner;
                if ($team->users->contains($user)) {
                    return back()->with('status', 'Already Added.');
                }
                if (!$team->allUsers->contains($user)) {
                    $team->allUsers()->attach($user, ['action' => Membership::REQUEST_FROM_USER]);
                }

                Mail::to($owner->email)->send(new TeamJoinRequest($team->name, $user->name, $owner->name, $team->id));

                return back()->with('status', 'Request has been sent.');
            }
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to Send Request.']);
        }

        return back()->withErrors(['message' => 'Team not found.']);
    }

    /**
     * Find the team a join request names.
     *
     * Accepts a team_id or a slug; the older owner-email plus team-name pair is
     * still honoured for callers that only know the team that way.
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

        if ($request->email && $request->team) {
            $owner = User::findByEmail($request->email);

            /** @var Team|null */
            return $owner
                ? Team::model()->where(['name' => $request->team, 'user_id' => $owner->id])->first()
                : null;
        }

        return null;
    }

    public function requestAction(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $member */
            $member = User::model()->find($request->user_id);
            // Acting on a join request admits someone to the team, and may hand
            // them a role, so it is reserved to the owner exactly as inviting is.
            if (!$team || !$member || !$user || $team->user_id !== $user->id) {
                return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
            }

            if ($request->action == 'reject') {
                // Only a membership not yet joined can be rejected. A joined
                // member is removed through leave(), which deactivates a
                // member the team's domain manages.
                if (!$team->hasPendingMember($member)) {
                    return back()->withErrors(['message' => 'Join request not found.']);
                }

                DB::transaction(function () use ($team, $member) {
                    $team->allUsers()->detach($member);
                    $member->removeRole($team);
                });
                return back()->with('status', 'Rejected Successfully');
            } elseif ($request->action == 'accept') {
                $joinRequest = $team->joinRequests->where('id', $member->id)->first();
                if (!$joinRequest) {
                    return back()->withErrors(['message' => 'Join request not found.']);
                }
                $membership = $joinRequest->membership;
                $membership->joined = true;
                $membership->save();
                if ($request->role) {
                    $member->assignRole($request->role, $team);
                }
                return back()->with('status', 'Request Accepted');
            }
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to Accept/Reject Request.']);
        }

        return back()->with('status', 'Successfully');
    }

    public function ownerChange(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var User|null $member */
            $member = User::model()->find($request->user_id);
            if (!$member || !$team->hasUser($member)) {
                return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
            }
            if ($team->owner->id === $user->id) {
                $team->user_id = $member->id;
                $team->save();
                return back()->with('status', 'Owner has been changed.');
            }
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to process change owner request.']);
        }

        return back()->withErrors(['message' => 'You cannot change owner.']);
    }

    /**
     * Set the team's rules (OwnerRules) from the form: an unticked box is a
     * rule turned off.
     */
    public function updateRules(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to update domain.']);
        }
        try {
            $values = [];
            foreach (OwnerRules::COLUMNS as $name => $column) {
                $values[$column] = $request->boolean($name);
            }

            $team->authSettings()->updateOrCreate([], $values);

            return back()->with('status', 'Domain Rules have been updated.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update domain rules.']);
        }
    }
}
