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
        self::write($request, $user);
    }

    /**
     * Declare that this request chooses a password for nobody it already
     * holds — a registration. Without this, a registration posted from a
     * signed-in session would be graded against that session's account.
     */
    public static function none(Request $request): void
    {
        self::write($request, false);
    }

    /**
     * A FormRequest is its own instance, built from the request the container
     * holds, and the rules read the container's through `request()`. Write to
     * both so a controller may pass whichever it was given.
     */
    private static function write(Request $request, User|false $value): void
    {
        $request->attributes->set(self::ATTRIBUTE, $value);

        $current = app()->bound('request') ? app('request') : null;
        if ($current instanceof Request && $current !== $request) {
            $current->attributes->set(self::ATTRIBUTE, $value);
        }
    }

    /**
     * The account the password rules compare against, or null when the
     * request has proven none.
     */
    public static function resolve(?Request $request = null): ?User
    {
        $request ??= request();

        $subject = $request->attributes->get(self::ATTRIBUTE);
        if ($subject === false) {
            return null;
        }
        if ($subject instanceof User) {
            return $subject;
        }

        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : User::model()->find($id);
    }
}
