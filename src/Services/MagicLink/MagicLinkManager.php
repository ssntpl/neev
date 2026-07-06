<?php

namespace Ssntpl\Neev\Services\MagicLink;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Ssntpl\Neev\Events\MagicLink\MagicLinkConsumed;
use Ssntpl\Neev\Events\MagicLink\MagicLinkGenerated;
use Ssntpl\Neev\Events\MagicLink\MagicLinkRejected;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Support\MagicLink\MagicLinkResult;

/**
 * Stateful, single-use, channel-aware magic-link engine.
 *
 * Tokens are opaque and high-entropy; only their hash is persisted. Single-use
 * is enforced by deleting the row on consumption, and generating a new link
 * always invalidates the user's previous link(s) for that channel.
 *
 * Neev manages token lifecycle and security policy only — it never renders UI,
 * handles deep-link routing, or performs frontend redirects.
 */
class MagicLinkManager
{
    public function __construct(
        protected Container $container,
    ) {
    }

    /**
     * Generate a web-channel magic link.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>  ['url', 'token', 'channel', 'expires_at', 'expires_in', 'model']
     */
    public function forWeb(object $user, array $context = []): array
    {
        return $this->generate($user, 'web', $context);
    }

    /**
     * Generate a mobile-channel magic link.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function forMobile(object $user, array $context = []): array
    {
        return $this->generate($user, 'mobile', $context);
    }

    /**
     * Issue a new magic link.
     *
     * The channel is any key under config('neev.magic_link.channels') — host
     * apps can add their own (e.g. 'desktop') without changing Neev. Unknown
     * channels fall back to 'web'.
     *
     * Always invalidates the user's existing link(s) for this channel, then
     * persists a fresh single-use token and fires MagicLinkGenerated.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>  ['url', 'token', 'channel', 'expires_at', 'expires_in', 'model']
     */
    public function generate(object $user, string $channel = 'web', array $context = []): array
    {
        $channel = $this->normalizeChannel($channel);
        $context = $this->withRequest($context);
        $request = $context['request'] ?? null;

        $this->invalidatePrevious($user->id, $channel);

        $plain = MagicLinkToken::generateToken();

        $token = MagicLinkToken::create([
            'user_id' => $user->id,
            'token' => MagicLinkToken::hashToken($plain),
            'channel' => $channel,
            'meta_data' => $this->buildMetaData($request, $context),
            'user_agent' => $request?->userAgent(),
            'created_ip' => $request?->ip(),
            'expires_at' => now()->addMinutes($this->expiryMinutes()),
        ]);

        MagicLinkGenerated::dispatch($user, $token);

        return $this->linkPayload($token, $plain);
    }

    /**
     * Delete the user's existing links for a channel (invalidate previous).
     */
    protected function invalidatePrevious(int $userId, string $channel): void
    {
        MagicLinkToken::query()
            ->where('user_id', $userId)
            ->where('channel', $channel)
            ->delete();
    }

    /**
     * Number of minutes a generated link stays valid.
     */
    protected function expiryMinutes(): int
    {
        return (int) config('neev.magic_link.expires_in', 10);
    }

    /**
     * Build the JSON-able metadata stored on a token (currently the binding
     * fingerprint), or null when there's nothing to store.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    protected function buildMetaData(?Request $request, array $context): ?array
    {
        $fingerprint = $request ? $this->fingerprint($request, $context) : null;

        return $fingerprint !== null ? ['fingerprint' => $fingerprint] : null;
    }

    /**
     * Shape the array returned to callers after generating a link.
     *
     * @return array<string, mixed>
     */
    protected function linkPayload(MagicLinkToken $token, string $plain): array
    {
        return [
            'url' => $this->buildChannelUrl($token->channel, ['token' => $plain, 'channel' => $token->channel]),
            'token' => $plain,
            'channel' => $token->channel,
            'expires_at' => $token->expires_at,
            'expires_in' => max(0, (int) ceil(($token->expires_at->getTimestamp() - now()->getTimestamp()) / 60)),
            'model' => $token,
        ];
    }

    /**
     * Validate a redemption request WITHOUT consuming the token.
     *
     * Safe to call from a GET handler / link preview: it never authenticates
     * and never deletes the token.
     *
     * @param  array<string, mixed>  $context
     */
    public function validate(Request $request, array $context = []): MagicLinkResult
    {
        $result = $this->resolve($request, $context);

        if (!$result->isValid() && !$result->needsConfirmation()) {
            MagicLinkRejected::dispatch($result);
        }

        return $result;
    }

    /**
     * Validate AND consume the token (single-use), completing the redemption.
     *
     * On success the token row is deleted and MagicLinkConsumed is fired.
     *
     * @param  array<string, mixed>  $context
     */
    public function consume(Request $request, array $context = []): MagicLinkResult
    {
        $result = $this->resolve($request, $context);

        // A pending-confirmation token is eligible for consumption: this call
        // IS the explicit confirmation step.
        if (!$result->isValid() && !$result->needsConfirmation()) {
            MagicLinkRejected::dispatch($result);
            return $result;
        }

        // Single-use: deleting the row prevents any replay.
        $result->token?->delete();

        $final = MagicLinkResult::valid($result->user, $result->channel, $result->token);
        MagicLinkConsumed::dispatch($result->user, $final);

        return $final;
    }

    /**
     * Resolve the status of a redemption request without side effects or events.
     *
     * @param  array<string, mixed>  $context
     */
    protected function resolve(Request $request, array $context = []): MagicLinkResult
    {
        $plain = $this->extractToken($request, $context);
        if ($plain === null || $plain === '') {
            return MagicLinkResult::failure(MagicLinkResult::INVALID);
        }

        $record = MagicLinkToken::findByToken($plain);
        if (!$record) {
            return MagicLinkResult::failure(MagicLinkResult::INVALID);
        }

        $channel = $record->channel;

        if ($record->isExpired()) {
            return MagicLinkResult::failure(MagicLinkResult::EXPIRED, $channel, $record);
        }

        if (config('neev.magic_link.bind_to_browser', false)
            && !$this->bindingMatches($record->fingerprint(), $request, $context)) {
            return MagicLinkResult::failure(MagicLinkResult::BINDING_MISMATCH, $channel, $record);
        }

        $user = $this->resolveUser($record->user_id);
        if (!$user) {
            return MagicLinkResult::failure(MagicLinkResult::INVALID, $channel, $record);
        }

        if (!$user->active) {
            return MagicLinkResult::failure(MagicLinkResult::INACTIVE_USER, $channel, $record, $user);
        }

        if (config('neev.magic_link.require_confirmation', false)) {
            return MagicLinkResult::failure(MagicLinkResult::PENDING_CONFIRMATION, $channel, $record, $user);
        }

        return MagicLinkResult::valid($user, $channel, $record);
    }

    /**
     * Resolve an eligible user (exists + verified email) or null.
     */
    protected function resolveUser(int|string|null $userId): ?object
    {
        if ($userId === null) {
            return null;
        }

        $user = User::model()->find($userId);

        if (!$user || !$user->hasVerifiedEmail()) {
            return null;
        }

        return $user;
    }


    /**
     * @param  array<string, mixed>  $context
     */
    protected function extractToken(Request $request, array $context = []): ?string
    {
        if (!empty($context['token'])) {
            return (string) $context['token'];
        }

        $token = $request->input('token');

        return $token === null ? null : (string) $token;
    }

    // -----------------------------------------------------------------
    // Browser / device binding
    // -----------------------------------------------------------------

    /**
     * Capture a binding fingerprint for the current request/context.
     *
     * Precedence: an explicit binding value (context['binding'], the `binding`
     * request field, or the X-Device-Id header) for session-less clients, then
     * the web session id. Only the SHA-256 hash is ever stored.
     *
     * @param  array<string, mixed>  $context
     */
    protected function fingerprint(Request $request, array $context = []): ?string
    {
        $source = $this->bindingSource($request, $context);

        return $source === null ? null : hash('sha256', $source);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function bindingMatches(?string $storedFingerprint, Request $request, array $context = []): bool
    {
        if ($storedFingerprint === null) {
            return false;
        }

        $current = $this->fingerprint($request, $context);

        return $current !== null && hash_equals($storedFingerprint, $current);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function bindingSource(Request $request, array $context = []): ?string
    {
        if (!empty($context['binding'])) {
            return (string) $context['binding'];
        }

        $fromRequest = $request->input('binding') ?? $request->header('X-Device-Id');
        if (!empty($fromRequest)) {
            return (string) $fromRequest;
        }

        if ($request->hasSession()) {
            $session = $request->session();
            if (!$session->isStarted()) {
                $session->start();
            }
            $id = $session->getId();

            return $id !== '' ? $id : null;
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Channel URL building
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $params
     */
    protected function buildChannelUrl(string $channel, array $params): string
    {
        $base = $this->channelBaseUrl($channel);
        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query($params);
    }

    /**
     * Build the base redemption URL for any configured channel.
     *
     * A channel whose config has a `scheme` or `universal_link` is treated as a
     * deep link (mobile/desktop/...). Otherwise it is a web URL built from
     * `base_url` + `path`. Works for any channel the host adds to config.
     */
    protected function channelBaseUrl(string $channel): string
    {
        $config = (array) config("neev.magic_link.channels.{$channel}", []);

        // Deep-link channels (mobile, desktop, ...): scheme or universal link.
        $deepLink = $config['scheme'] ?? $config['universal_link'] ?? null;
        if (!empty($deepLink)) {
            return rtrim((string) $deepLink, '/');
        }

        // Web-style channels: base URL + path.
        $base = rtrim((string) ($config['base_url'] ?? config('app.url')), '/');
        $path = (string) ($config['path'] ?? '/login-link');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Resolve a requested channel to a valid configured channel, defaulting to
     * 'web' when it is empty or not defined in config.
     */
    protected function normalizeChannel(?string $channel): string
    {
        $channel = $channel ?: 'web';
        $channels = array_keys((array) config('neev.magic_link.channels', []));

        return in_array($channel, $channels, true) ? $channel : 'web';
    }

    /**
     * Default the current request into the context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function withRequest(array $context): array
    {
        if (!isset($context['request']) && $this->container->bound('request')) {
            $context['request'] = $this->container->make('request');
        }

        return $context;
    }
}
