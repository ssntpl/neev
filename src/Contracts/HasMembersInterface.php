<?php

namespace Ssntpl\Neev\Contracts;

use Illuminate\Database\Eloquent\Relations\Relation;

interface HasMembersInterface
{
    public function members(): Relation;

    public function hasMember($user): bool;
}
