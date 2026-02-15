<?php

namespace Ssntpl\Neev\Http\Requests\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ssntpl\Neev\Models\Email;

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

        if (!$email || !Hash::check($this->input('password'), $email->user->password->password)) {
            $this->handleFailedAttempt();

            throw ValidationException::withMessages([
                'user' => __('auth.failed'),
            ]);
        }

        Auth::login($email->user, $this->boolean('remember'));
        Cache::forget($this->throttleKey() . ':attempts');
        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Check if email exists.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function checkEmail()
    {
        $this->ensureIsNotRateLimited();

        $email = Email::where('email', $this->input('email'))->first();
        return $email?->user;
    }

    /**
     * Handle failed login attempt.
     */
    protected function handleFailedAttempt(): void
    {
        $softFail = config('neev.login_soft_attempts', 5);
        $hardFail = config('neev.login_hard_attempts', 20);
        $blockMinutes = config('neev.login_block_minutes', 1);

        $key = $this->throttleKey();
        $attempts = Cache::get($key . ':attempts', 0) + 1;
        Cache::put($key . ':attempts', $attempts, 3600);

        if ($hardFail > 0 && $attempts >= $hardFail) {
            RateLimiter::hit($key, 60 * 60 * 24 * 365);
        } elseif ($softFail > 0 && $attempts >= $softFail) {
            RateLimiter::hit($key, $blockMinutes * 60);
        }
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::availableIn($this->throttleKey()) > 0) {
            event(new Lockout($this));

            $seconds = RateLimiter::availableIn($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ]);
        }
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
