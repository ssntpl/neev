<?php

namespace Ssntpl\Neev\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class LoginAttempt extends Model
{
    public const Password = 'password';
    public const Passkey = 'passkey';
    public const MagicAuth = 'magic auth';

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

    public static function isSuspicious($user, array $clientDetails, string $ip, ?string $location = null): bool
    {
        $allAttempts = $user->loginAttempts()
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->get();

        $successfulAttempts = $allAttempts->where('is_success', true);
        
        if ($successfulAttempts->isEmpty()) {
            return false;
        }

        $riskScore = 0;
        $deviceFingerprint = $clientDetails['browser'] . '|' . $clientDetails['platform'] . '|' . $clientDetails['device'];

        // Recent Failed Attempts Analysis
        $recentFailedAttempts = $allAttempts->where('is_success', false)
            ->where('created_at', '>=', now()->subHours(24));
        
        $failedFromSameDevice = $recentFailedAttempts->filter(fn($attempt) => 
            $attempt->browser . '|' . $attempt->platform . '|' . $attempt->device === $deviceFingerprint
        )->count();
        
        if ($failedFromSameDevice > 0) {
            $riskScore += min($failedFromSameDevice * 2, 6); // Cap at 6 points
        }

        // IP Analysis
        $ipCounts = $successfulAttempts->groupBy('ip_address')->map->count();
        if (!$ipCounts->has($ip)) {
            $riskScore += 3;
        } elseif (($ipCounts->get($ip, 0) / $successfulAttempts->count()) < 0.1) {
            $riskScore += 2;
        }

        // Device Analysis
        $deviceCombinations = $successfulAttempts->map(fn($attempt) => 
            $attempt->browser . '|' . $attempt->platform . '|' . $attempt->device
        )->countBy();
        
        if (!$deviceCombinations->has($deviceFingerprint)) {
            $riskScore += 2;
        }

        // Location Analysis
        if ($location) {
            $locationCounts = $successfulAttempts->whereNotNull('location')
                ->pluck('location')
                ->filter()
                ->countBy();
            
            if ($locationCounts->isNotEmpty() && !$locationCounts->has($location)) {
                $riskScore += 2;
            }
        }

        $threshold = match (true) {
            $successfulAttempts->count() < 3 => 4,
            $successfulAttempts->count() < 10 => 3,
            default => 2
        };

        return $riskScore >= $threshold;
    }
}