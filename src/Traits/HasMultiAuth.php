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
        return $this->hasMany(MultiFactorAuth::class);
    }

    public function activeMultiFactorAuths()
    {
        return $this->multiFactorAuths()->active();
    }

    public function pendingMultiFactorAuths()
    {
        return $this->multiFactorAuths()->pending();
    }

    public function multiFactorAuth($method, $status = null)
    {
        $query = $this->multiFactorAuths()->where('method', $method);
        if ($status === MultiFactorAuth::STATUS_ACTIVE) {
            $query->active();
        } elseif ($status === MultiFactorAuth::STATUS_PENDING) {
            $query->pending();
        }
        return $query->first();
    }

    /**
     * Verify an OTP against active instances of the given method. A user may
     * have several (e.g. multiple authenticator apps); by default the code is
     * tried against all of them and succeeds if any one matches. Pass $id to
     * pin verification to one specific instance.
     */
    public function verifyMultiFactorOtp(string $method, string $otp, ?int $id = null): bool
    {
        $query = $this->activeMultiFactorAuths()->where('method', $method);
        if ($id) {
            $query->where('id', $id);
        }
        foreach ($query->get() as $auth) {
            if ($auth->verifyOTP($otp)) {
                return true;
            }
        }
        return false;
    }

    public function preferredMultiFactorAuth()
    {
        // The preferred method is simply the most recently used active method.
        return $this->hasOne(MultiFactorAuth::class)->ofMany(
            ['last_used' => 'max', 'id' => 'max'],
            fn ($query) => $query->active()
        );
    }

    public function recoveryCodes()
    {
        return $this->hasMany(RecoveryCode::class);
    }

    public function addMultiFactorAuth($method, $name = null, $status = null, $email = null)
    {
        switch ($method) {
            case 'authenticator':
                $secret = Base32::encodeUpper(random_bytes(32));

                $auth = $this->multiFactorAuths()
                    ->pending()
                    ->where('method', 'authenticator')
                    ->where('name', $name)
                    ->first();

                if ($auth) {
                    $auth->secret = $secret;
                    if ($status !== null) {
                        $auth->status = $status;
                    }
                    $auth->save();
                } else {
                    $auth = $this->multiFactorAuths()->create([
                        'method' => 'authenticator',
                        'name' => $name,
                        'secret' => $secret,
                        'status' => $status ?? MultiFactorAuth::STATUS_PENDING,
                    ]);
                }

                return [
                    'status' => 'Success',
                    'id' => $auth->id,
                    'name' => $auth->name,
                    'qr_code' => MultiFactorAuth::getQrCodeForAuthenticatorSetup($secret, $this->email),
                    'secret' => $secret,
                    'method' => 'authenticator',
                ];

            case 'email':
                $target = $email ?? $this->email;

                $exists = $this->multiFactorAuths()
                    ->where('method', 'email')
                    ->get()
                    ->contains(fn ($auth) => $auth->email === $target);

                if ($exists) {
                    return [
                        'status' => 'Error',
                        'method' => 'email',
                        'message' => 'This email is already configured.',
                    ];
                }

                $defaultStatus = $target === $this->email
                    ? MultiFactorAuth::STATUS_ACTIVE
                    : MultiFactorAuth::STATUS_PENDING;

                $auth = $this->multiFactorAuths()->create([
                    'method' => 'email',
                    'name' => $name,
                    'email' => $target,
                    'status' => $status ?? $defaultStatus,
                ]);

                return [
                    'status' => 'Success',
                    'id' => $auth->id,
                    'name' => $auth->name,
                    'email' => $auth->email,
                    'method' => 'email',
                    'message' => $auth->status === MultiFactorAuth::STATUS_PENDING
                        ? 'Verification code sent. Enter it to enable this email.'
                        : 'Email Configured.',
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
