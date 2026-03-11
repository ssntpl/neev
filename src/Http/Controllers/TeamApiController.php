<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

class TeamApiController extends Controller
{
    public function getInvitations(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }

        // Get pending invitations sent to user's emails
        $emails = $user->emails->pluck('email');
        $invitations = \Ssntpl\Neev\Models\TeamInvitation::whereIn('email', $emails)
            ->with('team')
            ->get();

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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }

        /** @var \Ssntpl\Neev\Models\Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$team || !$team->hasUser($user)) {
            return response()->json([
                'message' => 'Team not found',
            ], 400);
        }

        $user->setDefaultTeam($team);

        $team->load('owner.email', 'users.email');

        return response()->json([
            'message' => 'Default team updated successfully.',
            'data' => $team,
        ]);
    }

    public function teams(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        $teams = $user?->teams?->load('owner');

        return response()->json([
            'data' => $teams,
        ]);
    }

    public function getTeam(Request $request, $id)
    {
        /** @var \Ssntpl\Neev\Models\Team|null $team */
        $team = Team::model()->find($id);
        if (!$team) {
            return response()->json([
                'message' => 'Team not found',
            ], 400);
        }

        if (!$team->hasUser($request->user())) {
            return response()->json([
                'message' => 'Team not found',
            ], 400);
        }

        $team->load('owner.email', 'users.email', 'joinRequests', 'invitedUsers', 'invitations');

        return response()->json([
            'data' => $team,
        ]);
    }

    public function createTeam(Request $request)
    {
        $user = $request->user();
        try {
            /** @var \Ssntpl\Neev\Models\User|null $user */
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

        $team->load('owner.email', 'users.email');

        return response()->json([
            'data' => $team,
        ]);
    }

    public function updateTeam(Request $request)
    {
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            if (!$team) {
                return response()->json([
                    'message' => 'Team not found',
                ], 400);
            }
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var \Ssntpl\Neev\Models\User|null $member */
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || ($team->domain?->enforce && $team->domain?->verified_at && !str_ends_with(strtolower($request->email), '@' . strtolower($team->domain?->domain)))) {
                return response()->json([
                    'message' => 'You cannot invite member in this team.',
                ], 400);
            }
            $email = Email::findByEmail($request->email);
            $member = $email?->user;
            if (!$member) {
                $expiry = now()->addDays(7);

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry]
                );

                $invitation->role = $request->role;
                $invitation->save();

                $signedUrl = URL::temporarySignedRoute(
                    'register',
                    $expiry,
                    ['id' => $invitation->id, 'hash' => sha1($request->email)]
                );

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
            } elseif (!$team->allUsers->contains($member)) {
                $team->users()->attach($member);
                if ($request->role) {
                    $member->assignRole($request->role, $team);
                }
            }

            $invitation = $team->invitations()->where('email', $request->email)->first();
            if ($invitation) {
                $invitation->delete();
            }

            Mail::to($member->email->email)->send(new TeamInvitation($team->name, $member->name));

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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            if ($request->invitation_id) {
                $invitation = \Ssntpl\Neev\Models\TeamInvitation::find($request->invitation_id);
                if (!$invitation || !$user->emails->where('email', $invitation->email)->first()?->exists()) {
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
                    if ($team->users->contains($user)) {
                        return response()->json(['message' => 'Already Added.'], 400);
                    }
                    if (!$team->allUsers->contains($user)) {
                        $team->allUsers()->attach($user, ['joined' => true]);
                        if ($invitation->role) {
                            $user->assignRole($invitation->role, $team);
                        }
                    }
                    $invitation->delete();
                    return response()->json([
                        'message' => 'Invitation Accepted Successfully',
                    ]);
                }
            } else {
                /** @var \Ssntpl\Neev\Models\Team|null $team */
                $team = Team::model()->find($request->team_id);
                if ($request->action == 'reject') {
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user_id ?? $request->user()?->id);
        $user?->loadMissing('email');
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            if ($request->has('invitation_id')) {
                $invitation = $team->invitations()->find($request->invitation_id);
                if ($invitation) {
                    $invitation->delete();
                    return response()->json([
                        'message' => 'Invitation Revoked Successfully',
                    ]);
                }
                return response()->json([
                    'message' => 'Invitation not found.',
                ], 400);
            }
            if ($user->id == $team->user_id) {
                return response()->json([
                    'message' => 'You cannot leave from this team.',
                ], 400);
            }

            if ($team->domain?->verified_at && str_ends_with(strtolower($user->email->email), '@' . strtolower($team->domain?->domain))) {
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

            $team->users()->detach($user);
            $user->removeRole($team);

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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            $team?->loadMissing('owner.email');
            if ($team && !$team->domain?->enforce && !$team->domain?->verified_at) {
                if ($team->users->contains($user)) {
                    return response()->json([
                        'message' => 'Already Added.',
                    ], 400);
                }
                if (!$team->allUsers->contains($user)) {
                    $team->allUsers()->attach($user, ['action' => 'request_from_user']);
                }

                Mail::to($team->owner->email->email)->send(new TeamJoinRequest($team->name, $user->name, $team->owner->name, $team->id));

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

    public function requestAction(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        try {
            /** @var \Ssntpl\Neev\Models\Team|null $team */
            $team = Team::model()->find($request->team_id);
            /** @var \Ssntpl\Neev\Models\User|null $member */
            $member = User::model()->find($request->user_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($member);

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
        /** @var \Ssntpl\Neev\Models\Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$team) {
            return response()->json([
                'message' => 'Team not found.',
            ], 400);
        }

        $domains = $team->domains->load('rules');

        // Eager load users with their emails to avoid N+1 queries
        $team->loadMissing('users.email');

        foreach ($domains as $domain) {
            if ($domain->enforce && $domain->verified_at) {
                $count = 0;
                foreach ($team->users as $member) {
                    if (!str_ends_with(strtolower($member->email->email), '@' . strtolower($domain->domain))) {
                        $count++;
                    }
                }
                $domain->outside_members = $count;
            }
        }

        return response()->json([
            'message' => 'Domains fetched successfully.',
            'data' => $domains,
        ]);
    }

    public function domainFederate(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        /** @var \Ssntpl\Neev\Models\Team|null $team */
        $team = Team::model()->find($request->team_id);
        if (!$user || !$team) {
            return response()->json([
                'message' => 'Not found.',
            ], 400);
        }

        if ($team->user_id !== $user->id) {
            //  || !str_ends_with(strtolower($user->email->email), '@' . strtolower($request->domain))
            return response()->json([
                'message' => 'You do not have the required permissions to federate domain.',
            ], 400);
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

            return response()->json([
                'message' => 'Domain federated successfully.',
                'token' => $token
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ]);
        }
    }

    public function updateDomain(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
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
                        $domain->rules()->create([
                            'name' => $rule,
                            'value' => false,
                        ]);
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
                $token = Str::random(32);
                $domain->verification_token = $token;
                $domain->save();

                return response()->json([
                    'message' => 'Domain verification token has been updated.',
                    'token' => $token
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
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'An unexpected error occurred.',
            ], 400);
        }
    }

    public function deleteDomain(Request $request)
    {
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || $domain->owner?->user_id !== $user->id) {
            return response()->json([
                'message' => 'You do not have the required permissions to delete domain.',
            ], 400);
        }
        try {
            $domain->rules()->delete();
            $domain->delete();

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
        /** @var \Ssntpl\Neev\Models\User|null $user */
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || !$domain->owner?->users->contains($user)) {
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
        /** @var \Ssntpl\Neev\Models\User|null $user */
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
