<?php

namespace Ssntpl\Neev\Services\MagicLink;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Ssntpl\Neev\Events\MagicLinkConsumed;
use Ssntpl\Neev\Events\MagicLinkGenerated;
use Ssntpl\Neev\Events\MagicLinkRejected;
use Ssntpl\Neev\Exceptions\MagicLinkBindingException;
use Ssntpl\Neev\Exceptions\MagicLinkChannelException;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\MagicLinkToken;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;
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
     * apps can add their own (e.g. 'desktop') without changing Neev. A channel
     * that is not declared there is rejected rather than quietly downgraded to
     * 'web': a caller that asked for a mobile link and silently got a web one
     * has no way to notice.
     *
     * Always invalidates the user's existing link(s) for this channel, then
     * persists a fresh single-use token and fires MagicLinkGenerated.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>  ['url', 'token', 'channel', 'expires_at', 'expires_in', 'model']
     *
     * @throws MagicLinkBindingException     When binding is enabled but the
     *                                       request carries no binding source.
     * @throws MagicLinkChannelException     When the channel is not configured,
     *                                       or is a deep-link channel with no
     *                                       scheme/universal link set.
     */
    public function generate(object $user, string $channel = 'web', array $context = []): array
    {
        $channel = $this->normalizeChannel($channel);
        $context = $this->withRequest($context);
        $request = $context['request'] ?? null;

        // Runs before invalidating: a refused send must not cost the user the
        // link they already have.
        $metaData = $this->buildMetaData($request, $context);

        $plain = MagicLinkToken::generateToken();

        // Replacement must be atomic: without serialization two concurrent
        // sends both invalidate before either inserts, leaving two live links
        // for the same user and channel.
        $token = DB::transaction(function () use ($user, $channel, $metaData, $request, $plain) {
            MagicLinkToken::query()
                ->where('user_id', $user->id)
                ->where('channel', $channel)
                ->lockForUpdate()
                ->get();

            $this->invalidatePrevious($user->id, $channel);

            return MagicLinkToken::create([
                'user_id' => $user->id,
                'token' => MagicLinkToken::hashToken($plain),
                'channel' => $channel,
                'meta_data' => $metaData,
                'user_agent' => $request?->userAgent(),
                'created_ip' => $request?->ip(),
                'expires_at' => now()->addMinutes($this->expiryMinutes()),
            ]);
        });

        event(MagicLinkGenerated::fromToken($user, $token));

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
            event(MagicLinkRejected::fromResult($result));
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
            event(MagicLinkRejected::fromResult($result));
            return $result;
        }

        // Single-use: claim the row with a conditional delete. Two requests can
        // resolve the same token concurrently, so only the one whose delete
        // actually removed a row may proceed — the loser is treated as a replay.
        if (!$this->claim($result->token)) {
            $rejected = MagicLinkResult::failure(
                MagicLinkResult::INVALID,
                $result->channel,
                $result->token,
                $result->user,
            );
            event(MagicLinkRejected::fromResult($rejected));

            return $rejected;
        }

        $user = $result->user;

        // The link was mailed to this address and came back, which proves inbox
        // control just as the verification mail would — so redeeming doubles as
        // email verification.
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        $final = MagicLinkResult::valid($user, $result->channel, $result->token);
        event(MagicLinkConsumed::fromResult($user, $final));

        return $final;
    }

    /**
     * Atomically claim a resolved token for this request.
     *
     * The delete is conditional on the row still existing, and only the caller
     * whose statement removed exactly one row owns the redemption; every other
     * concurrent request sees zero affected rows and must not authenticate.
     */
    protected function claim(?MagicLinkToken $token): bool
    {
        if ($token === null) {
            return false;
        }

        return MagicLinkToken::query()->whereKey($token->getKey())->delete() === 1;
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
     * Resolve the user a token belongs to, or null.
     *
     * An unverified address is not a reason to refuse: the link was mailed to
     * that address and came back, which proves control of the inbox just as the
     * verification mail would — so consume() marks it verified instead.
     */
    protected function resolveUser(int|string|null $userId): ?object
    {
        if ($userId === null) {
            return null;
        }

        return User::model()->find($userId) ?: null;
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
     * A channel whose config declares a `scheme` or `universal_link` is treated
     * as a deep link (mobile/desktop/...). Otherwise it is a web URL built from
     * `base_url` + `path`. Works for any channel the host adds to config.
     *
     * @throws MagicLinkChannelException
     */
    protected function channelBaseUrl(string $channel): string
    {
        $config = (array) config("neev.magic_link.channels.{$channel}", []);

        // Deep-link channels (mobile, desktop, ...): scheme or universal link.
        $declaresDeepLink = array_key_exists('scheme', $config)
            || array_key_exists('universal_link', $config);

        $deepLink = $config['scheme'] ?? $config['universal_link'] ?? null;
        if (!empty($deepLink)) {
            return rtrim((string) $deepLink, '/');
        }

        // Declared as a deep-link channel but pointing nowhere — the shipped
        // 'mobile' channel with NEEV_MOBILE_SCHEME unset is exactly this.
        if ($declaresDeepLink) {
            throw MagicLinkChannelException::unconfiguredDeepLink($channel);
        }

        // Web-style channels: base URL + path.
        $base = rtrim($this->webBaseUrl($config), '/');
        $path = (string) ($config['path'] ?? $this->defaultWebPath());

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Default path for a web-style channel when config does not set one.
     *
     * The Blade kit redeems the token server-side on its own route, so the link
     * must land there directly. A headless install has no such route: the link
     * lands on the host app's page, which reads the token and posts it to the
     * API. Same reasoning as EmailLinks' route-vs-page split.
     */
    protected function defaultWebPath(): string
    {
        return config('neev.ui') === 'blade' ? '/login-link/verify' : '/login-link';
    }

    /**
     * Host for a web-style channel link.
     *
     * In tenant mode a tenant is reached at its own host (subdomain or custom
     * domain), and the token is stored tenant-scoped, so the link must point at
     * a host that belongs to the tenant. The host is taken from the tenant's
     * own verified domain records — never from the request. A request can name
     * its tenant with the X-Tenant header while carrying an attacker-controlled
     * Host, and trusting that header would mail the bearer token to a host the
     * attacker controls.
     *
     * Falls back to the configured `base_url` (or app.url) in shared mode, when
     * the tenant has no verified domain, and whenever there is no resolved
     * tenant (CLI / queued generation).
     *
     * @param  array<string, mixed>  $config
     */
    protected function webBaseUrl(array $config): string
    {
        $fallback = (string) ($config['base_url'] ?? config('app.url'));

        if (!config('neev.tenant', false)) {
            return $fallback;
        }

        $host = $this->verifiedTenantHost();

        return $host === null ? $fallback : $this->schemeFor($fallback) . '://' . $host;
    }

    /**
     * The current tenant's own verified host, preferring its primary domain.
     *
     * Only DNS-verified domains owned by the resolved tenant qualify: those are
     * the hosts the tenant has proven control of.
     */
    protected function verifiedTenantHost(): ?string
    {
        if (!$this->container->bound(TenantResolver::class)) {
            return null;
        }

        $context = $this->container->make(TenantResolver::class)->resolvedContext();

        if ($context === null) {
            return null;
        }

        $domain = Domain::query()
            ->where('owner_type', $context->getContextType())
            ->where('owner_id', $context->getContextId())
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        return $domain?->domain;
    }

    /**
     * URL scheme to use for a tenant host, taken from the configured base URL.
     */
    protected function schemeFor(string $base): string
    {
        $scheme = parse_url($base, PHP_URL_SCHEME);

        return is_string($scheme) && $scheme !== '' ? $scheme : 'https';
    }

    /**
     * Resolve a requested channel, defaulting to 'web' when none is given.
     *
     * An undeclared channel is rejected, not downgraded to 'web'. A typo such
     * as 'mobil' would otherwise mint a perfectly valid *web* token in response
     * to a mobile request, and neither the caller nor the operator would see a
     * thing.
     *
     * @throws MagicLinkChannelException
     */
    protected function normalizeChannel(?string $channel): string
    {
        $channel = $channel ?: 'web';
        $channels = array_keys((array) config('neev.magic_link.channels', []));

        // An install that configures no channels at all still gets the built-in
        // web channel; anything beyond that has to be declared.
        if ($channels === [] && $channel === 'web') {
            return 'web';
        }

        if (!in_array($channel, $channels, true)) {
            throw MagicLinkChannelException::unknown($channel, $channels);
        }

        return $channel;
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
