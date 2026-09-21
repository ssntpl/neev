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
        if (!$member || !$this->isAttachedTo($resource, $member)) {
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

    /**
     * Members and pending members alike may hold a role.
     *
     * `hasMember()` answers joined-only, but a role can be granted before the
     * user has joined — `addMember()` does exactly that, and the members page
     * renders the role control for invited users and join requests. Asking
     * `hasMember()` here refused a row the page had just drawn, with
     * "User not found." The invitation branch above has always allowed a
     * pending role to change; this is the same rule for a pending membership.
     *
     * A user attached in no state at all is still refused: a role scoped to a
     * resource is meaningless for somebody outside it.
     */
    protected function isAttachedTo($resource, User $member): bool
    {
        if ($resource->hasMember($member)) {
            return true;
        }

        if (!method_exists($resource, 'allUsers')) {
            return false;
        }

        return $resource->allUsers()
            ->where('users.id', $member->getKey())
            ->exists();
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
