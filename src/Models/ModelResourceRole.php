<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ModelResourceRole extends Pivot
{
    /**
     * The table associated with the pivot model.
     *
     * @var string
     */
    protected $table = 'model_resource_roles';

    protected $fillable = [
        'role_id',
        'model_id',
        'model_type',
        'resource_id',
        'resource_type',
    ];

    public $timestamps = false;

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function model()
    {
        return $this->morphTo('model');
    }

    public function resource()
    {
        return $this->morphTo('resource');
    }
}
