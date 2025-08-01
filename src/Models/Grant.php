<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class Grant extends Pivot
{
    /**
     * The table associated with the pivot model.
     *
     * @var string
     */
    protected $table = 'role_has_permissions';
}
