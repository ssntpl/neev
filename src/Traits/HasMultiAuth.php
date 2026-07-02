<?php

namespace Ssntpl\Neev\Traits;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Events\MfaMethodAdded;
use Ssntpl\Neev\Events\MfaMethodRemoved;
use Ssntpl\Neev\Events\RecoveryCodesGenerated;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\RecoveryCode;

trait HasMultiAuth
{
    public function multiFactorAuths()
    {
        return $this->hasMany(MultiFactorAuth::class);
    }

    public function activeMultiFactorAuths()
    {
        return $this->hasMany(MultiFactorAuth::class)->where('status', MultiFactorAuth::STATUS_ACTIVE);
    }

    public function multiFactorAuth($method)
    {
        return $this->multiFactorAuths->where('method', $method)->first();
    }

    public function preferredMultiFactorAuth()
    {
        return $this->hasOne(MultiFactorAuth::class)
            ->where('preferred', true)
            ->where('status', MultiFactorAuth::STATUS_ACTIVE);
    }

    public function recoveryCodes()
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function addMultiFactorAuth($method)
    {
        switch ($method) {
            case 'authenticator':
                $auth = $this->multiFactorAuth($method);
                $secret = $auth?->secret ?? Base32::encodeUpper(random_bytes(32));
                $totp = TOTP::create($secret);
                $totp->setLabel($this->email);
                $totp->setIssuer(config('app.name', 'Neev'));
                if (!$auth) {
                    // Created pending: the method only becomes active (and
                    // enforced at login) once the user proves they scanned
                    // the QR code via verifyMfaSetup(). MfaMethodAdded fires
                    // on activation, not here.
                    $this->multiFactorAuths()->create([
                        'method' => $method,
                        'status' => MultiFactorAuth::STATUS_PENDING,
                        'preferred' => false,
                        'secret' => $totp->getSecret(),
                    ]);
                    $this->load('multiFactorAuths');
                }

                $renderer = new ImageRenderer(
                    new RendererStyle(200),
                    new SvgImageBackEnd()
                );
                $writer = new Writer($renderer);
                $qrCodeSvg = $writer->writeString($totp->getProvisioningUri());

                return [
                    'status' => 'Success',
                    'qr_code' => $qrCodeSvg,
                    'secret' => $totp->getSecret(),
                    'method' => $method,
                ];

            case 'email':
                $auth = $this->multiFactorAuth($method);
                if ($auth) {
                    return [
                        'status' => 'Error',
                        'method' => $method,
                        'message' => 'Email already Configured.'
                    ];
                }

                // The account email is already verified, so email OTP is
                // active immediately.
                $this->multiFactorAuths()->create([
                    'method' => $method,
                    'status' => MultiFactorAuth::STATUS_ACTIVE,
                    'preferred' => !$this->preferredMultiFactorAuth?->preferred,
                ]);
                $this->load('multiFactorAuths');

                event(new MfaMethodAdded($this, $method));

                return [
                    'status' => 'Success',
                    'method' => $method,
                    'message' => 'Email Configured.'
                ];

            default:
                return null;
        }
    }

    /**
     * Complete a pending MFA setup by verifying an OTP against it.
     * On success the method becomes active, is made preferred when no
     * other active method holds the flag, and MfaMethodAdded fires.
     *
     * @return bool False if there is no pending setup for the method or
     *              the code is wrong.
     */
    public function verifyMfaSetup(string $method, string $otp): bool
    {
        $auth = $this->multiFactorAuth($method);
        if (!$auth || $auth->isActive()) {
            return false;
        }

        if ($method === 'authenticator') {
            $totp = TOTP::create(secret: $auth->secret);
            if (!$totp->verify(otp: (string) $otp, timestamp: null, leeway: 29)) {
                return false;
            }
        } else {
            return false;
        }

        $auth->last_used = now();
        $auth->activate();
        $this->load('multiFactorAuths');

        return true;
    }

    /**
     * Remove an MFA method, reassigning the preferred flag to another
     * active method and deleting recovery codes when this was the last
     * active method. Removing a pending setup is a silent cancellation:
     * MfaMethodRemoved only fires for methods that were active.
     *
     * @return bool False if the method was not configured.
     */
    public function removeMultiFactorAuth(string $method): bool
    {
        $auth = $this->multiFactorAuth($method);
        if (!$auth) {
            return false;
        }

        $wasActive = $auth->isActive();

        if ($auth->preferred) {
            $next = $this->multiFactorAuths()->active()->whereNot('method', $auth->method)->first();
            if ($next) {
                $next->preferred = true;
                $next->save();
            }
        }
        $auth->delete();
        $this->load('multiFactorAuths');

        if ($this->activeMultiFactorAuths()->count() < 1) {
            $this->recoveryCodes()->delete();
        }

        if ($wasActive) {
            event(new MfaMethodRemoved($this, $method));
        }

        return true;
    }

    public function verifyMFAOTP($method, $otp): bool
    {
        $auth = $this->multiFactorAuth($method ?? $this->preferredMultiFactorAuth?->method);

        // Pending setups cannot satisfy an MFA challenge — they are not
        // an enrolled factor until verifyMfaSetup() activates them.
        if ($auth && !$auth->isActive()) {
            $auth = null;
        }

        if (!$auth && $method !== 'recovery') {
            return false;
        }

        switch ($auth?->method ?? $method) {
            case 'authenticator':
                $totp = TOTP::create(secret: $auth->secret);
                if ($totp->verify(otp: $otp, timestamp: null, leeway: 29)) {
                    $auth->last_used = now();
                    $auth->save();
                    return true;
                }
                return false;

            case 'email':
                if (Hash::check((string) $otp, $auth->otp) && now()->lt($auth->expires_at)) {
                    $auth->otp = null;
                    $auth->expires_at = null;
                    $auth->last_used = now();
                    $auth->save();
                    return true;
                }
                break;

            case 'recovery':
                $code = $this->recoveryCodes->first(function ($recoveryCode) use ($otp) {
                    return Hash::check($otp, $recoveryCode->code);
                });
                if ($code) {
                    $code->delete();
                    return true;
                }
                break;
        }
        return false;
    }

    public function generateRecoveryCodes()
    {
        $this->recoveryCodes()->delete();
        $codes = [];
        for ($i = 1; $i <= config('neev.recovery_codes'); $i++) {
            $code = Str::lower(Str::random(10));
            $this->recoveryCodes()->create([
                'code' => $code,
            ]);
            $codes[] = $code;
        }

        event(new RecoveryCodesGenerated($this));

        return $codes;
    }
}
