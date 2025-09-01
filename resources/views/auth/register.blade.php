<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <x-neev-component::validation-errors class="mb-4" />

        <form method="POST" action="{{ route('register') }}">
            @csrf

            @if ($id ?? null && $hash ?? null)
                <div>
                    <input type="hidden" name="invitation_id" value="{{$id}}"/>
                    <input type="hidden" name="hash" value="{{$hash}}"/>
                </div>
            @endif

            <div>
                <x-neev-component::label for="name" value="{{ __('Name') }}" />
                <x-neev-component::input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" />
            </div>

            <div class="mt-4">
                <x-neev-component::label for="email" value="{{ __('Email') }}" />
                <x-neev-component::input id="email" class="block mt-1 w-full" type="email" name="email" :value="$email ?? old('email')" required autocomplete="username" />
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
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('login') }}">
                    {{ __('Already registered?') }}
                </a>

                <x-neev-component::button class="ms-4">
                    {{ __('Register') }}
                </x-neev-component::button>
            </div>
        </form>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
