<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\PasswordHistory;
use Ssntpl\Neev\Models\User;

class CleanOldPasswords extends Command
{
    protected $signature = 'neev:clean-passwords';
    protected $description = 'Delete passwords older than given in config.';

    public function handle()
    {
        $oldPasswords = config('neev.password.old_passwords');
        $domainFederation = config('neev.domain_federation');
        $users = User::all();
        if (!$users) {
            $this->info('No users found.');
            return;
        }

        if ($domainFederation) {
            $users->each(function ($user) use ($oldPasswords) {
                $team = null;
                $teams = $user->teams;
                foreach ($teams as $t) {
                    if ($t->domain_verified_at) {
                        $team = $t;
                        break;
                    }
                }

                if ($team) {
                    $value = $team->rule(DomainRule::pass_old())->value ?? null;
                    if ($value) {
                        $ids = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->limit($value)->pluck('id');
                        PasswordHistory::where('user_id', $user->id)->whereNotIn('id', $ids)->delete();
                    }
                } else {
                    $ids = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->limit($oldPasswords)->pluck('id');
                    PasswordHistory::where('user_id', $user->id)->whereNotIn('id', $ids)->delete();
                }
            });
        } elseif ($oldPasswords && !$domainFederation) {
            $users->each(function ($user) use ($oldPasswords) {
                $ids = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->limit($oldPasswords)->pluck('id');
                PasswordHistory::where('user_id', $user->id)->whereNotIn('id', $ids)->delete();
            });
        }
        
        $this->info("Password cleanup completed.");
    }
}
