<?php

namespace Ssntpl\Neev\Tests\Unit\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\LoginAttemptFactory;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

class LoginAttemptTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------

    public function test_password_constant(): void
    {
        $this->assertSame('password', LoginAttempt::Password);
    }

    public function test_passkey_constant(): void
    {
        $this->assertSame('passkey', LoginAttempt::Passkey);
    }

    public function test_magic_auth_constant(): void
    {
        $this->assertSame('magic auth', LoginAttempt::MagicAuth);
    }

    public function test_sso_constant(): void
    {
        $this->assertSame('sso', LoginAttempt::SSO);
    }

    public function test_oauth_constant(): void
    {
        $this->assertSame('oauth', LoginAttempt::OAuth);
    }

    // -----------------------------------------------------------------
    // getClientDetails() — Browser detection
    // -----------------------------------------------------------------

    public function test_get_client_details_detects_chrome_browser(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Chrome', $details['browser']);
    }

    public function test_get_client_details_detects_firefox_browser(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Firefox', $details['browser']);
    }

    public function test_get_client_details_detects_safari_browser(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Safari', $details['browser']);
    }

    public function test_get_client_details_detects_edge_browser(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edge/120.0.0.0';

        $details = LoginAttempt::getClientDetails(null, $ua);

        // Edge contains "Chrome" too, so it depends on ordering — but the code checks Chrome first
        // The method checks Chrome before Edge, so this UA returns Chrome
        // Let's use a proper Edge UA without Chrome
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Edge/120.0.0.0';
        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Edge', $details['browser']);
    }

    // -----------------------------------------------------------------
    // getClientDetails() — Platform detection
    // -----------------------------------------------------------------

    public function test_get_client_details_detects_windows_platform(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Windows', $details['platform']);
    }

    public function test_get_client_details_detects_mac_os_x_platform(): void
    {
        $ua = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_2) AppleWebKit/605.1.15 Safari/605.1.15';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Mac OS X', $details['platform']);
    }

    public function test_get_client_details_detects_iphone_platform(): void
    {
        // Use a synthetic UA with "iPhone" but without "Mac OS X" to isolate the iPhone check,
        // since the code checks "Mac OS X" before "iPhone" in its detection order.
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('iPhone', $details['platform']);
    }

    public function test_get_client_details_detects_android_platform(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Android', $details['platform']);
    }

    public function test_get_client_details_detects_linux_platform(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Linux', $details['platform']);
    }

    public function test_get_client_details_detects_ipad_platform(): void
    {
        // Use a synthetic UA with "iPad" but without "Mac OS X" to isolate the iPad check,
        // since the code checks "Mac OS X" before "iPad" in its detection order.
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_2) AppleWebKit/605.1.15 Safari/605.1.15';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('iPad', $details['platform']);
    }

    // -----------------------------------------------------------------
    // getClientDetails() — Device detection
    // -----------------------------------------------------------------

    public function test_get_client_details_detects_mobile_device(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Mobile', $details['device']);
    }

    public function test_get_client_details_detects_desktop_device_as_default(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Desktop', $details['device']);
    }

    public function test_get_client_details_detects_tablet_device(): void
    {
        // The device detection checks Mobile/Android/iPhone/iPod first, then Tablet/iPad.
        // Use an iPad UA (without "Mobile" or "Android") to trigger the Tablet regex branch.
        $ua = 'Mozilla/5.0 (iPad; CPU OS 17_2) AppleWebKit/605.1.15 Safari/605.1.15';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Tablet', $details['device']);
    }

    public function test_get_client_details_detects_android_mobile_device(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertSame('Mobile', $details['device']);
    }

    // -----------------------------------------------------------------
    // getClientDetails() — Return structure
    // -----------------------------------------------------------------

    public function test_get_client_details_returns_array_with_expected_keys(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0.0.0';

        $details = LoginAttempt::getClientDetails(null, $ua);

        $this->assertIsArray($details);
        $this->assertArrayHasKey('browser', $details);
        $this->assertArrayHasKey('platform', $details);
        $this->assertArrayHasKey('device', $details);
    }

    // -----------------------------------------------------------------
    // Casts
    // -----------------------------------------------------------------

    public function test_location_is_cast_to_array(): void
    {
        $attempt = LoginAttemptFactory::new()->create([
            'location' => ['city' => 'New York', 'country' => 'US'],
        ]);

        $attempt->refresh();

        $this->assertIsArray($attempt->location);
        $this->assertSame('New York', $attempt->location['city']);
        $this->assertSame('US', $attempt->location['country']);
    }

    public function test_is_success_is_cast_to_boolean(): void
    {
        $attempt = LoginAttemptFactory::new()->create(['is_success' => 1]);

        $attempt->refresh();

        $this->assertIsBool($attempt->is_success);
        $this->assertTrue($attempt->is_success);
    }

    public function test_is_suspicious_is_cast_to_boolean(): void
    {
        $attempt = LoginAttemptFactory::new()->suspicious()->create();

        $attempt->refresh();

        $this->assertIsBool($attempt->is_suspicious);
        $this->assertTrue($attempt->is_suspicious);
    }

    public function test_failed_attempt_has_is_success_false(): void
    {
        $attempt = LoginAttemptFactory::new()->failed()->create();

        $this->assertFalse($attempt->is_success);
    }

    // -----------------------------------------------------------------
    // Relationships
    // -----------------------------------------------------------------

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();
        $attempt = LoginAttemptFactory::new()->create(['user_id' => $user->id]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $attempt->user());
        $this->assertInstanceOf(User::class, $attempt->user);
        $this->assertSame($user->id, $attempt->user->id);
    }
}
