<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Membership;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation as TeamInvitationModel;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;

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
        ]);
    }

    public function domain(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$team->hasMember($user)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }

        $domains = $team->domains;

        // A team can federate several domains, and a member on any verified one
        // of them is inside the team's boundary. Counting per domain in
        // isolation flagged those members on every other domain, so the warning
        // fired for people who were never outside.
        $verified = $domains->filter(fn ($domain) => $domain->verified_at !== null)
            ->map(fn ($domain) => '@' . strtolower($domain->domain))
            ->all();

        $outside = $team->users->filter(function ($member) use ($verified) {
            foreach ($verified as $suffix) {
                if (str_ends_with(strtolower($member->email), $suffix)) {
                    return false;
                }
            }

            return true;
        })->count();

        $outsideMembers = [];
        foreach ($domains as $domain) {
            $outsideMembers[$domain->id] = $domain->enforce && $domain->verified_at
                ? $outside
                : 0;
        }

        return view('neev::team.domain-federation', [
            'user' => $user,
            'team' => $team,
            'domains' => $domains,
            'outsideMembers' => $outsideMembers,
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
            if ($team->domain?->enforce && $team->domain?->verified_at && !str_ends_with(strtolower($request->email), '@' . strtolower($team->domain->domain))) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
            }
            $member = User::findByEmail($request->email);
            if (!$member) {
                $expiry = now()->addDays(7);

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry]
                );

                $invitation->role = $request->role;
                $invitation->save();

                $signedUrl = app(EmailLinks::class)->invitationUrl($invitation->id, $request->email, $expiry);

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

            if ($request->has('invitation_id')) {
                $invitation = $team->invitations()->find($request->invitation_id);
                if ($invitation) {
                    $invitation->delete();
                    return back()->with('status', 'Invitation Revoked Successfully');
                }
                return back()->withErrors(['message' => 'Invitation not found.']);
            }
            if ($user->id == $team->user_id) {
                return back()->withErrors(['message' => 'You cannot leave from this team.']);
            }

            if ($team->domain?->verified_at && str_ends_with(strtolower($user->email), '@' . strtolower($team->domain?->domain))) {
                if ($user->active) {
                    $user->deactivate();
                    return back()->with('status', 'User Deactivated Successfully');
                } else {
                    $user->activate();
                    return back()->with('status', 'User Activated Successfully');
                }
            }

            $team->users()->detach($user);
            $user->removeRole($team);
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
                if (!$invitation || !$user || $user->email !== $invitation->email) {
                    return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
                }
                $team = $invitation->team;
                if ($request->action == 'reject') {
                    $invitation->delete();
                    return back()->with('status', 'Invitation Revoked Successfully');
                } elseif ($request->action == 'accept') {
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

            if ($team && !$team->domain?->enforce && !$team->domain?->verified_at) {
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
                // Rejecting also removes an already-joined member, so the
                // team-scoped role has to go with the membership.
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

    public function federateDomain(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to federate domain.']);
        }

        $held = $team->domains()->where('domain', $request->domain)->exists();

        if (!$held && !Domain::isAvailable($request->domain, 'team')) {
            return back()->withErrors(['message' => 'This domain is already verified by another team.']);
        }

        try {
            $token = Str::random(32);
            $team->domains()->updateOrCreate([
                'domain' => $request->domain
            ], [
                'enforce' => (bool) $request->enforce,
                'verification_token' => $token,
                'is_primary' => !$team->domain,
            ]);

            return back()->with('token', $token);
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to federate domain.']);
        }
    }

    public function updateDomain(Request $request, Domain $domain)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain->owner?->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to update domain.']);
        }
        try {
            if ($request->verify) {
                $domain_rules = ["mfa"];
                if ($domain->verify()) {
                    foreach ($domain_rules as $rule) {
                        $domain->rules()->create([
                            'name' => $rule,
                            'value' => false,
                        ]);
                    }
                    return back()->with('status', 'Domain verified successfully!');
                }
                return back()->withErrors(['message' => 'DNS record not found. Please try again later.']);
            }

            if ($request->token) {
                $token = Str::random(32);
                $domain->verification_token = $token;
                $domain->save();
                return back()->with('token', $token);
            }

            $domain->enforce = (bool) $request->enforce;
            $domain->save();
            return back()->with('status', 'domain has been updated.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update domain.']);
        }
    }

    public function deleteDomain(Request $request, Domain $domain)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain->owner?->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to delete domain.']);
        }
        try {
            $domain->rules()->delete();
            $domain->delete();
            return back()->with('status', 'Domain has been deleted.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to delete domain.']);
        }
    }

    public function updateDomainRule(Request $request, Domain $domain)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain->owner?->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You do not have the required permissions to update domain.']);
        }
        try {
            foreach ($domain->rules as $rule) {
                $rule->value = (bool) $request->{$rule->name};
                $rule->save();
            }

            return back()->with('status', 'Domain Rules have been updated.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update domain rules.']);
        }
    }

    public function primaryDomain(Request $request)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || !$domain->verified_at || !$domain->owner?->users->contains($user)) {
            return back()->withErrors(['message' => 'Primary domain was not changed.']);
        }

        $pdomain = $domain->owner?->domain;
        if ($pdomain) {
            if ($pdomain->id == $domain->id) {
                return back()->with('status', 'Primary domain was already changed.');
            }
            $pdomain->is_primary = false;
            $pdomain->save();
        }
        $domain->is_primary = true;
        $domain->save();

        return back()->with('status', 'Primary domain has been changed.');
    }
}
