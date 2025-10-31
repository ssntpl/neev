<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{   
    protected $fillable = [
        'team_id',
        'enforce',
        'domain',
        'verification_token',
        'is_primary',
        'verified_at',
    ];

    protected $hidden = [
        'verification_token',
    ];

    protected $casts = [
        'enforce' => 'bool',
        'verified_at' => 'datetime',
        'verification_token' => 'hashed',
    ];

    public function team()
    {
        return $this->belongsTo(Team::getClass(), 'team_id');
    }

    public function rules()
    {
        return $this->hasMany(DomainRule::class);
    }

    public function rule($name)
    {
        return $this->hasMany(DomainRule::class)->where('name', $name)->first();
    }
}
