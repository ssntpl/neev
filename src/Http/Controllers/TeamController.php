<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Ssntpl\Neev\Models\Permission;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

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
        return view('neev::team.members', [
            'user' => User::find($request->user()->id),
            'team' => $team,
        ]);
    }
    
    public function roles(Request $request, Team $team)
    {
        return view('neev::team.roles', [
            'user' => User::find($request->user()->id),
            'team' => $team,
            'allPermissions' => Permission::orderBy('name')->get()
        ]);
    }
    
    public function settings(Request $request, Team $team)
    {
        return view('neev::team.settings', [
            'user' => User::find($request->user()->id),
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
                $team->users()->attach($user, ['joined' => true]);
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to create team.']);
        }

        return back()->with('status', 'Team Created Successfully.');
    }
    
    public function update(Request $request)
    {
        try {
            $team = Team::find($request->team_id);
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
            $team = Team::find($request->team_id);
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
            $team = Team::find($request->team_id);
            if ($user->id != $team->user_id) {
                return back()->withErrors(['message' => 'You cannot invite member in this team.']);
            }
            $member = User::where('email', $request->email)->first();
            if (!$member) {
                return back()->withErrors(['message' => 'User not found.']);
            } 
            if ($team->users->contains($member)) {
                return back()->with(['status' => 'User already added.']);
            } else if (!$team->allUsers->contains($member)) {
                $team->users()->attach($member, ['role_id' => $request->role_id]);
            }

            //email send
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to invite member.']);
        }

        return back()->with('status', 'Invite link sent successfully.');
    }
    
    public function leave(Request $request)
    {
        $user = User::find($request->user_id ?? $request->user()->id);
        try {
            $team = Team::find($request->team_id);
            if ($user->id == $team->user_id) {
                return back()->withErrors(['message' => 'You cannot leave from this team.']);
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
            $team = Team::find($request->team_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($user);
                return back()->with('status', 'Rejected Successfully');
            } elseif ($request->action == 'accept') {
                $membership = $team->invitedUsers->where('id', $user->id)->first()->membership;
                $membership->joined = true;
                $membership->save();
                return back()->with('status', 'Request Accepted');
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
                $team = Team::where(['name' => $request->team, 'user_id' => $owner->id])->first();
                if ($team) {
                    if ($team->users->contains($user)) {
                        return back()->with('status', 'Already Added.');
                    }
                    if (!$team->allUsers->contains($user)) {
                        $team->allUsers()->attach($user, ['action' => 'request_from_user']);
                    }
                    //send email to owner

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
            $team = Team::find($request->team_id);
            $member = User::find($request->user_id);
            if ($request->action == 'reject') {
                $team->allUsers()->detach($member);
                return back()->with('status', 'Rejected Successfully');
            } elseif ($request->action == 'accept') {
                $membership = $team->joinRequests->where('id', $member->id)->first()->membership;
                $membership->joined = true;
                $membership->role_id = $request->role_id;
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
            $team = Team::find($request->team_id);
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

    public function roleChange(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::find($request->team_id);
            $member = User::find($request->user_id);
            if ($team->owner->id === $user->id) {
                $membership = $team->allUsers->where('id', $member->id)->first()->membership;
                $membership->role_id = $request->role_id;
                $membership->save();
                return back()->with('status', 'Role has been changed.');
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to proccess change role request.']);
        }

        return back()->withErrors(['message' => 'You cannot change role.']);
    }
}
