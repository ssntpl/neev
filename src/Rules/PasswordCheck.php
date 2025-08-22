<?php

namespace Ssntpl\Neev\Rules;

use Auth;
use Carbon\Carbon;
use Hash;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\PasswordHistory;
use Ssntpl\Neev\Models\Team;
use Str;

class PasswordCheck implements ValidationRule 
{
    public function validate(string $attribute, mixed $value, Closure $fail): void 
    {
        $user = Auth::user();
        $password = config('neev.password');
        $email = request()->input('email');
        if ($email) {
            $email = Email::where('email', $email)->first();
            $user = $email?->user;
        }

        if ($user && config('neev.domain_federation')) {
            $emailDomain = substr(strrchr($user->email, "@"), 1);

            $team = Team::where('federated_domain', $emailDomain)->first();
            if ($team?->domain_verified_at) {
                $password['min_length'] = $team->rule(DomainRule::pass_min_len())->value ?? null;
                $password['max_length'] = $team->rule(DomainRule::pass_max_len())->value ?? null;
                $password['old_passwords'] = $team->rule(DomainRule::pass_old())->value ?? null;
                $password['combination_types'] = json_decode($team->rule(DomainRule::pass_combinations())->value ?? '[]');
                $password['check_user_columns'] = json_decode($team->rule(DomainRule::pass_columns())->value ?? '[]');
            }
        }

        if (isset($password['min_length']) && $password['min_length']) {
            if (strlen($value) < $password['min_length']) {
                $fail('Password must be at least '.$password['min_length'].' characters.');
                return;
            }
        }
        
        if (isset($password['max_length']) && $password['max_length']) {
            if (strlen($value) > $password['max_length']) {
                $fail('Password must be less than '.$password['max_length'].' characters.');
                return;
            }
        }
        
        if (isset($password['combination_types']) && $password['combination_types']) {
            foreach ($password['combination_types'] ?? [] as $type) {
                if ($type == 'alphabet' && !preg_match('/[a-zA-Z]/', $value)) {
                    $fail('Password must be contain '.$type);
                    return;
                }

                if ($type == 'number' && !preg_match('/\d/', $value)) {
                    $fail('Password must be contain '.$type);
                    return;
                }

                if ($type == 'symbols' && !preg_match('/[^a-zA-Z\d]/', $value)) {
                    $fail('Password must be contain '.$type);
                    return;
                }
            }
        }
       
        if (isset($password['check_user_columns']) && $password['check_user_columns'] && $user) {
            foreach ($password['check_user_columns'] ?? [] as $column) {
                if (str_contains(Str::lower($value), Str::lower($user->{$column})) || str_contains(Str::lower($user->{$column}), Str::lower($value))) {
                    $fail('Password should not be contain '.$column);
                    return;
                }
            }
        }

        if (isset($password['old_passwords']) && $password['old_passwords'] && $user) {
            $oldPasswords = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->limit($password['old_passwords'])->get();
            foreach ($oldPasswords ?? [] as $oldPassword) {
                if (Hash::check($value, (string) $oldPassword->password)) {
                    $fail('New Password should not be the same as old password.');
                    return;
                }
            }
            PasswordHistory::create([
                'user_id' => $user->id,
                'password' => $value,
            ]);
        }
        
        return;
    }

    public static function checkPasswordWarning($user) {
        $password = config('neev.password');
        if ($user || $password) {
            return false;
        }
        $currentPassword = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->first();
        if ($currentPassword && isset($password['password_expiry_soft_days']) && $password['password_expiry_soft_days']) {
            $softLimit = Carbon::parse($currentPassword->created_at)->addDays((int) $password['password_expiry_soft_days']);
            if (Carbon::now()->greaterThanOrEqualTo($softLimit)) {
                return [
                    'message' => 'Please change the password otherwise your account would be blocked. You have changed your password '.$currentPassword->created_at->diffForHumans(),
                ];
            } else {
                return [
                    'message' => 'You have changed your password '.$currentPassword->created_at->diffForHumans()
                ];
            }
        }
        return false;
    }

    public static function isLoginBlock($user) {
        $password = config('neev.password');
        if ($user || $password) {
            return;
        }
        $currentPassword = PasswordHistory::where('user_id', $user->id)->orderByDesc('id')->first();
        if ($currentPassword && isset($password['password_expiry_hard_days']) && $password['password_expiry_hard_days']) {
            $hardLimit = Carbon::parse($currentPassword->created_at)->addDays((int) $password['password_expiry_hard_days']);
            if (Carbon::now()->greaterThanOrEqualTo($hardLimit)) {
                return true;
            }
        }
        return false;
    }
}