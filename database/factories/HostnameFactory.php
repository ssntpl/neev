<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\Hostname;

class HostnameFactory extends Factory
{
    protected $model = Hostname::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'team',
            'owner_id' => TeamFactory::new(),
            'host' => fake()->unique()->domainName(),
            'status' => Hostname::STATUS_PENDING,
        ];
    }

    public function verified(): static
    {
        return $this->state(['status' => Hostname::STATUS_VERIFIED, 'verified_at' => now()]);
    }

    public function forOwner(Model $owner): static
    {
        return $this->state([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }
}
