<?php

namespace Ssntpl\Neev\Exceptions;

use Exception;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when an account has had too many wrong answers to a confirmation
 * in a short window — a password, or the emailed code an account without a
 * password uses instead.
 *
 * Keyed on the account, and counting wrong answers only: a confirmation is
 * asked of a session that already holds the account, so the limit is there to
 * stop a *stolen* session using the check as an oracle for the password it
 * lacks, not to meter the owner's legitimate actions. A right answer clears
 * the count. Rendered here so that every action that confirms answers the
 * same way without each controller catching it.
 */
class ConfirmationThrottledException extends Exception
{
    public function __construct(
        public readonly int $retryAfter,
        public readonly string $field,
    ) {
        parent::__construct(__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $retryAfter]));
    }

    /**
     * Not an error worth a log line: it is the limit doing its job.
     */
    public function report(): void
    {
    }

    public function render(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'retry_after' => $this->retryAfter,
            ], 429)->header('Retry-After', (string) $this->retryAfter);
        }

        // Back to the form, with the message under the field that was asked
        // for, so the Blade kit shows it where the user is looking rather
        // than a bare 429 page.
        return back()->withErrors([$this->field => $this->getMessage()]);
    }
}
