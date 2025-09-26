<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ssntpl\Neev\Models\Role;
use Ssntpl\Neev\Models\User;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role, $resourceType = null)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = User::model()->find($user?->id);

        if (!config('neev.roles')) {
            return $next($request);
        }

        $resourceId = $request->route('team') ?? $request->resource_id;
        if (!$resourceId) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $resource = Role::findResource($resourceType, $resourceId);
        if (!$resource) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        if (!$user->hasAnyRole($role, $resource)) {
            return response()->json(['message' => 'Insufficient permissions'], 403);
        }

        return $next($request);
    }
}
