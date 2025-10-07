<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class DomainRule extends Model
{   
    protected $fillable = [
        'team_id',
        'name',
        'value',
    ];

    public function team()
    {
        return $this->belongsTo(Team::getClass(), 'team_id');
    }
}
