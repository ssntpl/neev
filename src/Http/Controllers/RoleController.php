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
                'message' => 'Role has been changed.'
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Failed to process change role request.'
            ], 400);
        }
    }

    public function changeRole(Request $request)
    {
        $actor = User::model()->find($request->user()?->id);
        if (!$actor) {
            throw new Exception('Resource not found.');
        }

        $resource = $this->resourceFor($request, $actor);

        if ($request->invitation_id) {
            $this->changeInvitationRole($request, $resource);
            return;
        }

        $member = User::model()->find($request->user_id);
        if (!$member || !$resource->hasMember($member)) {
            throw new Exception("User not found.");
        }

        $member->assignRole($request->role, $resource);
    }

    protected function resourceFor(Request $request, User $actor)
    {
        $resource = $request->resource_type::find($request->resource_id);
        if (!$resource) {
            throw new Exception('Resource not found.');
        }

        $permitted = $resource->hasMember($actor);

        if (!$permitted) {
            throw new Exception('Resource not found.');
        }

        return $resource;
    }

    protected function changeInvitationRole(Request $request, $resource): void
    {
        $invitation = $resource->invitations()->find($request->invitation_id);
        if (!$invitation) {
            throw new Exception("Invitation not found.");
        }

        $invitation->role = $request->role;
        $invitation->save();
    }
}
