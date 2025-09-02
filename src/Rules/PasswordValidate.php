<?php

namespace Ssntpl\Neev\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Team;

class PasswordValidate implements ValidationRule 
{
    public function validate(string $attribute, mixed $value, Closure $fail): void 
    {
        $password = config('neev.password');

        if (config('neev.team') && config('neev.domain_federation')) {
            $emailDomain = substr(strrchr(request()->input('email'), "@"), 1);

            $team = Team::model()->where('federated_domain', $emailDomain)->first();
            if ($team?->domain_verified_at) {
                $password['min_length'] = $team->rule(DomainRule::pass_min_len())->value ?? null;
                $password['max_length'] = $team->rule(DomainRule::pass_max_len())->value ?? null;
                $password['combination_types'] = json_decode($team->rule(DomainRule::pass_combinations())->value ?? '[]');
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
        
        return;
    }
}