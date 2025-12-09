<?php

namespace Ssntpl\Neev\Traits;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use OTPHP\TOTP;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\RecoveryCode;
use Str;

trait HasMultiAuth
{
    public function multiFactorAuths()
    {
        return $this->hasMany(MultiFactorAuth::class);
    }

    public function multiFactorAuth($method)
    {
        return $this->multiFactorAuths?->where('method', $method)->first();
    }

    public function preferredMultiFactorAuth()
    {
        return $this->hasOne(MultiFactorAuth::class)->where('preferred', true);
    }

    public function recoveryCodes()
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function addMultiFactorAuth($method) {
        switch ($method) {
            case 'authenticator':
                $auth = $this->multiFactorAuth($method);
                $secret = $auth?->secret ?? Base32::encodeUpper(random_bytes(32));
                $totp = TOTP::create($secret);
                $totp->setLabel($this->email?->email);
                $totp->setIssuer(config('app.name', 'Neev')); 
                if (!$auth) {
                    $this->multiFactorAuths()->create([
                        'method' => $method,
                        'preferred' => !$this->preferredMultiFactorAuth?->preferred,
                        'secret' => $totp->getSecret(),
                    ]);
                }

                $renderer = new ImageRenderer(
                    new RendererStyle(200),
                    new SvgImageBackEnd()
                );
                $writer = new Writer($renderer);
                $qrCodeSvg = $writer->writeString($totp->getProvisioningUri());

                if (count($this->recoveryCodes) == 0) {
                    $this->generateRecoveryCodes();
                }

                return [
                    'qr_code' => $qrCodeSvg,
                    'secret' => $totp->getSecret(),
                    'method' => $method,
                ];
                
            case 'email':
                $auth = $this->multiFactorAuth($method);
                if ($auth) {
                    return ['message' => 'Email already Configured.'];
                }
                
                $this->multiFactorAuths()->create([
                    'method' => $method,
                    'preferred' => !$this->preferredMultiFactorAuth?->preferred,
                ]);

                if (count($this->recoveryCodes) == 0) {
                    $this->generateRecoveryCodes();
                }
                return ['message' => 'Email Configured.'];
                
            default:
                return null;
        }
    }

    public function verifyMFAOTP($method, $otp): bool {
        $auth = $this->multiFactorAuth($method ?? $this->preferredMultiFactorAuth?->method);
        if (!$auth && $method !== 'recovery') {
            return false;
        }

        switch ($auth?->method ?? $method) {
            case 'authenticator':
                $totp = TOTP::create(secret: $auth->secret);
                $auth->last_used = now();
                $auth->save();
                return $totp->verify(otp: $otp, timestamp: null, leeway: 29);

            case 'email':
                $otpStored = (string) $auth->otp;
                $otpGiven  = (string) $otp;
                if (hash_equals($otpStored, $otpGiven) && now()->lt($auth->expires_at)) {
                    $auth->otp = null;
                    $auth->expires_at = null;
                    $auth->last_used = now();
                    $auth->save();
                    return true;
                }
                break;
            
            case 'recovery':
                $code = $this->recoveryCodes?->first(function ($recoveryCode) use ($otp) {
                    return $recoveryCode->code === $otp;
                });
                if ($code) {
                    $code->code = Str::lower(Str::random(10));
                    $code->save();
                    return true;
                }
                break;
        }
        return false;
    }

    public function generateRecoveryCodes() {
        $this->recoveryCodes()->delete();
        for ($i = 1; $i <= config('neev.recovery_codes'); $i++) {
            $this->recoveryCodes()->create([
                'code' => Str::lower(Str::random(10)),
            ]);
        }
    }
}