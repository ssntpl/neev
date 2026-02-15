<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    /**
     * The API routes under /neev (register, login, sendLoginLink, forgotPassword)
     * are wrapped with throttle:10,1 middleware, allowing 10 requests per minute.
     *
     * We hit the login endpoint 11 times to trigger the rate limiter.
     */
    public function test_returns_429_after_exceeding_rate_limit(): void
    {
        $payload = [
            'email' => 'test@example.com',
            'password' => 'password',
        ];

        // Make 10 allowed requests
        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/neev/login', $payload);
            $this->assertNotEquals(
                429,
                $response->getStatusCode(),
                "Request {$i} should not be rate limited."
            );
        }

        // The 11th request should be rate limited
        $response = $this->postJson('/neev/login', $payload);
        $response->assertStatus(429);
    }

    public function test_rate_limit_applies_to_register_endpoint(): void
    {
        config(['neev.password' => ['required', 'confirmed']]);

        $payload = [
            'name' => 'Test',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ];

        // Exhaust the rate limit
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/neev/register', [
                'name' => 'Test',
                'email' => "test{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);
        }

        // The 11th request should be rate limited
        $response = $this->postJson('/neev/register', $payload);
        $response->assertStatus(429);
    }

    public function test_rate_limit_applies_to_send_login_link_endpoint(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/neev/sendLoginLink', [
                'email' => 'test@example.com',
            ]);
        }

        $response = $this->postJson('/neev/sendLoginLink', [
            'email' => 'test@example.com',
        ]);
        $response->assertStatus(429);
    }
}
