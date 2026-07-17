<?php

namespace Ssntpl\Neev\Services\MagicLink;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Ssntpl\Neev\Events\MagicLinkConsumed;
use Ssntpl\Neev\Events\MagicLinkGenerated;
use Ssntpl\Neev\Events\MagicLinkRejected;
use Ssntpl\Neev\Exceptions\MagicLinkBindingException;
use Ssntpl\Neev\Exceptions\MagicLinkUnverifiedException;
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
     *
     * @throws MagicLinkBindingException     When binding is enabled but the
     *                                       request carries no binding source.
     * @throws MagicLinkUnverifiedException  When the user's email is unverified
     *                                       and the unverified policy forbids it.
     */
    public function generate(object $user, string $channel = 'web', array $context = []): array
    {
        $channel = $this->normalizeChannel($channel);
        $context = $this->withRequest($context);
        $request = $context['request'] ?? null;

        // Both checks run before invalidating: a refused send must not cost the
        // user the link they already have.
        $this->assertUserMayReceiveLink($user);
        $metaData = $this->buildMetaData($request, $context);

        $this->invalidatePrevious($user->id, $channel);

        $plain = MagicLinkToken::generateToken();

        $token = MagicLinkToken::create([
            'user_id' => $user->id,
            'token' => MagicLinkToken::hashToken($plain),
            'channel' => $channel,
            'meta_data' => $metaData,
            'user_agent' => $request?->userAgent(),
            'created_ip' => $request?->ip(),
            'expires_at' => now()->addMinutes($this->expiryMinutes()),
        ]);

        MagicLinkGenerated::dispatch($user, $token);

        return $this->linkPayload($token, $plain);
    }

    /**
     * Refuse to issue a link the recipient could never use.
     *
     * Without this the link is mailed, and every redemption of it fails as
     * "invalid or expired" — a dead end the user cannot get out of.
     *
     * @throws MagicLinkUnverifiedException
     */
    protected function assertUserMayReceiveLink(object $user): void
    {
        if (!$user->hasVerifiedEmail() && !$this->allowsUnverifiedUsers()) {
            throw MagicLinkUnverifiedException::forEmail($user->email);
        }
    }

    /**
     * Whether users with an unverified email may use magic links at all.
     *
     * When enabled, redeeming a link also marks the email verified — following
     * the link is itself proof of control over the inbox.
     */
    protected function allowsUnverifiedUsers(): bool
    {
        return (bool) config('neev.magic_link.allow_unverified_users', false);
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
     * When binding is enabled this throws rather than returning null: a token
     * stored without a fingerprint can never pass the redemption check, so
     * failing here surfaces the misconfiguration at send time instead of
     * locking the user out of a link that was dead when it was minted.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     *
     * @throws MagicLinkBindingException
     */
    protected function buildMetaData(?Request $request, array $context): ?array
    {
        $fingerprint = $request ? $this->fingerprint($request, $context) : null;

        if ($fingerprint === null && $this->bindingEnabled()) {
            throw new MagicLinkBindingException();
        }

        return $fingerprint !== null ? ['fingerprint' => $fingerprint] : null;
    }

    /**
     * Whether links are bound to the browser/device that requested them.
     */
    protected function bindingEnabled(): bool
    {
        return (bool) config('neev.magic_link.bind_to_browser', false);
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

        $user = $result->user;

        // The click proved control of the inbox, so redeeming doubles as email
        // verification. Only reachable when the unverified policy allows it —
        // resolve() rejects unverified users otherwise.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $final = MagicLinkResult::valid($user, $result->channel, $result->token);
        MagicLinkConsumed::dispatch($user, $final);

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

        if ($this->bindingEnabled()
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
     * Resolve an eligible user, or null.
     *
     * An unverified user is only eligible when the unverified policy allows it,
     * in which case consume() marks the email verified on redemption.
     */
    protected function resolveUser(int|string|null $userId): ?object
    {
        if ($userId === null) {
            return null;
        }

        $user = User::model()->find($userId);

        if (!$user) {
            return null;
        }

        if (!$user->hasVerifiedEmail() && !$this->allowsUnverifiedUsers()) {
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
            return is_scalar($context['token']) ? (string) $context['token'] : null;
        }

        $token = $request->input('token');

        // Client-controlled input: `?token[]=a&token[]=b` arrives as an array,
        // and casting that to string raises "Array to string conversion" — a
        // 500 where a plain rejection belongs.
        return is_scalar($token) ? (string) $token : null;
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
        // Fail closed. Safe because generation refuses to mint an unbound token
        // while binding is on: a null fingerprint here means the link predates
        // the setting, and those links should not bypass the check.
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
        $base = rtrim($this->webBaseUrl($config), '/');
        $path = (string) ($config['path'] ?? '/login-link');

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Host for a web-style channel link.
     *
     * In tenant mode a tenant is reached at its own host (subdomain or custom
     * domain), and the token is stored tenant-scoped. A link must therefore be
     * redeemed on the tenant's host — on any other host the tenant scope hides
     * the token and redemption fails as "invalid or expired". The current
     * request already arrived on that host, so it is the authoritative base;
     * the static `base_url` config cannot represent more than one tenant.
     *
     * Falls back to the configured `base_url` (or app.url) in shared mode and
     * whenever there is no request (CLI / queued generation).
     *
     * @param  array<string, mixed>  $config
     */
    protected function webBaseUrl(array $config): string
    {
        if (config('neev.tenant', false)) {
            $request = $this->container->bound('request')
                ? $this->container->make('request')
                : null;

            if ($request instanceof Request && $request->getHost() !== '') {
                return $request->getSchemeAndHttpHost();
            }
        }

        return (string) ($config['base_url'] ?? config('app.url'));
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
