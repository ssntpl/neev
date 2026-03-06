<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\User;

class RoleController extends Controller
{
    public function roleChange(Request $request)
    {
        try {
            $this->changeRole($request);
            return back()->with('status', 'Role has been changed.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Failed to process change role request.']);
        }
    }

    public function roleChangeViaAPI(Request $request)
    {
        try {
            $this->changeRole($request);
            return response()->json([
                'status' => 'Success',
                'message' => 'Role has been changed.'
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Failed to process change role request.'
            ], 400);
        }
    }

    public function changeRole(Request $request)
    {
        $resourceClass = $request->resource_type;
        $resource = $resourceClass::find($request->resource_id);
        if (!$resource) {
            throw new Exception("Resource not found.");
        }
        if ($request->user_id) {
            $member = User::model()->find($request->user_id);
            if (!$member) {
                throw new Exception("User not found.");
            }
            if (class_basename($request->resource_type) == "Team") {
                $membership = $resource->allUsers->where('id', $member->id)->first()?->membership;
                if (!$membership) {
                    throw new Exception("User is not a member of this team.");
                }
                $membership->role = $request->role;
                $membership->save();
            }
        } elseif ($request->invitation_id) {
            $invitation = $resource->invitations()->find($request->invitation_id);
            if (!$invitation) {
                throw new Exception("Invitation not found.");
            }
            $invitation->role = $request->role;
            $invitation->save();
        }
    }
}
