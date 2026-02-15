<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\Team;

class TeamFactory extends Factory
{
    protected $model = Team::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
            'is_public' => true,
            'activated_at' => now(),
        ];
    }

    public function inactive(?string $reason = null): static
    {
        return $this->state([
            'activated_at' => null,
            'inactive_reason' => $reason,
        ]);
    }

    public function private(): static
    {
        return $this->state(['is_public' => false]);
    }
}
