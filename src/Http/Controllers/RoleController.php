<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Schema;
use Ssntpl\Neev\Models\Permission;
use Ssntpl\Neev\Models\Role;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;

class RoleController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();
        try {
                $user = User::find($user->id);
                $team = Team::model()->find($request->team_id);
                $team->roles()->updateOrCreate([
                    'name' => $request->name
                ], [
                    'name' => $request->name
                ]);
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to create role.']);
        }

        return back()->with('status', 'Role Created Successfully.');
    }
   
    public function delete(Request $request)
    {
        try {
            $team = Team::model()->find($request->team_id);
            $role = $team->roles()->find($request->role_id);
            if (!$role) {
                return back()->withErrors(['message' => 'Role not found.']);
            }
            $role->delete();
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to delete role.']);
        }

        return back()->with('status', 'Role has been deleted Successfully.');
    }

    public function updatePermission(Request $request)
    {
        try { 
            $role = Role::findOrFail($request->role_id);

            $permissions = explode(',', $request->permissions);
            $role->syncPermissions($permissions);
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to update permissions']);
        }

        return back()->with('status', 'Permissions updated successfully.');
    }

    public function deletePermission(Request $request)
    {
        try { 
            $permission = Permission::find($request->permission_id);
            $permission->delete();
        } catch (Exception $e) {
            return back()->withErrors(['p_message' => 'Failed to delete permission']);
        }

        return back()->with('p_status', 'Permission has been deleted successfully.');
    }

    public function storePermission(Request $request)
    {
        try { 
            Permission::create([
                'name' => $request->name
            ]);
        } catch (Exception $e) {
            return back()->withErrors(['p_message' => 'Failed to add permission']);
        }

        return back()->with('p_status', 'Permission has been added successfully.');
    }

    public function roleChange(Request $request)
    {
        $user = User::find($request->user()->id);
        try {
            $team = Team::model()->find($request->team_id);
            if ($team->owner->id === $user->id || (Schema::hasColumn('team_user', 'role') && $user->teams->find($team->id)->membership->role === 'admin')) {
                if ($request->user_id) {
                    $member = User::find($request->user_id);
                    $membership = $team->allUsers->where('id', $member->id)->first()->membership;
                    if (config('neev.roles')) {
                        $membership->role_id = $request->role_id;
                    } else if (Schema::hasColumn('team_user', 'role')) {
                        $membership->role = $request->role;
                    }
                    $membership->save();
                } else if ($request->invitation_id) {
                    $invitation = $team->invitations()->find($request->invitation_id);
                    if (config('neev.roles')) {
                        $invitation->role_id = $request->role_id;
                    } else if (Schema::hasColumn('team_invitations', 'role')) {
                        $invitation->role = $request->role;
                    }
                    $invitation->save();
                }
                return back()->with('status', 'Role has been changed.');
            }
        } catch (Exception $e) {
            return back()->withErrors(['message' => 'Failed to proccess change role request.']);
        }

        return back()->withErrors(['message' => 'You cannot change role.']);
    }
}
