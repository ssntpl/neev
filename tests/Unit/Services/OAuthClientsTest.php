<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use ReflectionProperty;
use Ssntpl\Neev\Services\OAuthClients;
use Ssntpl\Neev\Tests\TestCase;

class OAuthClientsTest extends TestCase
{
    protected OAuthClients $clients;

    /**
     * The package test app registers only Neev, and these tests exercise the
     * real Socialite manager rather than a mock of the facade.
     */
    protected function getPackageProviders($app): array
    {
        return array_merge([\Laravel\Socialite\SocialiteServiceProvider::class], parent::getPackageProviders($app));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clients = app(OAuthClients::class);

        config(['services.google' => [
            'client_id' => 'web-id',
            'client_secret' => 'web-secret',
            'redirect' => '/neev/oauth/google/callback',
            'clients' => [
                'android' => [
                    'client_id' => 'android-id',
                    'client_secret' => 'android-secret',
                    'redirect' => 'com.acme.app:/oauth',
                ],
            ],
        ]]);
    }

    protected function clientIdOf(object $driver): string
    {
        $property = new ReflectionProperty($driver, 'clientId');
        $property->setAccessible(true);

        return (string) $property->getValue($driver);
    }

    // -----------------------------------------------------------------
    // has() / platforms()
    // -----------------------------------------------------------------

    public function test_has_reports_configured_platforms(): void
    {
        $this->assertTrue($this->clients->has('google', 'android'));
        $this->assertFalse($this->clients->has('google', 'ios'));
        $this->assertSame(['android'], $this->clients->platforms('google'));
    }

    // -----------------------------------------------------------------
    // driver()
    // -----------------------------------------------------------------

    public function test_no_platform_uses_the_default_client(): void
    {
        $this->assertSame('web-id', $this->clientIdOf($this->clients->driver('google', null)));
    }

    public function test_a_platform_uses_its_own_client(): void
    {
        $this->assertSame('android-id', $this->clientIdOf($this->clients->driver('google', 'android')));
    }

    public function test_an_unknown_platform_falls_back_to_the_default_client(): void
    {
        $this->assertSame('web-id', $this->clientIdOf($this->clients->driver('google', 'ios')));
    }

    public function test_platform_config_does_not_leak_into_later_resolutions(): void
    {
        $this->clients->driver('google', 'android');

        $this->assertSame('web-id', config('services.google.client_id'));
        $this->assertSame('web-id', $this->clientIdOf($this->clients->driver('google', null)));
    }

    public function test_a_platform_inherits_keys_it_does_not_override(): void
    {
        config(['services.google.scopes' => ['openid', 'email']]);
        config(['services.google.clients.ios' => ['client_id' => 'ios-id']]);

        $driver = $this->clients->driver('google', 'ios');

        $this->assertSame('ios-id', $this->clientIdOf($driver));
        $this->assertContains('email', $driver->getScopes());
    }

    public function test_platform_clients_work_without_a_default_client(): void
    {
        // A mobile-only install: no top-level credentials for Socialite to read.
        config(['services.google' => [
            'clients' => [
                'android' => [
                    'client_id' => 'only-android',
                    'client_secret' => 'only-secret',
                    'redirect' => 'com.acme.app:/oauth',
                ],
            ],
        ]]);

        $this->assertSame('only-android', $this->clientIdOf($this->clients->driver('google', 'android')));
    }

    // -----------------------------------------------------------------
    // redirectUrl()
    // -----------------------------------------------------------------

    public function test_a_native_client_keeps_its_own_redirect(): void
    {
        $this->assertSame('com.acme.app:/oauth', $this->clients->redirectUrl('google', 'android'));
    }

    public function test_without_a_platform_the_callback_route_is_used(): void
    {
        $this->assertStringContainsString(
            '/oauth/google/callback',
            $this->clients->redirectUrl('google', null)
        );
    }

    public function test_a_platform_without_its_own_redirect_uses_the_callback_route(): void
    {
        config(['services.google.clients.ios' => ['client_id' => 'ios-id']]);

        $this->assertStringContainsString(
            '/oauth/google/callback',
            $this->clients->redirectUrl('google', 'ios')
        );
    }
}
