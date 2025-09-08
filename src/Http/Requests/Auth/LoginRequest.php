<?php

namespace Ssntpl\Neev\Http\Requests\Auth;

use Hash;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Models\DomainRule;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\PasswordHistory;
use Ssntpl\Neev\Models\Team;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $email = Email::where('email', $this->input('email'))->first();
        $currentPassword = PasswordHistory::where('user_id', $email->user_id)->orderByDesc('id')->first();
        if (!$currentPassword) {
            $currentPassword = PasswordHistory::create([
                'user_id' => $email->user_id,
                'password' => $email->user->password,
            ]);
        }
        if (!$email || !Hash::check($this->input('password'), $email->user->password)) {
            $currentPassword->wrong_attempts++;
            $isDomainfederated = false;
            if (config('neev.team') && config('neev.domain_federation')) {
                $emailDomain = substr(strrchr($email->email, "@"), 1);
                
                $team = Team::model()->where('federated_domain', $emailDomain)->first();
                if ($team?->domain_verified_at) {
                    $isDomainfederated = true;
                    if ($team->rule(DomainRule::pass_hard_fail_attempts())->value > 0 && $currentPassword?->wrong_attempts >= (int) $team->rule(DomainRule::pass_hard_fail_attempts())->value) {
                        RateLimiter::hit($this->throttleKey(),  60 * 60 * 24 * 365);
                    } elseif ($team->rule(DomainRule::pass_soft_fail_attempts())->value > 0 && $currentPassword?->wrong_attempts%$team->rule(DomainRule::pass_soft_fail_attempts())->value === 0) {
                        RateLimiter::hit($this->throttleKey(), (int) $team->rule(DomainRule::pass_block_user_mins())->value * 60);
                    }
                }
            }

            if (!$isDomainfederated) {
                if (config('neev.password.hard_fail_attempts') > 0 && $currentPassword?->wrong_attempts >= config('neev.password.hard_fail_attempts')) {
                    RateLimiter::hit($this->throttleKey(),  60 * 60 * 24 * 365);
                } elseif (config('neev.password.soft_fail_attempts') > 0 && $currentPassword?->wrong_attempts%config('neev.password.soft_fail_attempts') === 0){
                    RateLimiter::hit($this->throttleKey(), (int) config('neev.password.login_block_minutes') * 60);
                }
            }
            $currentPassword->save();
    
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }
    
        Auth::login($email->user, $this->boolean('remember'));
        $currentPassword->wrong_attempts = 0;
        $currentPassword->save();
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function checkEmail()
    {
        $this->ensureIsNotRateLimited();

        $email = Email::where('email', $this->input('email'))->first();
        $user = $email?->user;

        if (!$user ) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $user;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::availableIn($this->throttleKey()) <= 0) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
