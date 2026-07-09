<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Support\Facades\Hash;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\User;

class PasswordHistory implements ValidationRule
{
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
        $user = User::model()->find(request()->user()?->id);
        $email = request()->input('email');
        if ($email) {
            $user = User::findByEmail($email);
        }

        if (!$user) {
            $id = request()->input('id');
            if ($id) {
                $user = User::model()->find($id);
            }
        }

        if (!$user) {
            // No resolvable user means there is no history to reuse —
            // first-time registration lands here (no authenticated user,
            // and the submitted email belongs to nobody yet). The check
            // is vacuously satisfied; failing would block every
            // registration under the default password rules.
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
