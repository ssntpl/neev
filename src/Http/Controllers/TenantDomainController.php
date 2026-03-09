<?php

namespace Ssntpl\Neev\Http\Controllers;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Services\TenantResolver;

class TenantDomainController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver
    ) {
    }

    /**
     * List all tenant domains for a team.
     */
    public function index(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);

        if (!$user || !$team) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Team not found.',
            ], 400);
        }

        if (!$team->hasUser($user)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You do not have access to this team.',
            ], 403);
        }

        $domains = $team->domains;

        return response()->json([
            'status' => 'Success',
            'data' => $domains,
        ]);
    }

    /**
     * Add a new tenant domain.
     */
    public function store(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        /** @var Team|null $team */
        $team = Team::model()->find($request->team_id);

        if (!$user || !$team) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Team not found.',
            ], 400);
        }

        // Only team owner can add domains
        if ($team->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only team owner can add domains.',
            ], 403);
        }

        $request->validate([
            'domain' => 'required|string|unique:domains,domain',
            'type' => 'in:subdomain,custom',
        ]);

        try {
            $type = $request->type ?? 'custom';
            $domain = $request->domain;

            $tenantDomain = new Domain([
                'owner_type' => 'team',
                'owner_id' => $team->id,
                'domain' => $domain,
                'is_primary' => $team->domains()->count() === 0, // First domain is primary
            ]);

            // Subdomains are auto-verified; custom domains need DNS verification
            if ($type === 'subdomain') {
                $tenantDomain->verified_at = now();
                $tenantDomain->save();
                $token = null;
            } else {
                $token = $tenantDomain->generateVerificationToken();
                $tenantDomain->save();
            }

            $response = [
                'status' => 'Success',
                'message' => 'Domain added successfully.',
                'data' => $tenantDomain,
            ];

            if (isset($token)) {
                $response['verification_token'] = $token;
                $response['dns_record'] = [
                    'type' => 'TXT',
                    'name' => $tenantDomain->getDnsRecordName(),
                    'value' => $token,
                ];
            }

            return response()->json($response);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Unable to add domain.',
            ], 400);
        }
    }

    /**
     * Get a specific tenant domain.
     */
    public function show(Request $request, $id)
    {
        $user = User::model()->find($request->user()?->id);
        $tenantDomain = Domain::find($id);

        if (!$tenantDomain) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Domain not found.',
            ], 404);
        }

        /** @var Team|null $owner */
        $owner = $tenantDomain->owner;
        if (!$owner || !$owner->hasUser($user)) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'You do not have access to this domain.',
            ], 403);
        }

        return response()->json([
            'status' => 'Success',
            'data' => $tenantDomain,
        ]);
    }

    /**
     * Delete a tenant domain.
     */
    public function destroy(Request $request, $id)
    {
        $user = User::model()->find($request->user()?->id);
        $tenantDomain = Domain::find($id);

        if (!$tenantDomain) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Domain not found.',
            ], 404);
        }

        /** @var Team|null $owner */
        $owner = $tenantDomain->owner;
        if (!$owner || $owner->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only team owner can delete domains.',
            ], 403);
        }

        // Don't allow deleting the last domain
        if ($owner->domains()->count() <= 1) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Cannot delete the last domain. Team must have at least one domain.',
            ], 400);
        }

        try {
            $wasPrimary = $tenantDomain->is_primary;
            $tenantDomain->delete();

            // If we deleted the primary domain, set another as primary
            if ($wasPrimary) {
                $newPrimary = $owner->domains()->first();
                if ($newPrimary) {
                    $newPrimary->markAsPrimary();
                }
            }

            return response()->json([
                'status' => 'Success',
                'message' => 'Domain deleted successfully.',
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Unable to delete domain.',
            ], 400);
        }
    }

    /**
     * Verify a custom domain via DNS.
     */
    public function verify(Request $request, $id)
    {
        $user = User::model()->find($request->user()?->id);
        $tenantDomain = Domain::find($id);

        if (!$tenantDomain) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Domain not found.',
            ], 404);
        }

        /** @var Team|null $owner */
        $owner = $tenantDomain->owner;
        if (!$owner || $owner->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only team owner can verify domains.',
            ], 403);
        }

        if ($tenantDomain->isVerified()) {
            return response()->json([
                'status' => 'Success',
                'message' => 'Domain is already verified.',
            ]);
        }

        try {
            if ($tenantDomain->verify()) {
                return response()->json([
                    'status' => 'Success',
                    'message' => 'Domain verified successfully.',
                    'data' => $tenantDomain->fresh(),
                ]);
            }

            return response()->json([
                'status' => 'Failed',
                'message' => 'DNS verification failed. Please check your DNS record.',
            ], 400);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Unable to verify domain.',
            ], 400);
        }
    }

    /**
     * Regenerate verification token for a domain.
     */
    public function regenerateToken(Request $request, $id)
    {
        $user = User::model()->find($request->user()?->id);
        $tenantDomain = Domain::find($id);

        if (!$tenantDomain) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Domain not found.',
            ], 404);
        }

        /** @var Team|null $owner */
        $owner = $tenantDomain->owner;
        if (!$owner || $owner->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only team owner can regenerate tokens.',
            ], 403);
        }

        if (!$tenantDomain->verification_token) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only domains with verification tokens can be regenerated.',
            ], 400);
        }

        try {
            $token = $tenantDomain->generateVerificationToken();
            $tenantDomain->verified_at = null;
            $tenantDomain->save();

            return response()->json([
                'status' => 'Success',
                'message' => 'Verification token regenerated.',
                'verification_token' => $token,
                'dns_record' => [
                    'type' => 'TXT',
                    'name' => $tenantDomain->getDnsRecordName(),
                    'value' => $token,
                ],
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Unable to regenerate verification token.',
            ], 400);
        }
    }

    /**
     * Set a domain as the primary domain.
     */
    public function setPrimary(Request $request, $id)
    {
        $user = User::model()->find($request->user()?->id);
        $tenantDomain = Domain::find($id);

        if (!$tenantDomain) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Domain not found.',
            ], 404);
        }

        /** @var Team|null $owner */
        $owner = $tenantDomain->owner;
        if (!$owner || $owner->user_id !== $user->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only team owner can change primary domain.',
            ], 403);
        }

        if (!$tenantDomain->isVerified()) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Only verified domains can be set as primary.',
            ], 400);
        }

        try {
            $tenantDomain->markAsPrimary();

            return response()->json([
                'status' => 'Success',
                'message' => 'Domain set as primary.',
                'data' => $tenantDomain,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => 'Unable to set primary domain.',
            ], 400);
        }
    }

    /**
     * Get the current tenant from the request.
     */
    public function currentTenant(Request $request)
    {
        $tenant = $this->tenantResolver->current();
        $tenantDomain = $this->tenantResolver->currentDomain();

        if (!$tenant) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'No tenant context.',
            ], 400);
        }

        return response()->json([
            'status' => 'Success',
            'data' => [
                'team' => $tenant,
                'domain' => $tenantDomain,
            ],
        ]);
    }
}
