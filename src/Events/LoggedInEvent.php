<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoggedInEvent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public $user,
    ) {}
}
