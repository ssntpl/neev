<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Membership extends Pivot
{
    protected $table = 'team_user';

    public $incrementing = true;

    protected $casts = [
        'joined' => 'boolean',
    ];
}
