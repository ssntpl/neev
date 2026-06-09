<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\MultiFactorAuth;

class MultiFactorAuthFactory extends Factory
{
    protected $model = MultiFactorAuth::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'method' => 'authenticator',
            'status' => MultiFactorAuth::STATUS_ACTIVE,
        ];
    }

    public function email(): static
    {
        return $this->state(['method' => 'email']);
    }

    public function pending(): static
    {
        return $this->state(['status' => MultiFactorAuth::STATUS_PENDING]);
    }
}
