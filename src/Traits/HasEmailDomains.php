<?php

namespace Ssntpl\Neev\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ssntpl\Neev\Models\EmailDomain;

/**
 * Email domains an owner has claimed (RFC 006): users at them belong to it.
 */
trait HasEmailDomains
{
    /**
     * An owner's email domains go with it: a row always has an owner. They go
     * as any removed claim does, giving back the accounts they deactivated,
     * while the members are still attached; after this nothing could.
     */
    public static function bootHasEmailDomains(): void
    {
        static::deleting(function ($owner) {
            $owner->emailDomains()->get()->each(
                fn (EmailDomain $domain) => $domain->setRelation('owner', $owner)->deleteAndReactivate()
            );
        });
    }

    /**
     * @return MorphMany<EmailDomain, $this>
     */
    public function emailDomains(): MorphMany
    {
        return $this->morphMany(EmailDomain::class, 'owner');
    }
}
