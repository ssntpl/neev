<?php

namespace Ssntpl\Neev\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Ssntpl\Neev\Models\AccessToken;

class AccessTokenFactory extends Factory
{
    protected $model = AccessToken::class;

    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'name' => 'api token',
            'token' => fake()->sha256(),
            'token_type' => AccessToken::api_token,
            'permissions' => ['*'],
        ];
    }

    public function login(): static
    {
        return $this->state([
            'name' => AccessToken::login,
            'token_type' => AccessToken::login,
        ]);
    }

    public function mfa(): static
    {
        return $this->state([
            'name' => 'mfa_token',
            'token_type' => AccessToken::mfa_token,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'expires_at' => now()->subHour(),
        ]);
    }
}
