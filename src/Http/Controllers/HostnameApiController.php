<?php

namespace Ssntpl\Neev\Http\Controllers;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Http\Controllers\Concerns\AuthorizesDomainOwners;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Tenant;
use Ssntpl\Neev\Rules\Hostname as HostnameRule;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Support\PlatformHost;

/**
 * A team's or tenant's custom hosts (RFC 006): where it is served, each proven
 * by a TXT record at `_neev-host.<host>`. Neev checks only that the caller
 * belongs to the owner (AuthorizesDomainOwners); who among them may change
 * them is the application's own middleware's to decide.
 * The platform subdomain follows the slug and is listed, not stored.
 */
class HostnameApiController extends Controller
{
    use AuthorizesDomainOwners;

    public function index(Request $request, Team $team): JsonResponse
    {
        if (!$this->belongsToOwner($request, $team)) {
            return $this->forbidden();
        }

        return $this->listFor($team);
    }

    public function store(Request $request, Team $team): JsonResponse
    {
        if (!$this->belongsToOwner($request, $team)) {
            return $this->forbidden();
        }

        return $this->claimFor($request, $team);
    }

    /**
     * The hosts of the tenant this request resolved to.
     */
    public function tenantIndex(Request $request, TenantResolver $resolver): JsonResponse
    {
        $tenant = $resolver->currentTenant();

        if (!$tenant) {
            return $this->noTenant();
        }

        if (!$this->belongsToOwner($request, $tenant)) {
            return $this->forbidden();
        }

        return $this->listFor($tenant);
    }

    public function tenantStore(Request $request, TenantResolver $resolver): JsonResponse
    {
        $tenant = $resolver->currentTenant();

        if (!$tenant) {
            return $this->noTenant();
        }

        if (!$this->belongsToOwner($request, $tenant)) {
            return $this->forbidden();
        }

        return $this->claimFor($request, $tenant);
    }

    protected function listFor(Team|Tenant $owner): JsonResponse
    {
        return response()->json([
            'data' => $owner->hostnames()->orderBy('id')->get(),
            'platform_host' => $owner->platformHost(),
            'primary_hostname_id' => $owner->primary_hostname_id,
        ]);
    }

    protected function claimFor(Request $request, Team|Tenant $owner): JsonResponse
    {
        $request->validate([
            'host' => [
                'bail',
                'required',
                'string',
                'max:255',
                new HostnameRule(),
                function (string $attribute, mixed $value, Closure $fail) use ($owner) {
                    if (PlatformHost::covers($value)) {
                        $fail('A host under the platform domain follows the slug and cannot be added.');
                    } elseif ($owner->hostnames()->forHost($value)->exists()) {
                        $fail("This {$owner->getContextType()} has already added this host.");
                    }
                },
            ],
        ]);

        try {
            $hostname = $owner->claimHost($request->host);
        } catch (HostnameTakenException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['host' => $e->getMessage()]);
        }

        return $this->withRecord('Host added.', $hostname, 201);
    }

    public function show(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->belongsToOwner($request, $hostname->owner)) {
            return $this->forbidden();
        }

        return response()->json(['data' => $hostname]);
    }

    public function destroy(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->belongsToOwner($request, $hostname->owner)) {
            return $this->forbidden();
        }

        $hostname->release();

        return response()->json(['message' => 'Host deleted.']);
    }

    public function verify(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->belongsToOwner($request, $hostname->owner)) {
            return $this->forbidden();
        }

        // verify() leaves a disabled row alone; say why rather than blame DNS.
        if ($hostname->status === Hostname::STATUS_DISABLED) {
            return response()->json(['message' => 'This host is disabled.'], 400);
        }

        if (!$hostname->verify()) {
            return response()->json(['message' => 'DNS verification failed. Please check your DNS record.'], 400);
        }

        return response()->json(['message' => 'Host verified.', 'data' => $hostname]);
    }

    /**
     * A new token. A verified host keeps serving; the daily re-check holds it
     * to the new record.
     */
    public function token(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->belongsToOwner($request, $hostname->owner)) {
            return $this->forbidden();
        }

        if ($hostname->generateVerificationToken() === null) {
            return response()->json(['message' => 'This host is disabled.'], 400);
        }

        return $this->withRecord('Verification token issued.', $hostname);
    }

    public function primary(Request $request, Hostname $hostname): JsonResponse
    {
        if (!$this->belongsToOwner($request, $hostname->owner)) {
            return $this->forbidden();
        }

        if (!$hostname->isVerified()) {
            return response()->json(['message' => 'Only a verified host can be primary.'], 400);
        }

        /** @var Team|Tenant $owner */
        $owner = $hostname->owner;
        $owner->makePrimaryHostname($hostname);

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


    protected function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'You do not have permission to do this.'], 403);
    }

    protected function noTenant(): JsonResponse
    {
        return response()->json(['message' => 'No tenant context.'], 400);
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
