<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

class InvalidInvitationException extends Exception
{
    protected $message = 'Invalid or expired invitation link.';
}
