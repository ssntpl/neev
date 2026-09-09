<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\User;
use Illuminate\Support\Str;
use Ssntpl\Neev\Support\ExportsState;

class PasswordUserData implements ValidationRule
{
    use ExportsState;

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
            $user = User::findByEmail($email);
        }

        if (!$user) {
            return;
        }

        foreach ($this->columns as $column) {
            $columnValue = $user->{$column} ?? null;
            if ($columnValue && strlen($columnValue) >= 3 && str_contains(Str::lower($value), Str::lower($columnValue))) {
                $fail("Password should not contain your {$column}.");
                return;
            }
        }
    }
}
