<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\EmailLinks;
use Ssntpl\Neev\Tests\TestCase;

/**
 * EmailLinks is the single seam an application overrides to decide where the
 * package's emailed links land and what a followed link responds with. These
 * tests pin both the Blade-kit and headless defaults, and the override itself.
 */
class EmailLinksTest extends TestCase
{
    use RefreshDatabase;

    protected EmailLinks $links;

    protected function setUp(): void
    {
        parent::setUp();

        $this->links = new EmailLinks();
    }

    protected function expiry(): DateTimeInterface
    {
        return now()->addMinutes(60);
    }

    protected function headless(): void
    {
        config(['neev.ui' => null]);
    }

    /** The query of a signed URL, as an array. */
    protected function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    // =================================================================
    // base()
    // =================================================================

    public function test_base_defaults_to_the_app_url_without_a_trailing_slash(): void
    {
        config(['app.url' => 'https://example.test/']);

        $this->assertSame('https://example.test', $this->links->base());
    }

    // =================================================================
    // verificationUrl()
    // =================================================================

    public function test_verification_url_points_at_the_blade_page_route_under_the_blade_kit(): void
    {
        $user = User::factory()->create();

        $url = $this->links->verificationUrl($user, $this->expiry());

        $this->assertStringStartsWith(url('/email/verify/' . $user->id . '/' . hash('sha256', $user->email)), $url);
        $this->assertArrayHasKey('signature', $this->queryOf($url));
    }

    public function test_verification_url_points_at_the_api_route_when_headless(): void
    {
        $this->headless();
        $user = User::factory()->create();

        $url = $this->links->verificationUrl($user, $this->expiry());

        $this->assertStringStartsWith(route('mail.verify') . '?', $url);

        $query = $this->queryOf($url);
        $this->assertSame((string) $user->id, $query['id']);
        $this->assertSame(hash('sha256', $user->email), $query['hash']);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_verification_url_signature_is_valid_and_expires(): void
    {
        $this->headless();
        $user = User::factory()->create();

        $url = $this->links->verificationUrl($user, now()->addMinutes(60));

        $this->assertTrue(URL::hasValidSignature(Request::create($url)));

        $this->travel(61)->minutes();
        $this->assertFalse(URL::hasValidSignature(Request::create($url)));
    }

    // =================================================================
    // emailChangeUrl()
    // =================================================================

    public function test_email_change_url_points_at_the_blade_route_under_the_blade_kit(): void
    {
        $user = User::factory()->create();

        $url = $this->links->emailChangeUrl($user, 'new@example.com', $this->expiry());

        $this->assertStringStartsWith(url('/email/change/verify/' . $user->id), $url);
        $this->assertSame('new@example.com', $this->queryOf($url)['email']);
    }

    public function test_email_change_url_points_at_the_api_route_when_headless(): void
    {
        $this->headless();
        $user = User::factory()->create();

        $url = $this->links->emailChangeUrl($user, 'new@example.com', $this->expiry());

        $this->assertStringStartsWith(route('neev.email.change.verify') . '?', $url);

        $query = $this->queryOf($url);
        $this->assertSame((string) $user->id, $query['id']);
        $this->assertSame('new@example.com', $query['email']);
        $this->assertArrayHasKey('signature', $query);
    }

    // =================================================================
    // passwordResetUrl()
    // =================================================================

    public function test_password_reset_url_uses_the_blade_form_route_under_the_blade_kit(): void
    {
        $user = User::factory()->create();

        $url = $this->links->passwordResetUrl($user, $this->expiry());

        $this->assertStringStartsWith(
            url('/update-password/' . $user->id . '/' . hash('sha256', $user->email)),
            $url
        );
    }

    /**
     * A reset needs a form to type the new password into, and headless installs
     * ship their own, so the link lands on the app rather than on the API route.
     */
    public function test_password_reset_url_lands_on_the_app_page_when_headless(): void
    {
        $this->headless();
        config(['app.url' => 'https://app.test']);
        $user = User::factory()->create();

        $url = $this->links->passwordResetUrl($user, $this->expiry());

        $this->assertStringStartsWith('https://app.test/reset-password?', $url);

        $query = $this->queryOf($url);
        $this->assertSame((string) $user->id, $query['id']);
        $this->assertSame(hash('sha256', $user->email), $query['hash']);
        $this->assertArrayHasKey('signature', $query);
    }

    // =================================================================
    // magicLinkUrl()
    // =================================================================

    public function test_magic_link_url_uses_the_blade_login_route_under_the_blade_kit(): void
    {
        $user = User::factory()->create();

        $url = $this->links->magicLinkUrl($user, $this->expiry());

        $this->assertStringStartsWith(url('/login/' . $user->id), $url);
    }

    /**
     * Following the link mints a token, and a token must not travel in a URL,
     * so a headless link lands on the app's page which exchanges the query.
     */
    public function test_magic_link_url_lands_on_the_app_page_when_headless(): void
    {
        $this->headless();
        config(['app.url' => 'https://app.test']);
        $user = User::factory()->create();

        $url = $this->links->magicLinkUrl($user, $this->expiry());

        $this->assertStringStartsWith('https://app.test/login-link?', $url);
        $this->assertSame((string) $user->id, $this->queryOf($url)['id']);
    }

    // =================================================================
    // invitationUrl()
    // =================================================================

    public function test_invitation_url_is_a_signed_register_link_under_the_blade_kit(): void
    {
        $url = $this->links->invitationUrl(7, 'invitee@example.com', $this->expiry());

        $this->assertStringStartsWith(url('/register') . '?', $url);

        $query = $this->queryOf($url);
        $this->assertSame('7', $query['id']);
        $this->assertSame(sha1('invitee@example.com'), $query['hash']);
        $this->assertArrayHasKey('signature', $query);
    }

    public function test_invitation_url_is_an_unsigned_app_register_link_when_headless(): void
    {
        $this->headless();
        config(['app.url' => 'https://app.test']);

        $url = $this->links->invitationUrl(7, 'invitee@example.com', $this->expiry());

        $this->assertStringStartsWith('https://app.test/register?', $url);

        $query = $this->queryOf($url);
        $this->assertSame('7', $query['invitation_id']);
        $this->assertSame(sha1('invitee@example.com'), $query['hash']);
        $this->assertArrayNotHasKey('signature', $query);
    }

    // =================================================================
    // oauthCallbackUrl()
    // =================================================================

    public function test_oauth_callback_url_is_built_from_the_route_prefix(): void
    {
        config(['app.url' => 'https://app.test', 'neev.route_prefix' => 'neev']);

        $this->assertSame(
            'https://app.test/neev/oauth/google/callback',
            $this->links->oauthCallbackUrl('google')
        );
    }

    public function test_oauth_callback_url_honours_a_custom_route_prefix(): void
    {
        config(['app.url' => 'https://app.test', 'neev.route_prefix' => '/auth/']);

        $this->assertSame(
            'https://app.test/auth/oauth/github/callback',
            $this->links->oauthCallbackUrl('github')
        );
    }

    // =================================================================
    // Responses
    // =================================================================

    public function test_verified_returns_json_when_the_caller_asked_for_json(): void
    {
        $user = User::factory()->create();

        $response = $this->links->verified($this->jsonRequest(), $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Email verification done.', json_decode($response->getContent(), true)['message']);
    }

    public function test_verified_redirects_home_for_a_browser(): void
    {
        $user = User::factory()->create();

        $response = $this->links->verified($this->browserRequest(), $user);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url(config('neev.home')), $response->headers->get('Location'));
    }

    public function test_already_verified_is_not_treated_as_an_error(): void
    {
        $user = User::factory()->create();

        $response = $this->links->alreadyVerified($this->jsonRequest(), $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Email verification already done.', json_decode($response->getContent(), true)['message']);
    }

    public function test_verification_failed_is_a_403(): void
    {
        $response = $this->links->verificationFailed($this->jsonRequest());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('Invalid or expired verification link.', json_decode($response->getContent(), true)['message']);
    }

    public function test_email_in_use_is_a_409(): void
    {
        $user = User::factory()->create();

        $response = $this->links->emailInUse($this->jsonRequest(), $user);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('This email address is already in use.', json_decode($response->getContent(), true)['message']);
    }

    public function test_email_change_failed_sends_a_browser_back_to_login(): void
    {
        $response = $this->links->emailChangeFailed($this->browserRequest());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));
    }

    public function test_email_changed_confirms_the_new_address(): void
    {
        $user = User::factory()->create();

        $response = $this->links->emailChanged($this->jsonRequest(), $user);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'Email address has been updated and verified.',
            json_decode($response->getContent(), true)['message']
        );
    }

    // =================================================================
    // Overriding
    // =================================================================

    public function test_an_app_subclass_can_redirect_every_link_to_its_own_host(): void
    {
        $this->headless();
        $this->app->bind(EmailLinks::class, AppEmailLinks::class);

        $links = app(EmailLinks::class);
        $user = User::factory()->create();

        $this->assertSame('https://app.example.com', $links->base());
        $this->assertStringStartsWith('https://app.example.com/reset-password?', $links->passwordResetUrl($user, $this->expiry()));
        $this->assertStringStartsWith('https://app.example.com/login-link?', $links->magicLinkUrl($user, $this->expiry()));
        $this->assertStringStartsWith('https://app.example.com/register?', $links->invitationUrl(1, 'a@b.test', $this->expiry()));
    }

    public function test_the_container_resolves_a_single_shared_instance_by_default(): void
    {
        $this->assertInstanceOf(EmailLinks::class, app(EmailLinks::class));
        $this->assertSame(app(EmailLinks::class), app(EmailLinks::class));
    }

    protected function jsonRequest(): Request
    {
        $request = Request::create('/whatever');
        $request->headers->set('Accept', 'application/json');

        return $request;
    }

    protected function browserRequest(): Request
    {
        return Request::create('/whatever');
    }
}

/** The override an application writes to move every link onto its own host. */
class AppEmailLinks extends EmailLinks
{
    public function base(): string
    {
        return 'https://app.example.com';
    }
}
