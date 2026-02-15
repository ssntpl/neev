<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\Email;

class EmailFactory extends Factory
{
    protected $model = Email::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'email' => fake()->unique()->safeEmail(),
            'is_primary' => false,
            'verified_at' => now(),
        ];
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }

    public function unverified(): static
    {
        return $this->state(['verified_at' => null]);
    }
}
