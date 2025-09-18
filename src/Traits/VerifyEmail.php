<?php

namespace Ssntpl\Neev\Traits;

use Ssntpl\Neev\Models\User;

trait VerifyEmail
{
    public function hasVerifiedEmail()
    {
        $user = User::find($this->id);
        return $user->primaryEmail?->verified_at;
    }
}
