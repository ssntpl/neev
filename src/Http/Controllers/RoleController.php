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
        if ($request->invitation_id) {
            $this->changeInvitationRole($request);

            return;
        }

        $member = User::model()->find($request->user_id);
        if (!$member) {
            throw new Exception("User not found.");
        }

        // Resolve the role scope: Team, Tenant, or null (global)
        $resource = null;
        if ($request->resource_type && $request->resource_id) {
            $resource = $request->resource_type::find($request->resource_id);
            if (!$resource) {
                throw new Exception("Resource not found.");
            }
        }

        $member->assignRole($request->role, $resource);
    }

    protected function changeInvitationRole(Request $request): void
    {
        $resourceClass = $request->resource_type;
        $resource = $resourceClass::find($request->resource_id);
        if (!$resource) {
            throw new Exception("Resource not found.");
        }

        $invitation = $resource->invitations()->find($request->invitation_id);
        if (!$invitation) {
            throw new Exception("Invitation not found.");
        }

        $invitation->role = $request->role;
        $invitation->save();
    }
}
