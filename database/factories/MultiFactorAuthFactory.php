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
            'preferred' => true,
        ];
    }

    public function email(): static
    {
        return $this->state(['method' => 'email']);
    }
}
