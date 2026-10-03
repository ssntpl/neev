<?php

namespace Ssntpl\Neev\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Ssntpl\Neev\Traits\RetiresSlugs;

/**
 * An application's own model retiring slugs, with its slug in a column of
 * its own naming.
 */
class Project extends Model
{
    use RetiresSlugs;

    protected $fillable = ['handle'];

    public function getSlugColumn(): string
    {
        return 'handle';
    }
}
