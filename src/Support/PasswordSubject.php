<?php

namespace Ssntpl\Neev\Support;

use Illuminate\Http\Request;
use Ssntpl\Neev\Models\User;

/**
 * The account a password is being chosen for.
 *
 * `PasswordHistory` and `PasswordUserData` compare a candidate password with
 * an account's current and past passwords, name and email, and fail with a
 * message that says so. That answer must only ever be about an account the
 * request has already proven it may act on — the signed-in user, or the one a
 * reset link or code has just vouched for — never about whichever address or
 * id the request body happens to name. Read from the body, the rules were a
 * password oracle: anyone could post a victim's email with a guess and learn
 * from the error whether it was that account's password.
 *
 * Controllers that have proven an account but are not signed in as it (the
 * password-reset paths) name it with `set()` before validating. Everything
 * else resolves to the authenticated user, and a request with neither — a
 * registration — has no history to compare against.
 */
final class PasswordSubject
{
    public const ATTRIBUTE = 'neev.password_subject';

    /**
     * Name the account the request has proven, for the rules to compare against.
     */
    public static function set(Request $request, User $user): void
    {
        $request->attributes->set(self::ATTRIBUTE, $user);
    }

    /**
     * The account the password rules compare against, or null when the
     * request has proven none.
     */
    public static function resolve(?Request $request = null): ?User
    {
        $request ??= request();

        $subject = $request->attributes->get(self::ATTRIBUTE);
        if ($subject instanceof User) {
            return $subject;
        }

        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : User::model()->find($id);
    }
}
