<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property bool $joined
 * @property string $action
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 */
class Membership extends Pivot
{
    protected $table = 'team_user';

    public $incrementing = true;

    protected $casts = [
        'joined' => 'boolean',
    ];
}
