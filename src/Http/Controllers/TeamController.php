<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Ssntpl\LaravelAcl\Models\Role;
use Ssntpl\Neev\Mail\TeamInvitation;
use Ssntpl\Neev\Mail\TeamJoinRequest;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

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
        $user = User::model()->find($request->user()?->id);
        if (!$user?->allTeams?->find($team->id)) {
            return back()->withErrors(['message' => 'You have not required permissions to view members.']);
        }
        return view('neev::team.members', [
            'user' => $user,
            'team' => $team,
            'teamRoles' => Role::where('resource_type', Team::class)->get(),
        ]);
    }
    
    public function domain(Request $request, Team $team)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user?->allTeams?->find($team->id) || $team->user_id !== $user?->id || !config('neev.domain_federation')) {
            return back()->withErrors(['message' => 'You have not required permissions to view domain federation.']);
        }

        $domains = $team->domains;
        foreach ($domains ?? [] as $domain) {
            $count = 0;
            if ($domain?->enforce && $domain?->verified_at) {
                foreach ($team->users ?? [] as $member) {
                    if (!str_ends_with(strtolower($member->email?->email), '@' . strtolower($domain?->domain))) {
                        $count++;
                    }
                }
            }
            $domain->outside_members = $count;
        }

        return view('neev::team.domain-federation', [
            'user' => $user,
            'team' => $team,
            'domains' => $domains,
        ]);
    }
    
    public function settings(Request $request, Team $team)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user?->allTeams?->find($team->id)) {
            return back()->withErrors(['message' => 'You have not required permissions to view settings.']);
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
            $user = User::model()->find($user->id);
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
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to update team.']);
        }

        return back()->with('status', 'Team Updated Successfully.');
    }
    
    public function delete(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
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

        return redirect(route('teams.profile', $user?->ownedTeams[0]?->id));
    }
    
    public function inviteMember(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($user->id != $team->user_id || ($team->domain?->enforce && $team->verified_at && !str_ends_with(strtolower($request->email), '@' . strtolower($team->domain?->domain)))) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
            }
            $email = Email::where('email', $request->email)->first();
            $member = $email?->user;
            if (!$member) {
                $expiry = now()->addDays(7);

                $invitation = $team->invitations()->updateOrCreate(
                    ['email' => $request->email],
                    ['expires_at' => $expiry]
                );

                if(!$invitation) {
                    return back()->withErrors(['message' => 'Failed to create invitation.']);
                }

                $invitation->role = $request->role;
                $invitation->save();

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
                $team->users()->attach($member, ['role' => $request->role]);
                if ($request->role) {
                    $member->assignRole($request->role, $team);
                }
            }

            $invitation =$team->invitations()->where('email', $request->email)->first();
            if ($invitation) {
                $invitation->delete();
            }

            Mail::to($member->email->email)->send(new TeamInvitation($team->name, $member->name));
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to invite member.']);
        }
        
        return back()->with('status', 'Invite link sent successfully.');
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
                    return back()->with('status', 'Invitation Revoked Successfully');
                }
                return back()->withErrors(['message' => 'Invitation not found.']);
            }
            if ($user->id == $team->user_id) {
                return back()->withErrors(['message' => 'You cannot leave from this team.']);
            }
            
            if ($team->domain?->verified_at && str_ends_with(strtolower($user->email->email), '@' . strtolower($team->domain?->domain))) {
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
        $user = User::model()->find($request->user()?->id);
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
                        $team->allUsers()->attach($user, ['joined' => true, 'role' => $invitation->role]);
                        if ($invitation?->role) {
                            $user->assignRole($invitation->role, $team);
                        }
                    }
                    $invitation->delete();
                    return back()->with('status', 'Invitation Accepted');
                }
            } else {
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
        $user = User::model()->find($request->user()?->id);
        try {
            $email = Email::where('email', $request->email)->first();
            $owner = $email?->user;
            if ($owner) {
                $team = Team::model()->where(['name' => $request->team, 'user_id' => $owner->id])->first();
                if ($team && !$team->domain?->enforce && !$team->domain?->verified_at) {
                    if ($team->users->contains($user)) {
                        return back()->with('status', 'Already Added.');
                    }
                    if (!$team->allUsers->contains($user)) {
                        $team->allUsers()->attach($user, ['action' => 'request_from_user']);
                    }
                    
                    Mail::to($owner->email->email)->send(new TeamJoinRequest($team->name, $user->name, $owner->name, $team->id));

                    return back()->with('status', 'Request has been sent.');
                }
            }

        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to Send Request.']);
        }
        
        return back()->withErrors(['message' => 'Team not found.']);
    }

    public function requestAction(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::model()->find($request->user_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($member);
                return back()->with('status', 'Rejected Successfully');
            } elseif ($request->action == 'accept') {
                $membership = $team->joinRequests->where('id', $member->id)->first()->membership;
                $membership->joined = true;
                $membership->role = $request->role;
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
        $user = User::model()->find($request->user()?->id);
        try {
            $team = Team::model()->find($request->team_id);
            $member = User::model()->find($request->user_id);
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
        $user = User::model()->find($request->user()?->id);
        if (!$user || $team->user_id !== $user?->id) {
            //  || !str_ends_with(strtolower($user->email->email), '@' . strtolower($request->domain))
            return back()->withErrors(['message' => 'You have not required permissions to federate domain.']);
        }
        try {
            $token = Str::random(32);
            $team->domains()->updateOrCreate([
                'domain' => $request->domain
            ],[
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
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain?->team->user_id !== $user?->id) {
            return back()->withErrors(['message' => 'You have not required permissions to update domain.']);
        }
        try {
            if ($request->verify) {
                $res = $this->verify($domain);
                $domain_rules = ["mfa"];
                if ($res) {
                    foreach ($domain_rules ?? [] as $rule) {
                        $domain?->rules()->create([
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
    
    public function deleteDomain(Request $request, Domain $domain)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain?->team->user_id !== $user?->id) {
            return back()->withErrors(['message' => 'You have not required permissions to delete domain.']);
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
        $user = User::model()->find($request->user()?->id);
        if (!$user || $domain?->team->user_id !== $user?->id) {
            return back()->withErrors(['message' => 'You have not required permissions to update domain.']);
        }
        try {
            foreach ($domain?->rules ?? [] as $rule) {
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
        $user = User::model()->find($request->user()?->id);
        $domain = Domain::find($request->domain_id);
        if (!$user || !$domain || !$domain?->verified_at || !$domain->team->users->contains($user)) {
            return back()->withErrors(['message' => 'Primary domain was not changed.']);
        }
        
        $pdomain = $domain->team?->domain;
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
