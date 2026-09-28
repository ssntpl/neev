<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Support\PasswordSubject;
use Ssntpl\Neev\Support\ExportsState;

class PasswordHistory implements ValidationRule
{
    use ExportsState;

    public function __construct(
        protected int $count = 5,
    ) {
    }

    public static function notReused($count = 5)
    {
        return new static($count);
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Only an account the request has proven — the signed-in user, or the
        // one a reset has vouched for through PasswordSubject::set(). Never
        // the `email` or `id` in the body: read from there, this rule told
        // anyone naming an address whether a guess was its password.
        $user = PasswordSubject::resolve();

        if (!$user) {
            // No proven account means there is no history to reuse — a
            // first-time registration lands here. The check is vacuously
            // satisfied; failing would block every registration under the
            // default password rules.
            return;
        }

        // Check current password
        $currentHash = $user->getRawOriginal('password');
        if ($currentHash && Hash::check($value, $currentHash)) {
            $fail("New password cannot be the same as your last {$this->count} passwords.");
            return;
        }

        // Check password history
        $history = array_slice($user->password_history ?? [], 0, $this->count - 1);
        foreach ($history as $oldHash) {
            if (Hash::check($value, $oldHash)) {
                $fail("New password cannot be the same as your last {$this->count} passwords.");
                return;
            }
        }
    }
}
