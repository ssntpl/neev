<?php

namespace Ssntpl\Neev\Rules;

use Illuminate\Validation\Rules\Password as BasePassword;
use ReflectionClass;
use Ssntpl\Neev\Support\ExportsState;

/**
 * Laravel's password rule, made storable in a cached config file.
 *
 * Identical to Illuminate\Validation\Rules\Password in every way — the fluent
 * builder returns instances of this class because the parent's factories use
 * `new static` — it only adds the __set_state() the config cache needs.
 */
class Password extends BasePassword
{
    use ExportsState;

    /**
     * Replace plain Illuminate password rules in a rule list with this class.
     *
     * Applications that published config/neev.php before this class existed
     * still import Illuminate's rule; upgrading the instance on the way in
     * keeps `php artisan config:cache` working for them without an edit.
     */
    public static function upgrade(mixed $rules): mixed
    {
        if (! is_array($rules)) {
            return $rules;
        }

        foreach ($rules as $key => $rule) {
            if ($rule instanceof BasePassword && ! $rule instanceof static) {
                $rules[$key] = static::fromBase($rule);
            }
        }

        return $rules;
    }

    /**
     * Build an instance carrying the state of an Illuminate password rule.
     */
    protected static function fromBase(BasePassword $rule): static
    {
        $instance = (new ReflectionClass(static::class))->newInstanceWithoutConstructor();

        static::copyState(static::stateOf($rule), $instance);

        return $instance;
    }
}
