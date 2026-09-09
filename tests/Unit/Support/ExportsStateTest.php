<?php

namespace Ssntpl\Neev\Tests\Unit\Support;

use Illuminate\Validation\Rules\Password as BasePassword;
use Ssntpl\Neev\Rules\Password;
use Ssntpl\Neev\Rules\PasswordHistory;
use Ssntpl\Neev\Rules\PasswordUserData;
use Ssntpl\Neev\Tests\TestCase;

/**
 * `php artisan config:cache` writes config with var_export() and reads it back
 * with require, so every object left in the config array has to render as a
 * `::__set_state([...])` call the cache file can evaluate. The rules shipped in
 * config/neev.php supply that through the ExportsState trait.
 */
class ExportsStateTest extends TestCase
{
    /** Round-trip an object the way the config cache does. */
    protected function throughConfigCache(object $rule): object
    {
        return eval('return ' . var_export($rule, true) . ';');
    }

    /** Read a protected property off a rule instance. */
    protected function property(object $object, string $name): mixed
    {
        $class = new \ReflectionClass($object);

        while (! $class->hasProperty($name)) {
            $class = $class->getParentClass();
        }

        $property = $class->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($object);
    }

    // -----------------------------------------------------------------
    // Password
    // -----------------------------------------------------------------

    public function test_password_rule_survives_the_config_cache(): void
    {
        $rule = Password::min(10)->max(72)->letters()->mixedCase()->numbers()->symbols();

        $restored = $this->throughConfigCache($rule);

        $this->assertInstanceOf(Password::class, $restored);
        $this->assertSame(10, $this->property($restored, 'min'));
        $this->assertSame(72, $this->property($restored, 'max'));
        $this->assertTrue($this->property($restored, 'letters'));
        $this->assertTrue($this->property($restored, 'mixedCase'));
        $this->assertTrue($this->property($restored, 'numbers'));
        $this->assertTrue($this->property($restored, 'symbols'));
    }

    public function test_password_rule_still_validates_after_the_round_trip(): void
    {
        $rule = $this->throughConfigCache(Password::min(8)->letters()->numbers());

        $this->assertTrue(validator(['password' => 'abcd1234'], ['password' => [$rule]])->passes());
        $this->assertFalse(validator(['password' => 'abc'], ['password' => [$this->throughConfigCache(Password::min(8)->letters()->numbers())]])->passes());
    }

    public function test_fluent_builder_keeps_returning_the_neev_password_rule(): void
    {
        // The parent's factories use `new static`, so every builder entry
        // point yields the exportable subclass rather than Illuminate's.
        $this->assertInstanceOf(Password::class, Password::min(8));
        $this->assertInstanceOf(Password::class, Password::min(8)->max(20)->symbols());
    }

    // -----------------------------------------------------------------
    // PasswordHistory / PasswordUserData
    // -----------------------------------------------------------------

    public function test_password_history_rule_survives_the_config_cache(): void
    {
        $restored = $this->throughConfigCache(PasswordHistory::notReused(3));

        $this->assertInstanceOf(PasswordHistory::class, $restored);
        $this->assertSame(3, $this->property($restored, 'count'));
    }

    public function test_password_user_data_rule_survives_the_config_cache(): void
    {
        $restored = $this->throughConfigCache(PasswordUserData::notContain(['name', 'email']));

        $this->assertInstanceOf(PasswordUserData::class, $restored);
        $this->assertSame(['name', 'email'], $this->property($restored, 'columns'));
    }

    public function test_the_whole_shipped_password_rule_list_is_exportable(): void
    {
        $exported = var_export(config('neev.password'), true);

        $restored = eval('return ' . $exported . ';');

        $this->assertCount(count(config('neev.password')), $restored);
    }

    // -----------------------------------------------------------------
    // Password::upgrade()
    // -----------------------------------------------------------------

    public function test_upgrade_replaces_illuminate_password_rules_and_keeps_their_state(): void
    {
        $rules = ['required', BasePassword::min(12)->symbols()->uncompromised()];

        $upgraded = Password::upgrade($rules);

        $this->assertSame('required', $upgraded[0]);
        $this->assertInstanceOf(Password::class, $upgraded[1]);
        $this->assertSame(12, $this->property($upgraded[1], 'min'));
        $this->assertTrue($this->property($upgraded[1], 'symbols'));
        $this->assertTrue($this->property($upgraded[1], 'uncompromised'));
    }

    public function test_upgrade_leaves_rules_that_are_already_exportable_untouched(): void
    {
        $rule = Password::min(8);

        $upgraded = Password::upgrade([$rule]);

        $this->assertSame($rule, $upgraded[0]);
    }

    public function test_upgrade_passes_through_anything_that_is_not_a_rule_list(): void
    {
        $this->assertSame('required|min:8', Password::upgrade('required|min:8'));
        $this->assertNull(Password::upgrade(null));
    }
}
