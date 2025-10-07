<?php

namespace Ssntpl\Neev\Rules;

use Auth;
use Hash;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\Password;

class PasswordHistory implements ValidationRule 
{
    protected $count;

    public function __construct($count = 5)
    {
        $this->count = $count;
    }

    public static function notReused($count = 5)
    {
        return new static($count);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void 
    {
        $user = Auth::user();
        $email = request()->input('email');
        if ($email) {
            $email = Email::where('email', $email)->first();
            $user = $email?->user;
        }

        if (!$user) {
            return;
        }

        $oldPasswords = Password::where('user_id', $user->id)->orderByDesc('id')->limit($this->count)->get();
        foreach ($oldPasswords as $oldPassword) {
            if (Hash::check($value, $oldPassword->password)) {
                $fail("New password cannot be the same as your last {$this->count} passwords.");
                return;
            }
        }
    }
}