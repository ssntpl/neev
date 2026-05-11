<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Support\Str;
use ParagonIE\ConstantTime\Base32;
use Ssntpl\Neev\Models\MultiFactorAuth;
use Ssntpl\Neev\Models\RecoveryCode;

trait HasMultiAuth
{
    public function multiFactorAuths()
    {
        return $this->hasMany(MultiFactorAuth::class)
            ->where('status', MultiFactorAuth::STATUS_ACTIVE);
    }

    public function multiFactorAuth($method)
    {
        return $this->multiFactorAuths->where('method', $method)->first();
    }

    public function pendingMultiFactorAuth($method)
    {
        return MultiFactorAuth::where('user_id', $this->id)
            ->where('method', $method)
            ->where('status', MultiFactorAuth::STATUS_PENDING)
            ->first();
    }

    public function preferredMultiFactorAuth()
    {
        return $this->hasOne(MultiFactorAuth::class)
            ->where('status', MultiFactorAuth::STATUS_ACTIVE)
            ->where('preferred', true);
    }

    public function recoveryCodes()
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function addMultiFactorAuth($method)
    {
        switch ($method) {
            case 'authenticator':
                if ($this->multiFactorAuth('authenticator')) {
                    return [
                        'status' => 'Error',
                        'method' => 'authenticator',
                        'message' => 'Authenticator already configured.',
                    ];
                }

                $secret = Base32::encodeUpper(random_bytes(32));

                MultiFactorAuth::updateOrCreate(
                    ['user_id' => $this->id, 'method' => 'authenticator'],
                    [
                        'secret' => $secret,
                        'status' => MultiFactorAuth::STATUS_PENDING,
                        'preferred' => !$this->preferredMultiFactorAuth?->preferred,
                    ]
                );

                return [
                    'status' => 'Success',
                    'qr_code' => MultiFactorAuth::getQrCodeForAuthenticatorSetup($secret, $this->email),
                    'secret' => $secret,
                    'method' => 'authenticator',
                ];

            case 'email':
                if ($this->multiFactorAuth('email')) {
                    return [
                        'status' => 'Error',
                        'method' => 'email',
                        'message' => 'Email already Configured.',
                    ];
                }

                $this->multiFactorAuths()->create([
                    'method' => 'email',
                    'preferred' => !$this->preferredMultiFactorAuth?->preferred,
                    'status' => MultiFactorAuth::STATUS_ACTIVE,
                ]);

                return [
                    'status' => 'Success',
                    'method' => 'email',
                    'message' => 'Email Configured.',
                ];

            default:
                return null;
        }
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

        return $codes;
    }
}
