<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>
        <x-neev-component::validation-errors class="mb-4" />
        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Before continuing, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
        </div>

        @if (session('status') == 'verification-link-sent')
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ __('A new verification link has been sent to the email address you provided in your profile settings.') }}
            </div>
        @elseif (session('status'))
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ __(session('status')) }}
            </div>
        @endif
        <div class="py-2">
            {{$email}}
        </div>

        <form method="POST" action="{{ route('email.verify.otp') }}" class="mt-4">
            @csrf

            <div class="flex gap-2 items-end">
                <div class="grow">
                    <x-neev-component::label for="otp" value="{{ __('Or enter the code from the email') }}" />
                    <x-neev-component::input id="otp" class="block mt-1 w-full" type="text" name="otp" inputmode="numeric" autocomplete="one-time-code" required />
                </div>

                <x-neev-component::button type="submit">
                    {{ __('Verify') }}
                </x-neev-component::button>
            </div>
        </form>

        <div class="mt-4 flex items-center justify-between">
            <form method="GET" action="{{ route('email.verification.send') }}">
                @csrf

                <div>
                    <x-neev-component::button type="submit">
                        {{ __('Resend Verification Email') }}
                    </x-neev-component::button>
                </div>
            </form>

            <div>
                <form method="POST" action="{{ route('logout') }}" class="inline">
                    @csrf

                    <x-neev-component::button type="submit">
                        {{ __('Log Out') }}
                    </x-neev-component::button>
                </form>
            </div>
        </div>

        <div class="mt-4 space-y-1 text-sm text-gray-600 dark:text-gray-400">
            <div>
                {{ __('Wrong address?') }}
                <a href="{{ route('email.change') }}"
                   class="text-blue-600 dark:text-blue-400 hover:underline">
                    {{ __('Change your email address') }}
                </a>
            </div>
            <div>
                {{ __('No longer want this account?') }}
                <a href="{{ route('account.security') }}"
                   class="text-blue-600 dark:text-blue-400 hover:underline">
                    {{ __('Manage or delete it') }}
                </a>
            </div>
        </div>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
