<?php

namespace Ssntpl\Neev\Rules;

use Auth;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\Email;
use Str;

class PasswordUserData implements ValidationRule 
{
    protected $columns;

    public function __construct($columns = [])
    {
        $this->columns = is_array($columns) ? $columns : [$columns];
    }

    public static function notContain($columns)
    {
        return new static($columns);
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

        foreach ($this->columns as $column) {
            if ($column === 'email') {
                $emailValue = $user->email?->email;
                if ($emailValue && (str_contains(Str::lower($value), Str::lower($emailValue)) || str_contains(Str::lower($emailValue), Str::lower($value)))) {
                    $fail("Password should not contain your email.");
                    return;
                }
            } else {
                $columnValue = $user->{$column} ?? null;
                if ($columnValue && (str_contains(Str::lower($value), Str::lower($columnValue)) || str_contains(Str::lower($columnValue), Str::lower($value)))) {
                    $fail("Password should not contain your {$column}.");
                    return;
                }
            }
        }
    }
}