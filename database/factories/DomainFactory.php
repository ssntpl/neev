<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\Domain;

class DomainFactory extends Factory
{
    protected $model = Domain::class;

    public function definition(): array
    {
        return [
            'team_id' => TeamFactory::new(),
            'domain' => fake()->unique()->domainName(),
            'is_primary' => false,
            'enforce' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(['verified_at' => now()]);
    }

    public function primary(): static
    {
        return $this->state(['is_primary' => true]);
    }
}
