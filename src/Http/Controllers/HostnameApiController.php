<?php

namespace Ssntpl\Neev\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Rules\Hostname as HostnameRule;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\PlatformHost;

/**
 * A team's custom hosts (RFC 006): where it is served, each proven by a TXT
 * record at `_neev-host.<host>`. Members read them; only the owner changes
 * them. The platform subdomain follows the slug and is listed, not stored.
 */
class HostnameApiController extends Controller
{
    public function index(Request $request, Team $team): JsonResponse
    {
        if (!$team->hasMember($request->user())) {
            return $this->forbidden();
        }

        return response()->json([
            'data' => $team->hostnames()->orderBy('id')->get(),
            'platform_host' => $team->platformHost(),
            'primary_hostname_id' => $team->primary_hostname_id,
        ]);
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        if (!$this->owns($request, $team)) {
            return $this->forbidden();
        }

        $request->validate([
            'host' => [
                'bail',
                'required',
                'string',
                'max:255',
                new HostnameRule(),
                function (string $attribute, mixed $value, Closure $fail) use ($team) {
                    if (PlatformHost::covers($value)) {
                        $fail('A host under the platform domain follows the slug and cannot be added.');
                    } elseif ($team->hostnames()->forHost($value)->exists()) {
                        $fail('This team has already added this host.');
                    }
                },
            ],
        ]);

        try {
            $hostname = $team->claimHost($request->host);
        } catch (HostnameTakenException $e) {
            throw ValidationException::withMessages(['host' => $e->getMessage()]);
        }

        return $this->withRecord('Host added.', $hostname, 201);
    }

    public function show(Request $request, Hostname $hostname): JsonResponse
    {
        $team = $hostname->owner;
        if (!$team instanceof Team || !$team->hasMember($request->user())) {
            return $this->forbidden();
        }

        return response()->json(['data' => $hostname]);
    }

    public function destroy(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->ownsHost($request, $hostname)) {
            return $this->forbidden();
        }

        $hostname->release();

        return response()->json(['message' => 'Host deleted.']);
    }

    public function verify(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->ownsHost($request, $hostname)) {
            return $this->forbidden();
        }

        if (!$hostname->verify()) {
            return response()->json(['message' => 'DNS verification failed. Please check your DNS record.'], 400);
        }

        return response()->json(['message' => 'Host verified.', 'data' => $hostname]);
    }

    /**
     * A new token. The host stops serving until its new record is checked.
     */
    public function token(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->ownsHost($request, $hostname)) {
            return $this->forbidden();
        }

        if ($hostname->generateVerificationToken() === null) {
            return response()->json(['message' => 'This host is disabled.'], 400);
        }

        return $this->withRecord('Verification token issued.', $hostname);
    }

    public function primary(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->ownsHost($request, $hostname)) {
            return $this->forbidden();
        }

        if (!$hostname->isVerified()) {
            return response()->json(['message' => 'Only a verified host can be primary.'], 400);
        }

        /** @var Team $team */
        $team = $hostname->owner;
        $team->makePrimaryHostname($hostname);

        return response()->json(['message' => 'Primary host set.', 'data' => $hostname]);
    }

    /**
     * The context this request resolved to, and the custom host it came in
     * on, if any.
     */
    public function current(TenantResolver $resolver): JsonResponse
    {
        $context = $resolver->resolvedContext();

        if (!$context) {
            return response()->json(['message' => 'No tenant context.'], 400);
        }

        return response()->json([
            'data' => [
                'type' => $context->getContextType(),
                'context' => $context,
                'hostname' => $resolver->currentHostname(),
            ],
        ]);
    }

    protected function owns(Request $request, Team $team): bool
    {
        return $team->user_id === $request->user()?->getKey();
    }

    protected function ownsHost(Request $request, Hostname $hostname): bool
    {
        return $hostname->owner instanceof Team && $this->owns($request, $hostname->owner);
    }

    protected function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You do not have permission to do this.'], 403);
    }

    protected function withRecord(string $message, Hostname $hostname, int $status = 200): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => $hostname,
            'dns_record' => [
                'type' => 'TXT',
                'name' => $hostname->getDnsRecordName(),
                'value' => $hostname->verification_token,
            ],
        ], $status);
    }
}
