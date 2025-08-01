<?php

namespace Ssntpl\Neev\Models;

use Exception;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = [
        'name',
        'team_id'
    ];

    public function team()
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, Grant::class)
                ->using(Grant::class)
                ->as('grant');
    }

    public function syncPermissions(array $permissionIds)
    {
        return $this->permissions()->sync($permissionIds);
    }
}
