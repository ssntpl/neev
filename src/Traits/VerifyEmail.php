<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\Email;

trait VerifyEmail
{
    public function hasVerifiedEmail($email = null)
    {
        if ($email) {
            $email = Email::where('email', $email)->first();
            return $email?->verified_at !== null;
        }

        return $this->email?->verified_at !== null;
    }
}
