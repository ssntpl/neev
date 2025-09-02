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
        <div>
            <a
                href="{{ route('email.change') }}"
                class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800"
            >
                {{ __('Change Email') }}</a>
        </div>
        <div class="mt-4 flex items-center justify-between">
            <form method="GET" action="{{ route('verification.send') }}">
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
                </div>
                </form>
            </div>
        </div>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
