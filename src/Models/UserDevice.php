<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class UserDevice extends Model
{
    use HasFactory, Notifiable; 

    protected $fillable = [
        'user_id',
        'device_type',
        'device_token',
    ];

    public function routeNotificationForFcm()
    {
        return $this->device_token;
    }

    public function user()
    {
        return $this->belongsTo(User::getClass(),'user_id');
    }
}
