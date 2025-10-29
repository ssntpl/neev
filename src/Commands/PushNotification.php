<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ssntpl\Neev\Models\User;
use Exception;

class PushNotification extends Command
{
    protected $signature = 'neev:notify {user_id=1}';

    protected $description = 'Send push notification to user';

    public function handle()
    {
        try {
            $userId = $this->argument('user_id');
            $user = User::find($userId);
            
            if (!$user) {
                $this->error("User with ID {$userId} not found");
                return 1;
            }

            $user->notify(new \Ssntpl\Neev\Notifications\PushNotification(
                'Test Notification',
                'This is a test notification from Neev package.',
            ));
            
            $this->info('Notification sent successfully!');
            
        } catch (Exception $e) {
            $this->error('Failed to send notification: ' . $e->getMessage());
            Log::error('FCM Notification Error: ' . $e->getMessage(), [
                'user_id' => $userId ?? null,
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
        
        return 0;
    }
}