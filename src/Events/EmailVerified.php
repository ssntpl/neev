<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Neev's counterpart to Illuminate\Auth\Events\Verified.
 *
 * A dedicated event is used because Laravel's Verified requires the user
 * to implement MustVerifyEmail — and implementing that contract would make
 * the framework's auto-registered SendEmailVerificationNotification
 * listener react to Registered events, duplicating the verification mail
 * neev already sends itself.
 */
class EmailVerified
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public $user,
    ) {
    }
}
