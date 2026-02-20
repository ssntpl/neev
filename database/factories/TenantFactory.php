<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\Tenant;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => fake()->unique()->slug(2),
        ];
    }
}
