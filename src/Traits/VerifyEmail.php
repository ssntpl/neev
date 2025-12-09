<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\User;

trait VerifyEmail
{
    public function hasVerifiedEmail($email = null)
    {
        if ($email) {
            $email = Email::where('email', $email)->first();
            return $email?->verified_at ? true : false;
        }

        $user = User::model()->find($this->id);
        return $user?->email?->verified_at ? true : false;
    }
}
