<?php

namespace Ssntpl\Neev\Http\Controllers\Auth;

use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\Email;
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
use Webauthn\CeremonyStep\CheckAttestationFormatIsKnownAndValid;
use Webauthn\CeremonyStep\CheckChallenge;
use Webauthn\CeremonyStep\CheckCredentialId;
use Webauthn\CeremonyStep\CheckHasAttestedCredentialData;
use Webauthn\CeremonyStep\CheckOrigin;
use Webauthn\CeremonyStep\CheckSignature;
use Webauthn\CeremonyStep\CheckUserVerification;
use Webauthn\CeremonyStep\CheckUserWasPresent;
use Webauthn\CollectedClientData;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\TrustPath\EmptyTrustPath;
use Ssntpl\Neev\Services\GeoIP;
use Ssntpl\Neev\Http\Controllers\Controller;

class PasskeyController extends Controller
{
    public function __construct(
        protected AuthService $auth,
    ) {
    }

    public function generateRegistrationOptions(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found',
            ], 400);
        }
        $rpName = config('app.name', 'Neev');
        $rpId = parse_url(config('neev.frontend_url', config('app.url')), PHP_URL_HOST);

        $userId = strval($user->id);

        $challenge = random_bytes(32);
        $base64Challenge = Base64UrlSafe::encode($challenge);

        // Store challenge server-side for verification
        Cache::put("passkey_reg_challenge:{$user->id}", $base64Challenge, 300);

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
                'name' => $rpName,
                'id'   => $rpId,
            ],
            'user' => [
                'id' => base64_encode($userId),
                'name' => $user->email?->email,
                'displayName' => $user->name,
            ],
            'challenge' => $base64Challenge,
            'pubKeyCredParams' => $pubKeyCredParams,
            'authenticatorSelection' => [
                'residentKey' => $authenticatorSelection?->residentKey,
                'userVerification' => $authenticatorSelection?->userVerification,
            ],
            'timeout' => 60000,
            'excludeCredentials' => [],
            'attestation' => 'none',
            'extensions' => (object) [],
        ]);
    }

    public function register(Request $request, GeoIP $geoIP)
    {
        $user = User::model()->find($request->user()?->id);
        if (!$user) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'User not found',
            ], 400);
        }
        $input = json_decode($request->attestation, true);

        $rpId = parse_url(config('neev.frontend_url', config('app.url')), PHP_URL_HOST);

        // Retrieve challenge from server-side storage (not from client)
        $storedChallenge = Cache::pull("passkey_reg_challenge:{$user->id}");
        if (!$storedChallenge) {
            throw new Exception('Challenge expired or not found. Please try again.');
        }
        $challenge = Base64UrlSafe::decode($storedChallenge);

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
            name: config('app.name', 'Neev'),
            id: $rpId
        );

        $userEntity = new PublicKeyCredentialUserEntity(
            id: strval($user->id),
            name: $user->email?->email,
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
            excludeCredentials: [],
            pubKeyCredParams: $pubKeyCredParams,
            timeout: 60000,
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
                new CheckOrigin([$rpId]),
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

        $passkey = $user->passkeys->where('aaguid', $credentialSource->aaguid->toRfc4122())->first();
        if ($passkey) {
            $passkey->name = $request->input('name', 'Default Device') ?? 'Default Device';
            $passkey->credential_id = Base64UrlSafe::encode($credentialSource->publicKeyCredentialId);
            $passkey->public_key = Base64UrlSafe::encode($credentialSource->credentialPublicKey);
            $passkey->transports = $input['response']['transports'] ?? [];
            $passkey->ip = $request->ip();
            $passkey->location = $geoIP?->getLocation($request->ip());
            $passkey->save();
        } else {
            $passkey = $user->passkeys()->create([
                'credential_id' => Base64UrlSafe::encode($credentialSource->publicKeyCredentialId),
                'public_key' => Base64UrlSafe::encode($credentialSource->credentialPublicKey),
                'name' => $request->input('name', 'Default Device') ?? 'Default Device',
                'aaguid' => $credentialSource->aaguid->toRfc4122(),
                'transports' => $input['response']['transports'] ?? [],
                'ip' => $request->ip(),
                'location' => $geoIP?->getLocation($request->ip()),
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
        if (!$passkey || $passkey?->user_id != $user->id) {
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
                'status' => 'Failed',
                'message' => 'User not found',
            ], 400);
        }
        $passkey = Passkey::find($request->passkey_id);
        if (!$passkey || $passkey?->user_id != $user?->id) {
            return response()->json([
                'status' => 'Failed'
            ], 400);
        }
        $passkey->name = $request->name;
        $passkey->save();
        return response()->json([
            'status' => 'Success',
            'message' => 'Passkey name has been updated.',
            'data' => $passkey
        ]);
    }

    public function generateLoginOptions(Request $request)
    {
        try {
            $request->validate(['email' => 'required|email']);
            $email = Email::where('email', $request->email)->first();
            $user = $email?->user;
            if (!$email || !$user) {
                throw new Exception('User not found.');
            }
            $allowCredentials = [];

            foreach ($user->passkeys as $passkey) {
                $allowCredentials[] = [
                    'type' => 'public-key',
                    'id' => $passkey->credential_id,
                ];
            }

            $rpId = parse_url(config('neev.frontend_url', config('app.url')), PHP_URL_HOST);
            $challenge = random_bytes(32);
            $base64Challenge = Base64UrlSafe::encode($challenge);

            // Store challenge server-side for verification
            $cacheKey = 'passkey_login_challenge:' . hash('sha256', $request->email);
            Cache::put($cacheKey, $base64Challenge, 300);

            $options = new PublicKeyCredentialRequestOptions(
                challenge: $challenge,
                rpId: $rpId,
                allowCredentials: $allowCredentials,
                userVerification: 'required',
                timeout: 120000,
                extensions: []
            );

            return response()->json([
                'status' => 'Success',
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
                'status' => 'Failed',
                'message' => 'Unable to generate login options.'
            ]);
        }
    }

    public function passkeyLogin(Request $request, GeoIP $geoIP)
    {
        $input = json_decode($request->assertion, true);
        $rawId = $input['rawId'];
        $type = $input['type'];
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

        $credential = new PublicKeyCredential($type, $rawId, $response);

        $rpId = parse_url(config('neev.frontend_url', config('app.url')), PHP_URL_HOST);

        // Retrieve challenge from server-side storage (not from client)
        $cacheKey = 'passkey_login_challenge:' . hash('sha256', $request->email);
        $storedChallenge = Cache::pull($cacheKey);
        if (!$storedChallenge) {
            throw new Exception('Challenge expired or not found. Please try again.');
        }
        $challenge = Base64UrlSafe::decode($storedChallenge);

        $options = new PublicKeyCredentialRequestOptions(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: [],
            userVerification: 'required',
            timeout: 120000
        );

        $email = Email::where('email', $request->email)->first();
        $user = $email?->user;
        if (config('neev.record_failed_login_attempts')) {
            $clientDetails = LoginAttempt::getClientDetails($request);
            $attempt = $user?->loginAttempts()->create([
                'method' => LoginAttempt::Passkey,
                'location' => $geoIP?->getLocation($request->ip()),
                'multi_factor_method' => null,
                'platform' => $clientDetails['platform'] ?? '',
                'browser' => $clientDetails['browser'] ?? '',
                'device' => $clientDetails['device'] ?? '',
                'ip_address' => $request->ip(),
                'is_success' => false,
            ]);
        }
        $passkey = $user?->passkeys?->where('credential_id', Base64UrlSafe::encode(Base64UrlSafe::decode($rawId)))->first();

        if (!$passkey || !$user || $user?->id != (int) base64_decode($input['response']['userHandle'])) {
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
                new CheckOrigin([$rpId]),
                new CheckAlgorithm(),
                new CheckSignature(),
                new CheckCredentialId(),
                new CheckUserWasPresent(),
                new CheckUserVerification(),
            ])
        );

        $validator->check(
            publicKeyCredentialSource: $credentialSource,
            authenticatorAssertionResponse: $credential->response,
            publicKeyCredentialRequestOptions: $options,
            host: $rpId,
            userHandle: $input['response']['userHandle'] ?? null
        );

        $passkey->last_used = now();
        $passkey->save();

        return [$user, $attempt ?? null];
    }

    public function registerViaWeb(Request $request, GeoIP $geoIP)
    {
        try {
            $this->register($request, $geoIP);
            return back()->with('status', 'Passkey has been registered.');
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => $e->getMessage()]);
        }
    }

    public function registerViaAPI(Request $request, GeoIP $geoIP)
    {
        try {
            $res = $this->register($request, $geoIP);
            return response()->json([
                'status' => 'Success',
                'message' => 'Passkey has been registered.',
                'data' => $res,
            ]);
        } catch (Exception $e) {
            Log::error($e);
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function deletePasskeyViaAPI(Request $request)
    {
        $user = User::model()->find($request->user()?->id);
        $passkey = Passkey::find($request->passkey_id);
        if (!$user || !$passkey || $passkey?->user_id != $user?->id) {
            return response()->json([
                'status' => 'Failed',
                'message' => 'Passkey was not deleted.',
            ]);
        }
        $passkey->delete();

        return response()->json([
            'status' => 'Success',
            'message' => 'Passkey has been deleted.',
        ]);
    }

    public function loginViaWeb(Request $request, GeoIP $geoIP)
    {
        try {
            [$user, $attempt] = $this->passkeyLogin($request, $geoIP);

            $this->auth->login($request, $geoIP, $user, LoginAttempt::Passkey, $attempt ?? null);
            return redirect(config('neev.dashboard_url'));
        } catch (Exception $e) {
            Log::error($e);
            return back()->withErrors(['message' => $e->getMessage()]);
        }
    }

    public function loginViaAPI(Request $request, GeoIP $geoIP)
    {
        try {
            [$user, $attempt] = $this->passkeyLogin($request, $geoIP);

            $authController = new UserAuthApiController();
            $token = $authController->getToken(request: $request, geoIP: $geoIP, user: $user, method: LoginAttempt::Passkey, attempt: $attempt ?? null);

            return response()->json([
                'status' => 'Success',
                'token' => $token,
                'email_verified' => $user?->hasVerifiedEmail($request->email)
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'Failed',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
