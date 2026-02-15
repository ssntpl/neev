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
    public function teams(Request $request)
    {
        $teams = User::model()->find($request->user()?->id)?->teams?->load('owner');

        return response()->json([
            'status' => 'Success',
            'data' => $teams,
        ]);
    }

    public function getTeam(Request $request, $id)
    {
        $team = Team::model()->find($id);
        if (!$team) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Team not found',
            ], 400);
        }

        if (!$team->hasUser($request->user())) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Team not found',
            ], 400);
        }

        $team->load('owner', 'users', 'joinRequests', 'invitedUsers', 'invitations');

        return response()->json([
            'status' => 'Success',
            'data' => $team,
        ]);
    }

    public function createTeam(Request $request)
    {
        $user = $request->user();
        try {
            $user = User::model()->find($user?->id);
            if (!$user) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'User not found',
                ], 400);
            }
            $team = $user->ownedTeams()->forceCreate([
                'name' => $request->name,
                'is_public' => (bool) $request->public,
            ]);

            $team->users()->attach($user, ['joined' => true]);
            if ($team->default_role) {
                $user->assignRole($team->default_role ?? '', $team);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        $team->load('owner', 'users');

        return response()->json([
            'status' => 'Success',
            'data' => $team,
        ]);
    }

    public function updateTeam(Request $request)
    {
        try {
            $team = Team::model()->find($request->team_id);
            if (!$team) {
                return response()->json([
                    'status' => 'Failed',
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
                'status' => 'Success',
                'message' => 'Team has been updated.',
                'data' => $team,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function deleteTeam(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || count($user->ownedTeams) < 2) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'You cannot delete this team.',
                ], 400);
            }
            $user->removeRole($team);
            $team->delete();

            return response()->json([
                'status' => 'Success',
                'message' => 'Team has been deleted.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function changeTeamOwner(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::model()->find($request->user_id);
            if (!$user || !$team || !$member) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'not found',
                ], 400);
            }
            if (!$team->hasUser($member)) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'This user is not the member in this team.',
                ], 400);
            }
            if ($team->owner->id === $user->id) {
                $team->user_id = $member->id;
                $team->save();
                return response()->json([
                    'status' => 'Success',
                    'message' => $member->name . ' is now the owner of the team.',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => 'Failed',
            'message' => 'You cannot change owner.',
        ], 400);
    }

    public function inviteMember(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || ($team->domain?->enforce && $team->domain?->verified_at && !str_ends_with(strtolower($request->email), '@' . strtolower($team->domain?->domain)))) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'You cannot invite member in this team.',
                ], 400);
            }
            $email = Email::where('email', $request->email)->first();
            $member = $email?->user;
            if (!$member) {
                $expiry = now()->addDays(7);

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry]
                );

                if (!$invitation) {
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Failed to create invitation.',
                    ], 400);
                }

                $invitation->role = $request->role;
                $invitation->save();

                $signedUrl = URL::temporarySignedRoute(
                    'register',
                    $expiry,
                    ['id' => $invitation->id, 'hash' => sha1($request->email)]
                );

                Mail::to($request->email)->send(new TeamInvitation($team->name, 'there', $signedUrl, $expiry, false));
                return response()->json([
                    'status' => 'Success',
                    'message' => 'Invite link sent successfully.',
                    'data' => $invitation
                ]);
            }
            if ($team->users->contains($member)) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'User already added.',
                ], 400);
            } elseif (!$team->allUsers->contains($member)) {
                $team->users()->attach($member, ['role' => $request->role]);
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
                'status' => 'Success',
                'message' => 'Invite link sent successfully.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function inviteAction(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            if ($request->invitation_id) {
                $invitation = \Ssntpl\Neev\Models\TeamInvitation::find($request->invitation_id);
                if (!$invitation || !$user->emails->contains($invitation->email)) {
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Invitation not found',
                    ], 400);
                }
                $team = $invitation->team;
                if ($request->action == 'reject') {
                    $invitation->delete();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Invitation Revoked Successfully',
                    ]);
                } elseif ($request->action == 'accept') {
                    if ($team->users->contains($user)) {
                        return response()->json(['status' => 'Failed', 'message' => 'Already Added.'], 400);
                    }
                    if (!$team->allUsers->contains($user)) {
                        $team->allUsers()->attach($user, ['joined' => true, 'role' => $invitation->role]);
                        if ($invitation?->role) {
                            $user->assignRole($invitation->role, $team);
                        }
                    }
                    $invitation->delete();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Invitation Accepted Successfully',
                    ]);
                }
            } else {
                $team = Team::model()->find($request->team_id);
                if ($request->action == 'reject') {
                    $team->allUsers()->detach($user);
                    $user->removeRole($team);
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Invitation Rejected Successfully',
                    ]);
                } elseif ($request->action == 'accept') {
                    $membership = $team->invitedUsers->where('id', $user->id)->first()?->membership;
                    if (!$membership) {
                        return response()->json([
                            'status' => 'Failed',
                            'message' => 'Invitation not found',
                        ], 400);
                    }
                    $membership->joined = true;
                    $membership->save();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Invitation Accepted Successfully',
                    ]);
                }
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => 'Failed',
            'message' => 'Invalid Action.',
        ], 400);
    }

    public function leave(Request $request)
    {
        $user = User::model()->find($request->user_id ?? $request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($request->has('invitation_id')) {
                $invitation = $team->invitations()->find($request->invitation_id);
                if ($invitation) {
                    $invitation->delete();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Invitation Revoked Successfully',
                    ]);
                }
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'Invitation not found.',
                ], 400);
            }
            if ($user->id == $team->user_id) {
                return response()->json([
                    'status' => 'Failed',
                    'message' => 'You cannot leave from this team.',
                ], 400);
            }

            if ($team->domain?->verified_at && str_ends_with(strtolower($user->email->email), '@' . strtolower($team->domain?->domain))) {
                if ($user->active) {
                    $user->deactivate();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'User Deactivated Successfully',
                    ]);
                } else {
                    $user->activate();
                    return response()->json([
                        'status' => 'Success',
                        'message' => 'User Activated Successfully',
                    ]);
                }
            }

            $team->users()->detach($user);
            $user->removeRole($team);

            return response()->json([
                'status' => 'Success',
                'message' => 'Removed Successfully',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function request(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($team && !$team->domain?->enforce && !$team->domain?->verified_at) {
                if ($team->users->contains($user)) {
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Already Added.',
                    ], 400);
                }
                if (!$team->allUsers->contains($user)) {
                    $team->allUsers()->attach($user, ['action' => 'request_from_user']);
                }

                Mail::to($team->owner->email->email)->send(new TeamJoinRequest($team->name, $user->name, $team->owner->name, $team->id));

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Request sent successfully.',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => 'Failed',
            'message' => 'Team not found.',
        ], 400);
    }

    public function requestAction(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::model()->find($request->user_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($member);

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Rejected Successfully',
                ]);
            } elseif ($request->action == 'accept') {
                $membership = $team->joinRequests->where('id', $member->id)->first()?->membership;
                if (!$membership) {
                    return response()->json([
                        'status' => 'Failed',
                        'message' => 'Request not found',
                    ], 400);
                }
                $membership->joined = true;
                $membership->role = $request->role;
                $membership->save();
                if ($request->role) {
                    $member->assignRole($request->role, $team);
                }

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Accepted Successfully',
                ]);
            }
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'status' => 'Failed',
            'message' => 'Invalid Action.',
        ], 400);
    }

    public function getDomains(Request $request)
    {
        $team = Team::model()->find($request->team_id);
        if (!$team) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Team not found.',
            ], 400);
        }

        $domains = $team->domains->load('rules');

        foreach ($domains as $domain) {
            if ($domain->enforce && $domain->verified_at) {
                $count = 0;
                foreach ($team->users ?? [] as $member) {
                    if (!str_ends_with(strtolower($member->email->email), '@' . strtolower($domain->domain))) {
                        $count++;
                    }
                }
                $domain->outside_members = $count;
            }
        }

        return response()->json([
            'status' => 'Success',
            'message' => 'Domains fetched successfully.',
            'data' => $domains,
        ]);
    }

    public function domainFederate(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $team = Team::model()->find($request->team_id);
        if (!$user || !$team) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Not found.',
            ], 400);
        }

        if ($team->user_id !== $user->id) {
            //  || !str_ends_with(strtolower($user->email->email), '@' . strtolower($request->domain))
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to federate domain.',
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
                'status' => 'Success',
                'message' => 'Domain federated successfully.',
                'token' => $token
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function updateDomain(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || $domain?->team->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to update domain.',
            ], 400);
        }
        try {
            if ($request->verify) {
                $res = $this->verify($domain);
                if ($res) {
                    $domain_rules = ["mfa"];
                    foreach ($domain_rules ?? [] as $rule) {
                        $domain?->rules()->create([
                            'name' => $rule,
                            'value' => false,
                        ]);
                    }

                    return response()->json([
                        'status' => 'Success',
                        'message' => 'Domain verified successfully!',
                    ]);
                }

                return response()->json([
                    'status' => 'Failed',
                    'message' => 'DNS record not found. Please try again later.',
                ], 400);
            }

            if ($request->token) {
                $token = Str::random(32);
                $domain->verification_token = $token;
                $domain->save();

                return response()->json([
                    'status' => 'Success',
                    'message' => 'Domain verification token has been updated.',
                    'token' => $token
                ]);
            }

            if (isset($request->enforce)) {
                $domain->enforce = (bool) $request->enforce;
            }
            $domain->save();

            return response()->json([
                'status' => 'Success',
                'message' => 'Domain has been updated.',
                'data' => $domain
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function verify($domain)
    {
        $records = dns_get_record($domain?->domain, DNS_TXT);
        $verified = collect($records)->pluck('txt')->contains($domain?->verification_token);

        if ($verified) {
            $domain->verification_token = null;
            $domain->verified_at = now();
            $domain->save();
            return true;
        }

        return false;
    }

    public function deleteDomain(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || $domain?->team?->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to delete domain.',
            ], 400);
        }
        try {
            $domain->rules()->delete();
            $domain->delete();

            return response()->json([
                'status' => 'Success',
                'message' => 'Domain has been deleted.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function updateDomainRule(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || $domain?->team?->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to update domain.',
            ], 400);
        }
        try {
            foreach ($domain?->rules ?? [] as $rule) {
                if (isset($request->{$rule->name})) {
                    $rule->value = (bool) $request->{$rule->name};
                }
                $rule->save();
            }

            return response()->json([
                'status' => 'Success',
                'message' => 'Domain Rules have been updated.',
                'data' => $domain->rules
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function getDomainRule(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$domain || !$user || !$domain->team?->users->contains($user)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to get domain rules.',
            ], 400);
        }

        return response()->json([
            'status' => 'Success',
            'data' => $domain->rules
        ]);
    }

    public function primaryDomain(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || !$domain?->verified_at || !$domain->team?->users->contains($user)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You have not required permissions to change primary domain.',
            ], 400);
        }

        $pdomain = $domain->team->domain;
        if ($pdomain) {
            if ($pdomain->id == $domain->id) {
                return response()->json([
                    'status' => 'Success',
                    'message' => 'Primary domain was aready changed.',
                ]);
            }
            $pdomain->is_primary = false;
            $pdomain->save();
        }
        $domain->is_primary = true;
        $domain->save();

        return response()->json([
            'status' => 'Success',
            'message' => $domain->domain . ' has been set as primary domain.',
        ]);
    }
}
