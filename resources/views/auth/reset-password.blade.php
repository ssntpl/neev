<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <x-neev-component::validation-errors class="mb-4" />

        <form method="POST" action="{{ route('password.update') }}">
            @csrf

            <div class="block">
                <x-neev-component::input id="email" class="block mt-1 w-full" type="hidden" name="email" :value="$email" required autofocus />
            </div>

            <div class="mt-4">
                <x-neev-component::label for="password" value="{{ __('Password') }}" />
                <x-neev-component::input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
            </div>

            <div class="mt-4">
                <x-neev-component::label for="password_confirmation" value="{{ __('Confirm Password') }}" />
                <x-neev-component::input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-neev-component::button>
                    {{ __('Reset Password') }}
                </x-neev-component::button>
            </div>
        </form>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
