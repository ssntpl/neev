<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Mail\VerifyUserEmail;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * Changing the address on an account: requesting the change, and spending the
 * link that confirms it. The confirmation route answers on GET so a clicked
 * link reaches it, and on POST so an SPA can forward the signed query instead.
 */
class EmailChangeTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function authenticatedUser(array $attributes = []): array
    {
        config(['neev.password' => ['required']]);

        $user = User::factory()->create($attributes + ['password' => 'password123']);
        $token = $user->createLoginToken(60);

        return [$user, $token->plainTextToken];
    }

    protected function changeQuery(User $user, string $newEmail, int $minutes = 60): string
    {
        return (string) parse_url(URL::temporarySignedRoute(
            'neev.email.change.verify',
            now()->addMinutes($minutes),
            ['id' => $user->id, 'email' => $newEmail]
        ), PHP_URL_QUERY);
    }

    // -----------------------------------------------------------------
    // POST /neev/email/change — request the change
    // -----------------------------------------------------------------

    public function test_request_mails_a_confirmation_link_to_the_new_address(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/change', [
                'email' => 'new@example.com',
                'password' => 'password123',
            ])
            ->assertOk();

        Mail::assertSent(VerifyUserEmail::class, function (VerifyUserEmail $mail) {
            return $mail->hasTo('new@example.com')
                && $mail->purpose === 'Verify Email Change'
                && str_contains($mail->url, 'signature=')
                && str_contains($mail->url, 'email=');
        });

        // Nothing changes until the link is followed.
        $this->assertNotSame('new@example.com', $user->refresh()->email);
    }

    public function test_request_is_refused_without_the_current_password(): void
    {
        Mail::fake();

        [$user, $token] = $this->authenticatedUser();

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/neev/email/change', [
                'email' => 'new@example.com',
                'password' => 'wrong-password',
            ])
            ->assertStatus(403);

        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // GET|POST /neev/email/change/verify — spend the link
    // -----------------------------------------------------------------

    /**
     * A mail client hands the link to a browser, which issues a GET, so the
     * route has to answer one.
     */
    public function test_a_clicked_link_confirms_the_new_address(): void
    {
        [$user] = $this->authenticatedUser();

        $this->get('/neev/email/change/verify?' . $this->changeQuery($user, 'new@example.com'))
            ->assertRedirect(config('neev.home'));

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_a_json_caller_gets_a_json_confirmation(): void
    {
        [$user] = $this->authenticatedUser();

        $this->getJson('/neev/email/change/verify?' . $this->changeQuery($user, 'new@example.com'))
            ->assertOk()
            ->assertJsonPath('message', 'Email address has been updated and verified.');

        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    /** POST is retained for SPAs that forward the signed query themselves. */
    public function test_the_signed_query_can_also_be_forwarded_by_post(): void
    {
        [$user] = $this->authenticatedUser();

        $this->postJson('/neev/email/change/verify?' . $this->changeQuery($user, 'new@example.com'))
            ->assertOk()
            ->assertJsonPath('message', 'Email address has been updated and verified.');

        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    /**
     * The link is the credential, so it works in whichever browser the mail
     * client opened — not only the one holding the session.
     */
    public function test_the_link_works_without_a_session(): void
    {
        [$user] = $this->authenticatedUser();

        $this->getJson('/neev/email/change/verify?' . $this->changeQuery($user, 'new@example.com'))
            ->assertOk();

        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        [$user] = $this->authenticatedUser();
        $original = $user->email;

        $this->getJson('/neev/email/change/verify?id=' . $user->id . '&email=new@example.com&signature=nope')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Invalid or expired verification link.');

        $this->assertSame($original, $user->refresh()->email);
    }

    public function test_an_expired_link_is_refused(): void
    {
        [$user] = $this->authenticatedUser();
        $original = $user->email;

        $query = $this->changeQuery($user, 'new@example.com', 60);
        $this->travel(61)->minutes();

        $this->getJson('/neev/email/change/verify?' . $query)->assertStatus(403);

        $this->assertSame($original, $user->refresh()->email);
    }

    public function test_a_browser_with_a_bad_link_is_sent_to_login(): void
    {
        [$user] = $this->authenticatedUser();

        $this->get('/neev/email/change/verify?id=' . $user->id . '&email=new@example.com&signature=nope')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('message');
    }

    public function test_an_unknown_user_is_refused(): void
    {
        $this->getJson('/neev/email/change/verify?' . $this->changeQuery(
            new User(['id' => 999999]),
            'new@example.com'
        ))->assertStatus(403);
    }

    /**
     * Someone else may claim the address between the request and the click,
     * which is a conflict rather than a bad link.
     */
    public function test_an_address_claimed_in_the_meantime_returns_a_conflict(): void
    {
        [$user] = $this->authenticatedUser();
        $original = $user->email;

        $query = $this->changeQuery($user, 'taken@example.com');

        User::factory()->create(['email' => 'taken@example.com']);

        $this->getJson('/neev/email/change/verify?' . $query)
            ->assertStatus(409)
            ->assertJsonPath('message', 'This email address is already in use.');

        $this->assertSame($original, $user->refresh()->email);
    }

    // -----------------------------------------------------------------
    // GET /email/change/verify/{id} — the Blade kit page route
    // -----------------------------------------------------------------

    public function test_the_blade_link_confirms_the_new_address(): void
    {
        [$user] = $this->authenticatedUser();

        $url = URL::temporarySignedRoute(
            'email.change.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'email' => 'new@example.com']
        );

        $this->get($url)
            ->assertRedirect(config('neev.home'))
            ->assertSessionHasNoErrors();

        $this->assertSame('new@example.com', $user->refresh()->email);
    }

    public function test_the_blade_link_sends_a_bad_signature_back_to_login(): void
    {
        [$user] = $this->authenticatedUser();

        $this->get('/email/change/verify/' . $user->id . '?email=new@example.com&signature=nope')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('message');
    }
}
