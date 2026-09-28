<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * A signed link proves the parameters in its query — and only those.
 *
 * `$request->id` reads `input()`, where a request body overrides the query
 * string, while `hasValidSignature()` covers the query alone. So a body `id`
 * (or `hash`, or `email`) beside a perfectly valid signature named any account
 * at all: anyone with a reset link for their own account could reset any other
 * account's password, and anyone with an email-change link could move any
 * account to an address they read. Every consumer reads the signed query now.
 */
class SignedLinkParametersTest extends TestCase
{
    use RefreshDatabase;

    private User $attacker;

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['neev.password' => ['required', 'confirmed']]);

        $this->attacker = User::factory()->create(['email' => 'attacker@example.com', 'password' => bcrypt('Attacker-Pass-1!'), 'email_verified_at' => null]);
        $this->victim = User::factory()->create(['email' => 'victim@example.com', 'password' => bcrypt('Victim-Secret-9!'), 'email_verified_at' => null]);
        $this->travel(1)->seconds();
    }

    private function signedQuery(string $route, array $params): string
    {
        return (string) parse_url(URL::temporarySignedRoute($route, now()->addMinutes(60), $params), PHP_URL_QUERY);
    }

    public function test_a_body_id_cannot_point_a_reset_link_at_another_account(): void
    {
        config(['neev.ui' => null]);
        $query = $this->signedQuery('neev.resetPassword', ['id' => $this->attacker->id, 'hash' => hash('sha256', $this->attacker->email)]);

        $this->postJson('/neev/resetPassword?' . $query, [
            'id' => $this->victim->id,
            'hash' => hash('sha256', $this->victim->email),
            'password' => 'Pwned-Pass-123!',
            'password_confirmation' => 'Pwned-Pass-123!',
        ])->assertOk();

        // The link's own account was reset; the one the body named was not.
        $this->assertTrue(Hash::check('Victim-Secret-9!', $this->victim->fresh()->password));
        $this->assertTrue(Hash::check('Pwned-Pass-123!', $this->attacker->fresh()->password));
    }

    public function test_a_body_id_cannot_point_an_email_change_link_at_another_account(): void
    {
        config(['neev.ui' => null]);
        $query = $this->signedQuery('neev.email.change.verify', ['id' => $this->attacker->id, 'email' => 'attacker-new@example.com']);

        $this->postJson('/neev/email/change/verify?' . $query, [
            'id' => $this->victim->id,
            'email' => 'stolen@example.com',
        ])->assertOk();

        $this->assertSame('victim@example.com', $this->victim->fresh()->email);
        $this->assertSame('attacker-new@example.com', $this->attacker->fresh()->email);
    }

    public function test_a_body_id_cannot_verify_another_accounts_email(): void
    {
        config(['neev.ui' => null]);
        $query = $this->signedQuery('mail.verify', ['id' => $this->attacker->id, 'hash' => hash('sha256', $this->attacker->email)]);

        $this->json('GET', '/neev/email/verify?' . $query, [
            'id' => $this->victim->id,
            'hash' => hash('sha256', $this->victim->email),
        ]);

        $this->assertNull($this->victim->fresh()->email_verified_at, 'the account the body named stays unverified');
        $this->assertNotNull($this->attacker->fresh()->email_verified_at, "the link's own account is verified");
    }

    public function test_the_blade_email_change_link_takes_the_address_from_the_signed_query(): void
    {
        $url = URL::temporarySignedRoute('email.change.verify', now()->addMinutes(60), ['id' => $this->attacker->id, 'email' => 'attacker-new@example.com']);

        $this->json('GET', $url, ['email' => 'stolen@example.com']);

        $this->assertSame('attacker-new@example.com', $this->attacker->fresh()->email);
    }
}
