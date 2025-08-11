<?php

namespace Ssntpl\Neev\Http\Requests\Auth;

use Hash;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Models\Email;
use Ssntpl\Neev\Models\PasswordHistory;

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
            'email' => ['required', 'string', 'email'],
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
        if (!$email || !Hash::check($this->input('password'), $email->user->password)) {
            if (config('neev.password.soft_fail_attempts')) {
                $currentPassword = PasswordHistory::where('user_id', $email->user_id)->orderByDesc('id')->first();
                if ($currentPassword?->wrong_attempts >= config('neev.password.hard_fail_attempts')) {
                    RateLimiter::hit($this->throttleKey(),  60 * 60 * 24 * 365);
                } else {
                    RateLimiter::hit($this->throttleKey(), (int) config('neev.password.soft_fail_attempts') * 60);
                }
                $currentPassword->wrong_attempts++;
                $currentPassword->save();
            }
    
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }
    
        Auth::login($email->user, $this->boolean('remember'));
        
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
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), (int) config('neev.password.soft_fail_attempts', 5))) {
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
