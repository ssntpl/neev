<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoggedOutEvent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public $user,
    ) {
    }
}
