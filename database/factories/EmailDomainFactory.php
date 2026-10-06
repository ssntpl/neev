<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Models\EmailDomain;

class EmailDomainFactory extends Factory
{
    protected $model = EmailDomain::class;

    public function definition(): array
    {
        return [
            'owner_type' => 'team',
            'owner_id' => TeamFactory::new(),
            'domain' => fake()->unique()->domainName(),
            'status' => EmailDomain::STATUS_PENDING,
            'enforce' => false,
        ];
    }

    public function verified(): static
    {
        return $this->state(['status' => EmailDomain::STATUS_VERIFIED, 'verified_at' => now()]);
    }

    public function enforced(): static
    {
        return $this->state(['enforce' => true]);
    }

    public function forOwner(Model $owner): static
    {
        return $this->state([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }
}
