<?php

namespace Ssntpl\Neev\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Ssntpl\Neev\Models\Role;
use Ssntpl\Neev\Models\User;

class RoleOrPermissionMiddleware
{
    public function handle(Request $request, Closure $next, $roleOrPermission, $resourceType = null)
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $user = User::model()->find($user?->id);

        $resourceId = $request->route('team') ?? $request->resource_id;
        if (!$resourceId) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $resource = Role::findResource($resourceType, $resourceId);
        if (!$resource) {
            return response()->json(['message' => 'Resource not found'], 404);
        }

        $userRole = $user->role($resource)->first();
        if (!$userRole) {
            return response()->json(['message' => 'Insufficient permissions'], 403);
        }

        $items = explode('|', $roleOrPermission);
        
        foreach ($items as $item) {
            $item = trim($item);
            if ($user->hasRole($item, $resource) || $userRole->role->permissions()->where('name', $item)->exists()) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Insufficient permissions'], 403);
    }
}
