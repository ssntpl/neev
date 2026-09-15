<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\MagicLinkToken;

class MagicLinkTokenFactory extends Factory
{
    protected $model = MagicLinkToken::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'token' => hash('sha256', fake()->unique()->sha256()),
            'channel' => 'web',
            'expires_at' => now()->addMinutes(10),
        ];
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function mobile(): static
    {
        return $this->state(['channel' => 'mobile']);
    }

    public function boundTo(string $fingerprint): static
    {
        return $this->state(['meta_data' => ['fingerprint' => hash('sha256', $fingerprint)]]);
    }
}
