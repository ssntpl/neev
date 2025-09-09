<?php

namespace Ssntpl\Neev\Http\Controllers;

use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Mail;
use Schema;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Permission;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Str;
use URL;

class TeamController extends Controller
{
    public function profile(Request $request, Team $team)
    {
        return view('neev::team.profile', [
            'user' => User::find($request->user()->id),
            'team' => $team,
        ]);
    }
    
    public function members(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if (!$user->allTeams->find($team->id)) {
            return response(null, 404);
        }
        return view('neev::team.members', [
            'user' => $user,
            'team' => $team,
        ]);
    }
    
    public function roles(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if (!$user->allTeams->find($team->id)) {
            return response(null, 404);
        }
        return view('neev::team.roles', [
            'user' => $user,
            'team' => $team,
            'allPermissions' => Permission::orderBy('name')->get()
        ]);
    }
    
    public function domain(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if (!$user->allTeams->find($team->id) || $team->user_id !== $user->id || !config('neev.domain_federation')) {
            return response(null, 404);
        }

        $count = 0;
        if ($team->enforce_domain && $team->domain_verified_at) {
            foreach ($team->users ?? [] as $member) {
                if (!str_ends_with(strtolower($member->email), '@' . strtolower($team->federated_domain))) {
                    $count++;
                }
            }
        }

        return view('neev::team.domain-federation', [
            'user' => $user,
            'team' => $team,
            'outside_members' => $count,
        ]);
    }
    
    public function settings(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if (!$user->allTeams->find($team->id)) {
            return response(null, 404);
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
            $user = User::find($user->id);
            $team = $user->ownedTeams()->forceCreate([
                'name' => $request->name,
                'is_public' => (bool) $request->public,
            ]);
            if (Schema::hasColumn('team_invitations', 'role')) {
                $team->users()->attach($user, ['joined' => true, 'role' => 'admin']);
            } else {
                $team->users()->attach($user, ['joined' => true]);
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to create team.']);
        }

        return back()->with('status', 'Team Created Successfully.');
    }
    
    public function update(Request $request)
    {
        try {
            $team = Team::model()->find($request->team_id);
            $team->name = $request->name;
            $team->is_public = (bool) $request->public;
            $team->save();
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to update team.']);
        }

        return back()->with('status', 'Team Updated Successfully.');
    }
    
    public function delete(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || count($user->ownedTeams) < 2) {
                return back()->withErrors(['message' => 'You cannot delete this team.']);
            }
            $team->delete();
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to delete team.']);
        }

        return redirect(route('teams.profile', $user->ownedTeams[0]->id));
    }
    
    public function inviteMember(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::model()->find($request->team_id);
            if (Schema::hasColumn('team_user', 'role')) {
                if ($user->teams->find($team->id)->membership->role !== 'admin') {
                    return back()->withErrors(['message' => 'You cannot invite member in this team.']);
                }
            } else if ($user->id != $team->user_id || ($team->enforce_domain && $team->domain_verified_at && !str_ends_with(strtolower($request->email), '@' . strtolower($team->federated_domain)))) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
            }
            $member = User::where('email', $request->email)->first();
            if (!$member) {
                $expiry = Carbon::now()->addDays(7);

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry]
                );

                if(!$invitation) {
                    return back()->withErrors(['message' => 'Failed to create invitation.']);
                }

                if (config('neev.roles')) {
                    $invitation->role_id = $request->role_id;
                    $invitation->save();
                } else if (Schema::hasColumn('team_invitations', 'role')) {
                    $invitation->role = $request->role;
                    $invitation->save();
                }

                $signedUrl = URL::temporarySignedRoute(
                    'register',
                    $expiry,
                    ['id' => $invitation->id, 'hash' => sha1($request->email)]
                );

                Mail::to($request->email)->send(new TeamInvitation($team->name, 'there', $signedUrl, $expiry, false));
                return back()->with('status', 'Invite link sent successfully.');
            } 
            if ($team->users->contains($member)) {
                return back()->with(['status' => 'User already added.']);
            } else if (!$team->allUsers->contains($member)) {
                if (config('neev.roles')) {
                    $team->users()->attach($member, ['role_id' => $request->role_id]);
                } else if (Schema::hasColumn('team_user', 'role')) {
                    $team->users()->attach($member, ['role' => $request->role]);
                } else {
                    $team->users()->attach($member);
                }
            }

            $invitation =$team->invitations()->where('email', $request->email)->first();
            if ($invitation) {
                $invitation->delete();
            }

            Mail::to($member->email)->send(new TeamInvitation($team->name, $member->name));
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to invite member.']);
        }
        
        return back()->with('status', 'Invite link sent successfully.');
    }
    
    public function leave(Request $request)
    {
        $user = User::find($request->user_id ?? $request->user()->id);
        try {
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
            
            if ($team->domain_verified_at && str_ends_with(strtolower($user->email), '@' . strtolower($team->federated_domain))) {
                if ($user->active) {
                    $user->deactivate();
                    return back()->with('status', 'User Deactivated Successfully');
                } else {
                    $user->activate();
                    return back()->with('status', 'User Activated Successfully');
                }
            }

            $team->users()->detach($user);
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to leave from team.']);
        }
        
        if ($request->user()->id == $request->user_id) {
            return redirect(route('account.teams'));
        }
        return back()->with('status', 'Removed Successfully');
    }
    
    public function inviteAction(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            if ($request->invitation_id) {
                $invitation = \Ssntpl\Neev\Models\TeamInvitation::find($request->invitation_id);
                $team = $invitation->team;
                if ($request->action == 'reject') {
                    $invitation->delete();
                    return back()->with('status', 'Invitation Revoked Successfully');
                } elseif ($request->action == 'accept') {
                    if ($team->users->contains($user)) {
                        return back()->with('status', 'Already Added.');
                    }
                    if (!$team->allUsers->contains($user)) {
                        if (config('neev.roles')) {
                            $team->allUsers()->attach($user, ['joined' => true, 'role_id' => $invitation->role_id]);
                        } else if (Schema::hasColumn('team_user', 'role')) {
                            $team->allUsers()->attach($user, ['joined' => true, 'role' => $invitation->role]);
                        }
                    }
                    $invitation->delete();
                    return back()->with('status', 'Invitation Accepted');
                }
            } else {
                $team = Team::model()->find($request->team_id);
                if ($request->action == 'reject') {
                    $team->allUsers()->detach($user);
                    return back()->with('status', 'Rejected Successfully');
                } elseif ($request->action == 'accept') {
                    $membership = $team->invitedUsers->where('id', $user->id)->first()->membership;
                    $membership->joined = true;
                    $membership->save();
                    return back()->with('status', 'Request Accepted');
                }
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to Accept/Reject Request.']);
        }
        
        return back()->with('status', 'Successfully');
    }
    
    public function request(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $owner = User::where('email', $request->email)->first();
            if ($owner) {
                $team = Team::model()->where(['name' => $request->team, 'user_id' => $owner->id])->first();
                if ($team && !$team->enforce_domain && !$team->domain_verified_at) {
                    if ($team->users->contains($user)) {
                        return back()->with('status', 'Already Added.');
                    }
                    if (!$team->allUsers->contains($user)) {
                        $team->allUsers()->attach($user, ['action' => 'request_from_user']);
                    }
                    
                    Mail::to($owner->email)->send(new TeamJoinRequest($team->name, $user->name, $owner->name, $team->id));

                    return back()->with('status', 'Request has been sent.');
                }
            }

        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to Send Request.']);
        }
        
        return back()->withErrors(['message' => 'Team not found.']);
    }

    public function requestAction(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::find($request->user_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($member);
                return back()->with('status', 'Rejected Successfully');
            } elseif ($request->action == 'accept') {
                $membership = $team->joinRequests->where('id', $member->id)->first()->membership;
                $membership->joined = true;
                if (config('neev.roles')) {
                    $membership->role_id = $request->role_id;
                } else if (Schema::hasColumn('team_user', 'role')) {
                    $membership->role = $request->role;
                }
                $membership->save();
                return back()->with('status', 'Request Accepted');
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to Accept/Reject Request.']);
        }

        return back()->with('status', 'Successfully');
    }

    public function ownerChange(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::find($request->user_id);
            if ($team->owner->id === $user->id) {
                $team->user_id = $member->id;
                $team->save();
                return back()->with('status', 'Owner has been changed.');
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to proccess change owner request.']);
        }

        return back()->withErrors(['message' => 'You cannot change owner.']);
    }
    
    public function federateDomain(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if ($team->user_id !== $user->id || !str_ends_with(strtolower($user->email), '@' . strtolower($request->domain))) {
            return back()->withErrors(['message' => 'You have not required permissions to federate domain.']);
        }
        try {
            $token = Str::random(32);
            $team->federated_domain = $request->domain;
            $team->enforce_domain = (bool) $request->enforce;
            $team->domain_verification_token = $token;
            $team->save();
            return back()->with('token', $token);
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to federate domain.']);
        }
    }
    
    public function updateDomain(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if ($team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You have not required permissions to update domain.']);
        }
        try {
            if ($request->verify) {
                $res = $this->verify($team);
                if ($res) {
                    foreach (config('neev.domain_rules') ?? [] as $rule) {
                        $team->rules()->create([
                            'name' => $rule,
                            'value' => DomainRule::ruleDefaultValue($rule) ?? null,
                        ]);
                    }
                    return back()->with('status', 'Domain verified successfully!');
                }
                return back()->withErrors(['message' => 'DNS record not found. Please try again later.']);
            }

            if ($request->token) {
                $token = Str::random(32);
                $team->domain_verification_token = $token;
                $team->save();
                return back()->with('token', $token);
            }
            
            $team->enforce_domain = (bool) $request->enforce;
            $team->save();
            return back()->with('status', 'domain has been updated.');
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to update domain.']);
        }
    }

    public function verify($team)
    {
        $records = dns_get_record($team->federated_domain, DNS_TXT);
        $verified = collect($records)->pluck('txt')->contains($team->domain_verification_token);

        if ($verified) {
            $team->domain_verified_at = now();
            $team->save();
            return true;
        }

        return false;
    }
    
    public function deleteDomain(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if ($team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You have not required permissions to delete domain.']);
        }
        try {
            $team->federated_domain = null;
            $team->enforce_domain = false;
            $team->domain_verification_token = null;
            $team->domain_verified_at = null;
            $team->save();
            $team->rules()->delete();
            return back()->with('status', 'Domain has been delete.');
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to delete domain.']);
        }
    }
    
    public function updateDomainRule(Request $request, Team $team)
    {
        $user = User::find($request->user()->id);
        if ($team->user_id !== $user->id) {
            return back()->withErrors(['message' => 'You have not required permissions to update domain.']);
        }
        try {
            foreach ($team->rules ?? [] as $rule) {
                if ($rule->ruleType($rule->name) === 'bool') {
                    $rule->value = (bool) $request->{$rule->name};
                } elseif ($rule->ruleType($rule->name) === 'number') {
                    $rule->value = (int) $request->{$rule->name};
                } else {
                    $rule->value = $request->{$rule->name};
                }

                $rule->save();
            }
            
            return back()->with('status', 'Domain Rules have been updated.');
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to update domain rules.']);
        }
    }
}
