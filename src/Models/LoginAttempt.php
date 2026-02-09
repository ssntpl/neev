<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LoginAttempt extends Model
{
    public const Password = 'password';
    public const Passkey = 'passkey';
    public const MagicAuth = 'magic auth';
    public const SSO = 'sso';
    public const OAuth = 'oauth';

    protected $fillable = [
        'user_id',
        'method',
        'multi_factor_method',
        'location',
        'platform',
        'browser',
        'device',
        'ip_address',
        'is_success',
        'is_suspicious',
    ];

    protected $casts = [
        'location' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::getClass(),'user_id');
    }
    
    public static function getClientDetails(Request $request = null, $userAgent = null): array
    {
        $userAgent = $userAgent ?? $request->header('User-Agent');
        // Detect browser
        $browser = '';
        if (stripos($userAgent, 'Chrome') !== false) $browser = 'Chrome';
        elseif (stripos($userAgent, 'Firefox') !== false) $browser = 'Firefox';
        elseif (stripos($userAgent, 'Safari') !== false && stripos($userAgent, 'Chrome') === false) $browser = 'Safari';
        elseif (stripos($userAgent, 'Edge') !== false) $browser = 'Edge';
        elseif (stripos($userAgent, 'OPR') !== false || stripos($userAgent, 'Opera') !== false) $browser = 'Opera';

        // Detect platform
        $platform = '';
        if (stripos($userAgent, 'Windows') !== false) $platform = 'Windows';
        elseif (stripos($userAgent, 'Mac OS X') !== false) $platform = 'Mac OS X';
        elseif (stripos($userAgent, 'iPhone') !== false) $platform = 'iPhone';
        elseif (stripos($userAgent, 'iPad') !== false) $platform = 'iPad';
        elseif (stripos($userAgent, 'Android') !== false) $platform = 'Android';
        elseif (stripos($userAgent, 'Linux') !== false) $platform = 'Linux';

        // Detect device
        $device = 'Desktop';
        if (preg_match('/Mobile|Android|iPhone|iPod/i', $userAgent)) {
            $device = 'Mobile';
        } elseif (preg_match('/Tablet|iPad/i', $userAgent)) {
            $device = 'Tablet';
        }

        return [
            'browser'  => $browser,
            'platform' => $platform,
            'device'   => $device,
        ];
    }
}
