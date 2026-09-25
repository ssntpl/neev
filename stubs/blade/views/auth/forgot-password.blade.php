<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link and a code, either of which will let you choose a new one.') }}
        </div>

        <x-neev-component::validation-errors class="mb-4" />
        <x-neev-component::validation-status class="mb-4" />

        @php($codeEmail = session('password_reset_code_email'))

        <form method="POST" action="{{ route('password.email') }}">
            @csrf

            <div class="block">
                <x-neev-component::label for="email" value="{{ __('Email') }}" />
                <x-neev-component::input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $codeEmail)" required :autofocus="!$codeEmail" autocomplete="username" />
            </div>

            <div class="flex gap-4 items-center justify-end mt-4">
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                    {{ __('Back to Login') }}
                </a>

                <x-neev-component::button class="cursor-pointer">
                    {{ $codeEmail ? __('Send Again') : __('Send Reset Link and Code') }}
                </x-neev-component::button>
            </div>
        </form>

        @if ($codeEmail)
            <form method="POST" action="{{ route('user-password.update') }}" class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                @csrf
                <input type="hidden" name="email" value="{{ $codeEmail }}" />

                <div class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('Enter the code sent to :email and choose a new password.', ['email' => $codeEmail]) }}
                </div>

                <div class="mt-4">
                    <x-neev-component::label for="otp" value="{{ __('Code') }}" />
                    <x-neev-component::input id="otp" class="block mt-1 w-full" type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" required autofocus />
                </div>

                <div class="mt-4">
                    <x-neev-component::label for="password" value="{{ __('New Password') }}" />
                    <x-neev-component::input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
                </div>

                <div class="mt-4">
                    <x-neev-component::label for="password_confirmation" value="{{ __('Confirm Password') }}" />
                    <x-neev-component::input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                </div>

                <div class="flex items-center justify-end mt-4">
                    <x-neev-component::button class="cursor-pointer">
                        {{ __('Reset Password') }}
                    </x-neev-component::button>
                </div>
            </form>
        @endif
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
