<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $name
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainRule extends Model
{
    protected $fillable = [
        'domain_id',
        'name',
        'value',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }
}
