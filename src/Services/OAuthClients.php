<?php

namespace Ssntpl\Neev\Services;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * Per-platform OAuth clients for a single provider.
 *
 * Providers hand out a separate client per platform — Google will not accept
 * a web client_id from an Android app, and a native client needs its own
 * redirect (a custom scheme or app link) rather than this application's
 * callback URL. Socialite reads one `services.<provider>` block, so the extra
 * clients live under a `clients` key inside it:
 *
 *     'google' => [
 *         'client_id' => env('GOOGLE_CLIENT_ID'),        // the web client
 *         'client_secret' => env('GOOGLE_CLIENT_SECRET'),
 *         'redirect' => env('GOOGLE_REDIRECT_URI'),
 *         'clients' => [
 *             'android' => [
 *                 'client_id' => env('GOOGLE_ANDROID_CLIENT_ID'),
 *                 'client_secret' => env('GOOGLE_ANDROID_CLIENT_SECRET'),
 *                 'redirect' => env('GOOGLE_ANDROID_REDIRECT_URI'),
 *             ],
 *             'ios' => [...],
 *         ],
 *     ],
 *
 * A platform block inherits everything it does not override (scopes, guzzle
 * options), so it usually only carries the credentials that actually differ.
 * With no platform asked for, the provider behaves exactly as before.
 */
class OAuthClients
{
    /**
     * Is this platform configured for the provider?
     */
    public function has(string $service, string $platform): bool
    {
        return is_array($this->overridesFor($service, $platform));
    }

    /**
     * Every platform configured for the provider, for error messages.
     *
     * @return array<int, string>
     */
    public function platforms(string $service): array
    {
        return array_keys((array) config("services.{$service}.clients", []));
    }

    /**
     * Build the driver for a platform, or the default client without one.
     *
     * Deliberately untyped: Socialite::driver() is declared to return the
     * Provider contract, and only the concrete providers carry stateless(),
     * which is what the callers need.
     *
     * @return AbstractProvider|Provider
     */
    public function driver(string $service, ?string $platform)
    {
        $overrides = $platform ? $this->overridesFor($service, $platform) : null;

        if (! $overrides) {
            return Socialite::driver($service);
        }

        $config = array_merge((array) config("services.{$service}", []), $overrides);
        unset($config['clients']);

        // Built by Socialite itself with the platform's credentials swapped
        // in, rather than from a provider class worked out here: drivers
        // registered with Socialite::extend() keep working, and an install
        // that configures only platform clients — a mobile-only app, with no
        // top-level block for Socialite to read — resolves too.
        $original = config("services.{$service}");

        config()->set("services.{$service}", $config);
        Socialite::forgetDrivers();

        try {
            return Socialite::driver($service);
        } finally {
            // The driver keeps the credentials it was constructed with, so the
            // config goes back and the next resolution gets the usual client.
            config()->set("services.{$service}", $original);
            Socialite::forgetDrivers();
        }
    }

    /**
     * Where the provider sends the user back.
     *
     * A native client brings its own redirect; everything else comes back to
     * this application's callback route.
     */
    public function redirectUrl(string $service, ?string $platform): string
    {
        $overrides = $platform ? $this->overridesFor($service, $platform) : null;

        if (is_array($overrides) && ! empty($overrides['redirect'])) {
            return $overrides['redirect'];
        }

        return app(EmailLinks::class)->oauthCallbackUrl($service);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function overridesFor(string $service, string $platform): ?array
    {
        $overrides = config("services.{$service}.clients.{$platform}");

        return is_array($overrides) ? $overrides : null;
    }
}
