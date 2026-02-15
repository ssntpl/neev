<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Http\Controllers\Controller;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Services\AuthService;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\TenantResolver;
use Ssntpl\Neev\Services\TenantSSOManager;

/**
 * Controller for handling tenant-specific SSO authentication.
 *
 * Handles the SSO redirect and callback flow for tenants that have
 * configured external identity providers (Microsoft Entra ID, Google, etc.).
 */
class TenantSSOController extends Controller
{
    public function __construct(
        protected TenantResolver $tenantResolver,
        protected TenantSSOManager $ssoManager,
        protected AuthService $authService
    ) {
    }

    /**
     * Get the tenant's authentication configuration.
     *
     * GET /api/tenant/auth
     *
     * Returns the auth method (password or sso) and SSO redirect URL if applicable.
     * This is a public endpoint used by SPAs to determine the login flow.
     */
    public function authConfig(Request $request)
    {
        $tenant = $this->tenantResolver->current();

        // Default to password auth if no tenant context
        if (!$tenant) {
            return response()->json([
                'auth_method' => 'password',
                'sso_enabled' => false,
            ]);
        }

        $authMethod = $tenant->getAuthMethod();
        $ssoConfigured = $tenant->hasSSOConfigured();

        return response()->json([
            'auth_method' => $authMethod,
            'sso_enabled' => $authMethod === 'sso' && $ssoConfigured,
            'sso_provider' => $ssoConfigured ? $tenant->getSSOProvider() : null,
            'sso_redirect_url' => ($authMethod === 'sso' && $ssoConfigured)
                ? route('sso.redirect')
                : null,
        ]);
    }

    /**
     * Redirect to the tenant's SSO provider.
     *
     * GET /sso/redirect
     *
     * Optional parameters:
     * - redirect_uri: URL to redirect to after SSO (for SPAs)
     * - email: Login hint for the identity provider
     */
    public function redirect(Request $request)
    {
        $tenant = $this->tenantResolver->current();

        // Ensure we have a tenant context
        if (!$tenant) {
            return $this->handleError($request, 'No tenant context. Please access via your organization URL.');
        }

        // Ensure tenant requires SSO
        if (!$tenant->requiresSSO()) {
            return redirect()->route('login');
        }

        // Ensure SSO is properly configured
        if (!$tenant->hasSSOConfigured()) {
            return $this->handleError($request, 'SSO is not configured for this organization. Please contact your administrator.');
        }

        // Store redirect_uri in session for SPA flow
        if ($request->redirect_uri) {
            // Validate redirect_uri against tenant's domains
            if ($this->isValidRedirectUri($tenant, $request->redirect_uri)) {
                session(['sso_redirect_uri' => $request->redirect_uri]);
            }
        }

        try {
            $driver = $this->ssoManager->buildSocialiteDriver($tenant);

            // Add login hint if email is provided
            $params = [];
            if ($request->email) {
                $params['login_hint'] = $request->email;
            }

            return $driver->with($params)->redirect();
        } catch (Exception $e) {
            Log::error('Tenant SSO redirect error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleError($request, 'Unable to connect to identity provider. Please try again later.');
        }
    }

    /**
     * Validate that the redirect_uri belongs to the tenant's domains.
     */
    protected function isValidRedirectUri($tenant, string $redirectUri): bool
    {
        $parsedUrl = parse_url($redirectUri);
        if (!isset($parsedUrl['host'])) {
            return false;
        }

        $host = $parsedUrl['host'];

        // Check against tenant domains
        if (method_exists($tenant, 'domains')) {
            foreach ($tenant->domains as $domain) {
                if ($domain->domain === $host || str_ends_with($host, '.' . $domain->domain)) {
                    return true;
                }
            }
        }

        // Allow same origin as current request
        $currentHost = request()->getHost();
        if ($host === $currentHost) {
            return true;
        }

        return false;
    }

    /**
     * Handle the callback from the SSO provider.
     *
     * GET /sso/callback
     */
    public function callback(Request $request, GeoIP $geoIP)
    {
        $tenant = $this->tenantResolver->current();

        // Ensure we have a tenant context
        if (!$tenant) {
            return $this->handleError($request, 'No tenant context. Please access via your organization URL.');
        }

        // Check for OAuth error response
        if ($request->error) {
            Log::warning('Tenant SSO callback error', [
                'tenant_id' => $tenant->id,
                'error' => $request->error,
                'error_description' => $request->error_description,
            ]);

            return $this->handleError($request, $request->error_description ?? 'Authentication was cancelled or failed.');
        }

        // Check for authorization code
        if (!$request->code) {
            return redirect()->route('login');
        }

        try {
            // Get the authenticated user from SSO provider
            $ssoUser = $this->ssoManager->handleCallback($tenant);

            // Find or create the user (global identity)
            $user = $this->ssoManager->findOrCreateUser($tenant, $ssoUser);

            // Ensure user has membership in this tenant
            $this->ssoManager->ensureMembership($user, $tenant);

            // Check if we need to redirect to a SPA (redirect_uri was stored)
            $redirectUri = session()->pull('sso_redirect_uri');

            if ($redirectUri) {
                // SPA flow: create a token and redirect with it
                $token = $this->createTokenForUser($request, $geoIP, $user);

                // Build redirect URL with token
                $separator = str_contains($redirectUri, '?') ? '&' : '?';
                return redirect($redirectUri . $separator . 'token=' . urlencode($token));
            }

            // Web flow: use session-based authentication
            $this->authService->login($request, $geoIP, $user, LoginAttempt::SSO);

            return redirect()->intended(config('neev.dashboard_url'));
        } catch (Exception $e) {
            Log::error('Tenant SSO callback error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            return $this->handleError($request, $e->getMessage());
        }
    }

    /**
     * Create an API token for the user (used for SPA flow).
     */
    protected function createTokenForUser(Request $request, GeoIP $geoIP, $user): string
    {
        $clientDetails = LoginAttempt::getClientDetails($request);

        $attempt = $user->loginAttempts()->create([
            'method' => LoginAttempt::SSO,
            'location' => $geoIP?->getLocation($request->ip()),
            'platform' => $clientDetails['platform'] ?? '',
            'browser' => $clientDetails['browser'] ?? '',
            'device' => $clientDetails['device'] ?? '',
            'ip_address' => $request->ip(),
            'is_success' => true,
        ]);

        $token = $user->createLoginToken(1440); // 24 hours
        $accessToken = $token->accessToken;
        $accessToken->attempt_id = $attempt->id;
        $accessToken->save();

        return $token->plainTextToken;
    }

    /**
     * Handle SSO errors by redirecting with an error message.
     */
    protected function handleError(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'error',
                'message' => $message,
            ], 400);
        }

        return redirect()->route('login')
            ->withErrors(['sso' => $message]);
    }
}
