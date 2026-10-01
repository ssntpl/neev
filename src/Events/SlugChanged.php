<?php

namespace Ssntpl\Neev\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A team or tenant took a new slug. Its platform subdomain moved with it, so
 * this is where an application asks the owner to update what still points at
 * the old host: IdP redirect URIs, API base URLs, integrations.
 */
class SlugChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public $owner,
        public string $oldSlug,
        public string $newSlug,
    ) {
    }
}
