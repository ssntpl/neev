<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\LoginAttempt;
use Ssntpl\Neev\Models\Passkey;
use Ssntpl\Neev\Models\User;
use Illuminate\Http\Request;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Ssntpl\Neev\Services\AuthService;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorDataLoader;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CheckAlgorithm;
use Webauthn\CeremonyStep\CheckAllowedOrigins;
use Webauthn\CeremonyStep\CheckAttestationFormatIsKnownAndValid;
use Webauthn\CeremonyStep\CheckChallenge;
use Webauthn\CeremonyStep\CheckCredentialId;
use Webauthn\CeremonyStep\CheckHasAttestedCredentialData;
use Webauthn\CeremonyStep\CheckSignature;
use Webauthn\CeremonyStep\CheckUserVerification;
use Webauthn\CeremonyStep\CheckUserWasPresent;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Services\RelyingPartyResolver;
use Ssntpl\Neev\Services\SpaCookieResponder;
use Ssntpl\Neev\Http\Controllers\Controller;

class PasskeyController extends Controller
{
    /**
     * How long the browser may spend on a ceremony. Long enough for the
     * hybrid (QR) transport, where the user walks the credential over to a
     * phone.
     */
    protected const CEREMONY_TIMEOUT_MS = 300000;

    /**
     * How long the server holds the challenge. Deliberately longer than the
     * ceremony itself: an authenticator that answers on the last second of
     * the timeout must still find its challenge waiting, or the user is told
     * to try again after they have already touched their key.
     */
    protected const CHALLENGE_TTL_SECONDS = self::CEREMONY_TIMEOUT_MS / 1000 + 60;

    public function __construct(
        protected AuthService $auth,
        protected RelyingPartyResolver $relyingParty,
    ) {
    }

    public function getPasskeys(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $passkeys = $user->passkeys()->get();

        return response()->json([
            'data' => $passkeys
        ]);
    }

    public function generateRegistrationOptions(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }
        $rpId = $this->relyingParty->rpId();
        $userId = strval($user->id);

        $challenge = random_bytes(32);
        $base64Challenge = Base64UrlSafe::encode($challenge);

        // Bound to the relying party so both halves of a ceremony run under
        // the same context.
        Cache::put("passkey_reg_challenge:{$user->id}", ['challenge' => $base64Challenge, 'rp_id' => $rpId], self::CHALLENGE_TTL_SECONDS);

        $authenticatorSelection = new AuthenticatorSelectionCriteria(
            residentKey: 'required',
            userVerification: 'required'
        );

        $pubKeyCredParams = [
            new PublicKeyCredentialParameters('public-key', -7),    // ES256
            new PublicKeyCredentialParameters('public-key', -257),  // RS256
        ];

        return response()->json([
            'rp' => [
                'name' => $this->relyingParty->rpName(),
                'id'   => $rpId,
            ],
            'user' => [
                'id' => base64_encode($userId),
                'name' => $user->email,
                'displayName' => $user->name,
            ],
            'challenge' => $base64Challenge,
            'pubKeyCredParams' => $pubKeyCredParams,
            'authenticatorSelection' => [
                'residentKey' => $authenticatorSelection->residentKey,
                'userVerification' => $authenticatorSelection->userVerification,
            ],
            'timeout' => self::CEREMONY_TIMEOUT_MS,
            'excludeCredentials' => array_map(
                fn (PublicKeyCredentialDescriptor $descriptor) => ['type' => $descriptor->type, 'id' => $descriptor->id],
                $this->enrolledCredentials($user, $rpId)
            ),
            'attestation' => 'none',
            'extensions' => (object) [],
        ]);
    }

    public function register(Request $request, GeoIP $geoIP)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }
        $input = json_decode($request->attestation, true);

        $rpId = $this->relyingParty->rpId();

        // Retrieve challenge from server-side storage (not from client)
        $challenge = $this->pullChallenge("passkey_reg_challenge:{$user->id}", $rpId);

        $clientDataJson = $input['response']['clientDataJSON'];
        $attestationObjectRaw = $input['response']['attestationObject'];
        $rawId = $input['rawId'];
        $type = $input['type'];

        $collectedClientData = CollectedClientData::createFormJson($clientDataJson);

        $attStmtSupportManager = new AttestationStatementSupportManager();
        $attStmtSupportManager->add(new NoneAttestationStatementSupport());
        $attestationLoader = new AttestationObjectLoader($attStmtSupportManager);
        $attestationObject = $attestationLoader->load($attestationObjectRaw);

        $response = new AuthenticatorAttestationResponse(
            $collectedClientData,
            $attestationObject,
            $input['response']['transports'] ?? []
        );
        try {
            $credential = new PublicKeyCredential($type, $rawId, $response);
        } catch (Throwable $e) {
            Log::error($e);
            throw $e;
        }

        // Rebuild PublicKeyCredentialCreationOptions
        $rp = new PublicKeyCredentialRpEntity(
            name: $this->relyingParty->rpName(),
            id: $rpId
        );

        $userEntity = new PublicKeyCredentialUserEntity(
            id: strval($user->id),
            name: $user->email,
            displayName: $user->name
        );

        $pubKeyCredParams = [
            new PublicKeyCredentialParameters('public-key', -7),
            new PublicKeyCredentialParameters('public-key', -257),
        ];

        $options = new PublicKeyCredentialCreationOptions(
            rp: $rp,
            user: $userEntity,
            challenge: $challenge,
            excludeCredentials: $this->enrolledCredentials($user, $rpId),
            pubKeyCredParams: $pubKeyCredParams,
            timeout: self::CEREMONY_TIMEOUT_MS,
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                residentKey: 'required',
                userVerification: 'required'
            ),
            attestation: 'none'
        );

        $attestationSupportManager = new AttestationStatementSupportManager();
        try {
            $ceremonySteps = new CeremonyStepManager([
                new CheckChallenge(),
                new CheckAllowedOrigins(
                    $this->relyingParty->allowedOrigins(),
                    $this->relyingParty->allowSubdomains()
                ),
                new CheckAlgorithm(),
                new CheckSignature(),
                new CheckCredentialId(),
                new CheckUserWasPresent(),
                new CheckUserVerification(),
                new CheckHasAttestedCredentialData(),
                new CheckAttestationFormatIsKnownAndValid($attestationSupportManager),
            ]);

            $validator = new AuthenticatorAttestationResponseValidator($ceremonySteps);

            $response = $credential->response;

            $credentialSource = $validator->check(
                $response,
                $options,
                $rp->id
            );
        } catch (Throwable $e) {
            Log::error($e);
            throw $e;
        }

        $credentialId = Base64UrlSafe::encode($credentialSource->publicKeyCredentialId);

        // Match on credential ID, not AAGUID: an AAGUID identifies a
        // make/model, so two keys of the same model would overwrite each other.
        $passkey = $user->passkeys()
            ->where('credential_id', $credentialId)
            ->forRelyingParty($rpId)
            ->first();

        if ($passkey) {
            $passkey->name = $request->input('name', 'Default Device') ?? 'Default Device';
            $passkey->credential_id = $credentialId;
            $passkey->rp_id = $rpId;
            $passkey->public_key = Base64UrlSafe::encode($credentialSource->credentialPublicKey);
            $passkey->transports = $input['response']['transports'] ?? [];
            $passkey->ip = $request->ip();
            $passkey->location = $geoIP->getLocation($request->ip());
            $passkey->save();
        } else {
            $passkey = $user->passkeys()->create([
                'credential_id' => $credentialId,
                'rp_id' => $rpId,
                'public_key' => Base64UrlSafe::encode($credentialSource->credentialPublicKey),
                'name' => $request->input('name', 'Default Device') ?? 'Default Device',
                'aaguid' => $credentialSource->aaguid->toRfc4122(),
                'transports' => $input['response']['transports'] ?? [],
                'ip' => $request->ip(),
                'location' => $geoIP->getLocation($request->ip()),
            ]);
        }

        return $passkey;
    }

    public function deletePasskey(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return back()->withErrors([
                'message' => 'Passkey was not deleted.'
            ]);
        }
        $passkey = Passkey::find($request->passkey_id);
        if (!$passkey || $passkey->user_id != $user->id) {
            return back()->withErrors([
                'message' => 'Passkey was not deleted.'
            ]);
        }
        $passkey->delete();
        return back()->with('status', 'Passkey has been deleted.');
    }

    public function updatePasskeyName(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'message' => 'User not found',
            ], 400);
        }
        $passkey = Passkey::find($request->passkey_id);
        if (!$passkey || $passkey->user_id != $user->id) {
            return response()->json([
                'message' => 'Passkey not found'
            ], 400);
        }
        $passkey->name = $request->name;
        $passkey->save();
        return response()->json([
            'message' => 'Passkey name has been updated.',
            'data' => $passkey
        ]);
    }

    public function generateLoginOptions(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);
            $user = User::findByEmail($request->email);
            if (!$user) {
                throw new Exception('User not found.');
            }
            $rpId = $this->relyingParty->rpId();
            $allowCredentials = $this->enrolledCredentials($user, $rpId);

            if (empty($allowCredentials)) {
                throw new Exception('User not found.');
            }

            $challenge = random_bytes(32);
            $base64Challenge = Base64UrlSafe::encode($challenge);

            // Bound to the relying party it was issued for.
            $cacheKey = 'passkey_login_challenge:' . hash('sha256', $request->email);
            Cache::put($cacheKey, ['challenge' => $base64Challenge, 'rp_id' => $rpId], self::CHALLENGE_TTL_SECONDS);

            $options = new PublicKeyCredentialRequestOptions(
                challenge: $challenge,
                rpId: $rpId,
                allowCredentials: $allowCredentials,
                userVerification: 'required',
                timeout: self::CEREMONY_TIMEOUT_MS,
                extensions: []
            );

            return response()->json([
                'challenge' => $base64Challenge,
                'timeout' => $options->timeout,
                'rpId' => $options->rpId,
                'allowCredentials' => $options->allowCredentials,
                'userVerification' => $options->userVerification,
                'extensions' => [],
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to generate login options.'
            ], 400);
        }
    }

    public function passkeyLogin(Request $request, GeoIP $geoIP)
    {
        $input = json_decode($request->assertion, true);
        $rawId = $input['rawId'];
        $authData = $input['response']['authenticatorData'];
        $signature = Base64UrlSafe::decode($input['response']['signature']);

        $authenticatorLoader = AuthenticatorDataLoader::create();
        $authenticatorData = $authenticatorLoader->load(
            Base64UrlSafe::decode($authData)
        );

        $response = new AuthenticatorAssertionResponse(
            CollectedClientData::createFormJson($input['response']['clientDataJSON']),
            $authenticatorData,
            $signature,
            $input['response']['userHandle'] ?? null
        );

        $user = User::findByEmail($request->email);
        if (!$user) {
            throw new Exception('Wrong Credentials.');
        }

        $rpId = $this->relyingParty->rpId();

        // Retrieve challenge from server-side storage (not from client)
        $challenge = $this->pullChallenge('passkey_login_challenge:' . hash('sha256', $request->email), $rpId);

        $options = new PublicKeyCredentialRequestOptions(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: [],
            userVerification: 'required',
            timeout: self::CEREMONY_TIMEOUT_MS
        );

        $attempt = null;
        if (config('neev.log_failed_logins')) {
            $clientDetails = LoginAttempt::getClientDetails($request);
            $attempt = $user->loginAttempts()->create([
                'method' => LoginAttempt::Passkey,
                'location' => $geoIP->getLocation($request->ip()),
                'multi_factor_method' => null,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => false,
            ]);
        }
        $passkey = $user->passkeys->where('credential_id', Base64UrlSafe::encode(Base64UrlSafe::decode($rawId)))->first();

        if (!$passkey
            || !$passkey->matchesRelyingParty($rpId)
            || $user->id != (int) base64_decode($input['response']['userHandle'])) {
            throw new Exception('Wrong Credentials.');
        }

        $data = Base64UrlSafe::decode($passkey->public_key);
        $credentialSource = new PublicKeyCredentialSource(
            publicKeyCredentialId: Base64UrlSafe::decode($passkey->credential_id),
            type: 'public-key',
            transports: $passkey->transports ?? [],
            attestationType: 'none',
            trustPath: new EmptyTrustPath(),
            aaguid: new Uuid($passkey->aaguid),
            credentialPublicKey: $data,
            userHandle: $input['response']['userHandle'],
            counter: $data['counter'] ?? 0
        );

        $validator = new AuthenticatorAssertionResponseValidator(
            new CeremonyStepManager([
                new CheckChallenge(),
                new CheckAllowedOrigins(
                    $this->relyingParty->allowedOrigins(),
                    $this->relyingParty->allowSubdomains()
                ),
                new CheckAlgorithm(),
                new CheckSignature(),
                new CheckCredentialId(),
                new CheckUserWasPresent(),
                new CheckUserVerification(),
            ])
        );

        $validator->check(
            $credentialSource,
            $response,
            $options,
            $rpId,
            $input['response']['userHandle'] ?? null
        );

        $passkey->last_used = now();
        $passkey->save();

        return [$user, $attempt ?? null];
    }

    /**
     * Take the stored challenge, refusing one issued for another relying
     * party — options and completion are separate requests whose context can
     * differ.
     *
     * A ceremony that began before this version was deployed stored the
     * challenge as a bare string, with no relying party alongside it. Those
     * were all issued under the configured relying party, so read them as
     * such rather than failing every ceremony in flight across the deploy.
     *
     * @return string  The raw challenge bytes.
     */
    protected function pullChallenge(string $cacheKey, string $rpId): string
    {
        $stored = Cache::pull($cacheKey);

        if (is_string($stored) && $stored !== '') {
            $stored = ['challenge' => $stored, 'rp_id' => $this->relyingParty->configured()];
        }

        if (!is_array($stored) || empty($stored['challenge'])) {
            throw new Exception('Challenge expired or not found. Please try again.');
        }

        if (($stored['rp_id'] ?? null) !== $rpId) {
            throw new Exception('Challenge was issued for a different relying party.');
        }

        return Base64UrlSafe::decode($stored['challenge']);
    }

    /**
     * The user's credentials on this relying party — `allowCredentials` on
     * login, `excludeCredentials` on registration.
     *
     * @return array<int, PublicKeyCredentialDescriptor>
     */
    protected function enrolledCredentials(User $user, string $rpId): array
    {
        return $user->passkeys()
            ->forRelyingParty($rpId)
            ->get()
            ->map(fn (Passkey $passkey) => new PublicKeyCredentialDescriptor(
                type: 'public-key',
                id: $passkey->credential_id,
            ))
            ->all();
    }

    public function registerViaWeb(Request $request, GeoIP $geoIP)
    {
        try {
            $this->register($request, $geoIP);
            return back()->with('status', 'Passkey has been registered.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Unable to register passkey.']);
        }
    }

    public function registerViaAPI(Request $request, GeoIP $geoIP)
    {
        try {
            $res = $this->register($request, $geoIP);
            return response()->json([
                'message' => 'Passkey has been registered.',
                'data' => $res,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to register passkey.',
            ], 400);
        }
    }

    public function deletePasskeyViaAPI(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $passkey = Passkey::find($request->passkey_id);
        if (!$user || !$passkey || $passkey->user_id != $user->id) {
            return response()->json([
                'message' => 'Passkey was not deleted.',
            ], 400);
        }
        $passkey->delete();

        return response()->json([
            'message' => 'Passkey has been deleted.',
        ]);
    }

    public function loginViaWeb(Request $request, GeoIP $geoIP)
    {
        try {
            [$user, $attempt] = $this->passkeyLogin($request, $geoIP);

            $this->auth->login($request, $geoIP, $user, LoginAttempt::Passkey, attempt: $attempt);
            return redirect($this->auth->intendedUrl($request->redirect));
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => 'Unable to authenticate with passkey.']);
        }
    }

    public function loginViaAPI(Request $request, GeoIP $geoIP)
    {
        try {
            [$user, $attempt] = $this->passkeyLogin($request, $geoIP);

            $expiryMinutes = config('neev.login_token_expiry_minutes', 1440);
            $token = app(AuthService::class)->createApiToken($request, $geoIP, $user, LoginAttempt::Passkey, $expiryMinutes, $attempt);

            return app(SpaCookieResponder::class)->attach($request, response()->json([
                'auth_state' => 'authenticated',
                'token' => $token,
                'expires_in' => $expiryMinutes,
                'mfa_options' => null,
                'email_verified' => $user->hasVerifiedEmail(),
            ]), $expiryMinutes);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'message' => 'Unable to authenticate with passkey.',
            ], 400);
        }
    }
}
