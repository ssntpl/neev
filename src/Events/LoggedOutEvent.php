<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LoggedOutEvent
{
    use Dispatchable, SerializesModels;

    public $user;
    
    public function __construct($user)
    {
        $this->user = $user;
    }
}
