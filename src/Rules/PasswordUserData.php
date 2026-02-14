<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;
use Illuminate\Support\Str;

class PasswordUserData implements ValidationRule 
{
    public function __construct(
        protected string|array $columns = [],
    ) {
        $this->columns = (array) $columns;
    }

    public static function notContain($columns)
    {
        return new static($columns);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void 
    {
        $user = User::model()->find(request()->user()?->id);
        $email = request()->input('email');
        if ($email) {
            $email = Email::where('email', $email)->first();
            $user = $email?->user;
        }

        if (!$user) {
            return;
        }

        foreach ($this->columns as $column) {
            if ($column === 'email') {
                $emailValue = $user->email?->email;
                if ($emailValue && strlen($emailValue) >= 3 && str_contains(Str::lower($value), Str::lower($emailValue))) {
                    $fail("Password should not contain your email.");
                    return;
                }
            } else {
                $columnValue = $user->{$column} ?? null;
                if ($columnValue && strlen($columnValue) >= 3 && str_contains(Str::lower($value), Str::lower($columnValue))) {
                    $fail("Password should not contain your {$column}.");
                    return;
                }
            }
        }
    }
}