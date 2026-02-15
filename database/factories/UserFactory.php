<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\User;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            $user->emails()->create([
                'email' => fake()->unique()->safeEmail(),
                'is_primary' => true,
                'verified_at' => now(),
            ]);

            $user->passwords()->create([
                'password' => 'password',
            ]);
        });
    }
}
