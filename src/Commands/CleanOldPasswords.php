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
        $oldPasswords = config('neev.password.old_passwords');
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
