<?php

namespace Ssntpl\Neev\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\NewAccessToken;
use Ssntpl\Neev\Tests\TestCase;

class NewAccessTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_array_returns_correct_structure(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        $this->assertInstanceOf(NewAccessToken::class, $token);

        $array = $token->toArray();
        $this->assertArrayHasKey('accessToken', $array);
        $this->assertArrayHasKey('plainTextToken', $array);
        $this->assertNotEmpty($array['plainTextToken']);
    }

    public function test_to_json_returns_valid_json(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        $json = $token->toJson();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertArrayHasKey('accessToken', $decoded);
        $this->assertArrayHasKey('plainTextToken', $decoded);
    }

    public function test_plain_text_token_is_accessible(): void
    {
        $user = User::factory()->create();
        $token = $user->createLoginToken(60);

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertStringContainsString('|', $token->plainTextToken);
    }
}
