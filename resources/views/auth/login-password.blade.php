<x-guest>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Please confirm your password before continuing.') }}
        </div>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __("Email: $email") }}
        </div>


        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div>
                <x-input id="email" class="block mt-1 w-full" :disabled type="hidden" name="email" :value="$email" required />
            </div>
            <div>
                <x-label for="password" value="{{ __('Password') }}" />
                <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
            </div>
            <x-validation-errors class="mb-4" />
            <div class="block mt-4 flex justify-between">
                <label for="remember_me" class="flex items-center">
                    <x-checkbox id="remember_me" name="remember" />
                    <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
                </label>
            </div>

            <div class="flex items-center justify-end mt-4">
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                    {{ __('Forgot Password?') }}
                </a>

                <x-button class="ms-4">
                    {{ __('Log In') }}
                </x-button>
            </div>
        </form>
    </x-authentication-card>
</x-guest>
