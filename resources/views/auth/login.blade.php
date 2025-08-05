<x-guest>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <x-validation-errors class="mb-4" />
        <x-validation-status class="mb-4" />

        <form method="POST" action="{{ route('login.password') }}">
            @csrf
            @method('PUT')
            <div>
                <x-label for="email" value="{{ __('Email') }}" />
                <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>

            <div class="flex flex-col gap-2 mt-4">
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('register') }}">
                    {{ __('Have not registered?') }}
                </a>
                
                <div class="flex items-center  justify-end">
                    @if (config('neev.magicauth'))
                        <x-button class="ms-2" name="action" value="link">
                            {{ __('Send Login Link') }}
                        </x-button>
                    @endif

                    <x-button class="ms-2">
                        {{ __('Continue') }}
                    </x-button>
                </div>
            </div>
        </form>
    </x-authentication-card>
</x-guest>
