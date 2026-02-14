<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class DomainRule extends Model
{   
    protected $fillable = [
        'domain_id',
        'name',
        'value',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
