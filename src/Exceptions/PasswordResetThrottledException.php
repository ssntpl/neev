<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;

/**
 * Thrown when an account has asked for too many password resets, or had too
 * many wrong reset codes tried against it, in a short window.
 *
 * Both limits are keyed on the account, not the caller. Each reset email
 * issues a fresh code with a fresh allowance of guesses, so a limit per IP
 * alone let anyone spread across addresses keep asking and guessing until a
 * code fell.
 */
class PasswordResetThrottledException extends Exception
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }

    public static function sending(int $retryAfter): self
    {
        return new self('Too many password resets have been requested for this account. Please wait before requesting another.', $retryAfter);
    }

    public static function guessing(int $retryAfter): self
    {
        return new self('Too many incorrect codes. Use the link in the email, or try again later.', $retryAfter);
    }
}
