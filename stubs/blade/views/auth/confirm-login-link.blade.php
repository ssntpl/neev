<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Please confirm that you want to sign in. This extra step protects your account from automated email link scanners.') }}
        </div>

        <x-neev-component::validation-errors class="mb-4" />

        <form method="POST" action="{{ route('login.link.verify') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">

            @if (!empty($channel))
                <input type="hidden" name="channel" value="{{ $channel }}">
            @endif

            <div class="flex items-center justify-end mt-4">
                <x-neev-component::button class="cursor-pointer">
                    {{ __('Confirm and Sign In') }}
                </x-neev-component::button>
            </div>
        </form>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
