<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $team_id
 * @property int $user_id
 * @property bool $joined
 * @property string $action
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Membership extends Pivot
{
    /** The team invited the user, who has yet to accept. */
    public const REQUEST_TO_USER = 'request_to_user';

    /** The user asked to join, and the owner has yet to approve. */
    public const REQUEST_FROM_USER = 'request_from_user';

    protected $table = 'team_user';

    public $incrementing = true;

    protected $casts = [
        'joined' => 'boolean',
    ];
}
