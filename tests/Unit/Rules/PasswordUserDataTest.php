<?php

namespace Ssntpl\Neev\Tests\Unit\Rules;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\PasswordUserData;
use Ssntpl\Neev\Tests\TestCase;

class PasswordUserDataTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Helper to run the rule and return whether it failed
    // -----------------------------------------------------------------

    protected function runRule(PasswordUserData $rule, string $value): bool
    {
        $failed = false;
        $rule->validate('password', $value, function () use (&$failed) {
            $failed = true;
        });

        return $failed;
    }

    /**
     * Set the authenticated user on the request so request()->user() works.
     */
    protected function setRequestUser($user): void
    {
        $this->app['request']->setUserResolver(function () use ($user) {
            return $user;
        });
    }

    // -----------------------------------------------------------------
    // notContain() factory method
    // -----------------------------------------------------------------

    public function test_not_contain_creates_instance_with_string_column(): void
    {
        $rule = PasswordUserData::notContain('name');

        $this->assertInstanceOf(PasswordUserData::class, $rule);

        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('columns');
        $this->assertSame(['name'], $property->getValue($rule));
    }

    public function test_not_contain_creates_instance_with_array_columns(): void
    {
        $rule = PasswordUserData::notContain(['name', 'email']);

        $reflection = new \ReflectionClass($rule);
        $property = $reflection->getProperty('columns');
        $this->assertSame(['name', 'email'], $property->getValue($rule));
    }

    // -----------------------------------------------------------------
    // Passes when no user found (rule skips)
    // -----------------------------------------------------------------

    public function test_passes_when_no_user_found(): void
    {
        $rule = PasswordUserData::notContain(['name', 'email']);

        $failed = $this->runRule($rule, 'anything');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Fails when password contains user's name (case insensitive)
    // -----------------------------------------------------------------

    public function test_fails_when_password_contains_user_name(): void
    {
        $user = User::factory()->create(['name' => 'JohnDoe']);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['name']);

        // Password contains name (case insensitive)
        $failed = $this->runRule($rule, 'mypasswordjohndoe123');

        $this->assertTrue($failed);
    }

    public function test_fails_when_password_contains_user_name_case_insensitive(): void
    {
        $user = User::factory()->create(['name' => 'Alice']);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['name']);

        // 'ALICE' in the password should match 'Alice' in the name
        $failed = $this->runRule($rule, 'myALICEpassword');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Fails when password contains user's email
    // -----------------------------------------------------------------

    public function test_fails_when_password_contains_user_email(): void
    {
        $user = User::factory()->create();
        $this->setRequestUser($user);

        $emailValue = $user->email->email;

        $rule = PasswordUserData::notContain(['email']);

        $failed = $this->runRule($rule, 'prefix' . $emailValue . 'suffix');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Passes when password doesn't contain personal data
    // -----------------------------------------------------------------

    public function test_passes_when_password_does_not_contain_personal_data(): void
    {
        $user = User::factory()->create(['name' => 'JohnDoe']);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['name', 'email']);

        $failed = $this->runRule($rule, 'completely-unrelated-strong-pass!');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Skips columns with values shorter than 3 characters
    // -----------------------------------------------------------------

    public function test_skips_columns_with_values_shorter_than_three_characters(): void
    {
        $user = User::factory()->create(['name' => 'Al']);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['name']);

        // Even though 'Al' appears in the password, it's too short to check
        $failed = $this->runRule($rule, 'myAlpassword');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Skips null column values
    // -----------------------------------------------------------------

    public function test_skips_null_column_values(): void
    {
        $user = User::factory()->create(['username' => null]);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['username']);

        $failed = $this->runRule($rule, 'anypassword');

        $this->assertFalse($failed);
    }

    // -----------------------------------------------------------------
    // Works when user found via email input
    // -----------------------------------------------------------------

    public function test_works_when_user_found_via_email_input(): void
    {
        $user = User::factory()->create(['name' => 'SamuelJackson']);
        $emailRecord = $user->email;

        // Simulate request with email input (no authenticated user)
        $this->app['request']->merge(['email' => $emailRecord->email]);

        $rule = PasswordUserData::notContain(['name']);

        $failed = $this->runRule($rule, 'mySamuelJacksonPass');

        $this->assertTrue($failed);
    }

    // -----------------------------------------------------------------
    // Checks multiple columns and stops on first match
    // -----------------------------------------------------------------

    public function test_checks_multiple_columns(): void
    {
        $user = User::factory()->create(['name' => 'UniqueNameHere']);
        $this->setRequestUser($user);

        $rule = PasswordUserData::notContain(['name', 'email']);

        // Name matches
        $failed = $this->runRule($rule, 'UniqueNameHere!@#');

        $this->assertTrue($failed);
    }
}
