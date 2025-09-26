<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\ModelResourceRole;
use Ssntpl\Neev\Models\Role;

trait HasRoles
{
    public function roles()
    {
        if (!config('neev.roles')) {
            return [];
        }
        return Role::where('resource_type', class_basename($this))->get();
    }

    public function modelResourceRoles()
    {
        return $this->morphMany(ModelResourceRole::class, 'model');
    }

    public function role($resource)
    {
        return $this->morphOne(ModelResourceRole::class, 'model')
            ->where('resource_id', $resource->id)
            ->where('resource_type', class_basename($resource))
            ->with('role');
    }

    public function assignRole($role, $resource)
    {
        if (is_string($role)) {
            $role = Role::where('name', $role)->where('resource_type', class_basename($resource))->firstOrFail();
        }

        $currentRole = $this->role($resource);
        if ($currentRole->first()) {
            $currentRole = $currentRole->update(['role_id' => $role->id]);
        } else {
            $currentRole = $this->modelResourceRoles()->create([
                'role_id' => $role->id,
                'resource_id' => $resource->id,
                'resource_type' => class_basename($resource)
            ]);
        }

        return $currentRole;
    }

    public function removeRole($resource)
    {
        return $this->role($resource)->delete();
    }

    public function hasRole($role, $resource): bool
    {
        $resource = $resource instanceof \Illuminate\Database\Eloquent\Collection 
            ? $resource->first()
            : $resource;

        if (is_string($role)) {
            $role = Role::where('name', $role)->where('resource_type', class_basename($resource))->first();
        }
        if (!$role) {
            return false;
        }
        if ($this->role($resource)->first()?->role?->id == $role->id) {
            return true;
        }
        return false;
    }
   
    public function hasAnyRole($roles, $resource): bool
    {
        $resource = $resource instanceof \Illuminate\Database\Eloquent\Collection 
            ? $resource->first()
            : $resource;

        $roleNames = is_string($roles) ? explode('|', $roles) : (array) $roles;
        
        foreach ($roleNames as $roleName) {
            if ($this->hasRole(trim($roleName), $resource)) {
                return true;
            }
        }
        
        return false;
    }

    public function getRoleName($resource)
    {
        return $this->role($resource)->first()?->role?->name ?? '';
    }
}
