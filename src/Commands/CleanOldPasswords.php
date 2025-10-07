<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\Password;
use Ssntpl\Neev\Models\User;

class CleanOldPasswords extends Command
{
    protected $signature = 'neev:clean-passwords';
    protected $description = 'Delete passwords older than given in config.';

    public function handle()
    {
        $passwordRules = config('neev.password');
        $oldPasswords = 5; // default
        
        // Extract count from PasswordHistory rule
        foreach ($passwordRules as $rule) {
            if (is_object($rule) && get_class($rule) === 'Ssntpl\Neev\Rules\PasswordHistory') {
                $reflection = new \ReflectionClass($rule);
                $property = $reflection->getProperty('count');
                $property->setAccessible(true);
                $oldPasswords = $property->getValue($rule);
                break;
            }
        }
        $users = User::model()->all();
        if (!$users) {
            $this->info('No users found.');
            return;
        }

        if ($oldPasswords) {
            $users->each(function ($user) use ($oldPasswords) {
                $ids = Password::where('user_id', $user->id)->orderByDesc('id')->limit($oldPasswords)->pluck('id');
                Password::where('user_id', $user->id)->whereNotIn('id', $ids)->delete();
            });
        }
        
        $this->info("Password cleanup completed.");
    }
}
