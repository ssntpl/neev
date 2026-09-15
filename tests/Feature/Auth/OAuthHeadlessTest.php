<?php

namespace Ssntpl\Neev\Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * The OAuth web routes are registered kit or not, so a headless install
 * reaches the MFA hand-off too — with no `otp.mfa.create` page to send the
 * browser to. EmailLinks decides where it lands instead.
 */
class OAuthHeadlessTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SocialiteServiceProvider::class,
        ]);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // The default install: no Blade starter kit, so none of the kit's
        // page routes — `otp.mfa.create` among them — exist.
        $app['config']->set('neev.ui', null);

        $app['config']->set('neev.oauth', ['google']);
        $app['config']->set('neev.home', '/dashboard');
        $app['config']->set('services.google', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/oauth/google/callback',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(
            dirname(__DIR__, 3) . '/vendor/ssntpl/laravel-acl/database/migrations'
        );
    }

    private function mockSocialiteUser(string $email): void
    {
        $socialiteUser = Mockery::mock(SocialiteUser::class);
        $socialiteUser->email = $email;
        $socialiteUser->name = 'Test User';
        $socialiteUser->shouldReceive('getId')->andReturn('oauth-123');
        $socialiteUser->shouldReceive('getNickname')->andReturn(null);
        $socialiteUser->shouldReceive('getAvatar')->andReturn(null);

        $provider = Mockery::mock(Provider::class);
        $provider->shouldReceive('user')->andReturn($socialiteUser);

        Socialite::shouldReceive('driver')
            ->with('google')
            ->andReturn($provider);
    }

    private function userWithTotp(): User
    {
        $this->enableMFA();

        $user = User::factory()->create();

        $user->multiFactorAuths()->create([
            'method' => 'authenticator',
            'preferred' => true,
            'secret' => Base32::encodeUpper(random_bytes(32)),
            'verified_at' => now(),
        ]);

        return $user;
    }

    /** The kit page does not exist here, so the app's own page is used instead. */
    public function test_callback_sends_an_enrolled_account_to_the_apps_own_challenge_page(): void
    {
        $user = $this->userWithTotp();

        $this->mockSocialiteUser($user->email);

        $this->get('/neev/oauth/google/callback?code=test-auth-code')
            ->assertRedirect('http://localhost/mfa-challenge/authenticator');

        // The gate still holds: the attempt is on record but unanswered.
        $this->assertNull($user->loginAttempts()->latest('id')->first()->multi_factor_method);
    }

    /** NeevMiddleware turns a parked session back to the same page. */
    public function test_middleware_sends_a_parked_session_to_the_apps_own_challenge_page(): void
    {
        $user = $this->userWithTotp();

        $this->mockSocialiteUser($user->email);
        $this->get('/neev/oauth/google/callback?code=test-auth-code');

        Route::middleware(['web', 'neev:web'])->get('/mfa-protected', fn () => response('PROTECTED'));

        $this->get('/mfa-protected')
            ->assertRedirect('http://localhost/mfa-challenge/authenticator');
    }

    /** An account with no second factor is unaffected by any of this. */
    public function test_callback_still_completes_for_an_account_without_mfa(): void
    {
        $user = User::factory()->create();

        $this->mockSocialiteUser($user->email);

        $this->get('/neev/oauth/google/callback?code=test-auth-code')
            ->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user->fresh());
    }
}
