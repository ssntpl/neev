<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Log;
use Ssntpl\Permissions\Models\Role;
use Ssntpl\Neev\Models\User;

class RoleController extends Controller
{
    public function roleChange(Request $request)
    {
        try {
            $resource = Role::findResource($request->resource_type ?? '', $request->resource_id);
            if ($request->user_id) {
                $member = User::model()->find($request->user_id);
                if (class_basename($request->resource_type) == "Team") {
                    $membership = $resource->allUsers->where('id', $member->id)->first()->membership;
                    $membership->role = $request->role;
                    $membership->save();
                }
                if ($request->resource_type) {
                    $member->assignRole($request->role, $resource);
                }
            } else if ($request->invitation_id) {
                $invitation = $resource->invitations()->find($request->invitation_id);
                $invitation->role = $request->role;
                $invitation->save();
            }

            return back()->with('status', 'Role has been changed.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to proccess change role request.']);
        }
    }
}
