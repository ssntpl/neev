<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\LoginAttempt;

class LoginAttemptFactory extends Factory
{
    protected $model = LoginAttempt::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'method' => LoginAttempt::Password,
            'platform' => 'Windows',
            'browser' => 'Chrome',
            'device' => 'Desktop',
            'ip_address' => fake()->ipv4(),
            'is_success' => true,
            'is_suspicious' => false,
        ];
    }

    public function failed(): static
    {
        return $this->state(['is_success' => false]);
    }

    public function suspicious(): static
    {
        return $this->state(['is_suspicious' => true]);
    }

    public function withMFA(string $method = 'authenticator'): static
    {
        return $this->state(['multi_factor_method' => $method]);
    }
}
