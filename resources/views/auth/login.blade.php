<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <x-neev-component::validation-errors class="mb-4" />
        <x-neev-component::validation-status class="mb-4" />
        
        @if ($errors->has('email'))
            <div x-data="{ show: true }">
                <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                    <x-slot name="title">
                        {{ __('Account Not Found') }}
                    </x-slot>
                    
                    <x-slot name="content">
                        {{ __('You entered email or username is not registered. Would you like to create a new account?') }}
                    </x-slot>

                    <x-slot name="footer">
                        <x-neev-component::secondary-button class="cursor-pointer" @click="show = false">
                            {{ __('Cancel') }}
                        </x-neev-component::secondary-button>

                        <a href="{{ route('register', ['email' => old('email')]) }}" class="ms-3 inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 focus:bg-blue-700 active:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition ease-in-out duration-150">
                            {{ __('Register') }}
                        </a>
                    </x-slot>
                </x-neev-component::dialog-modal>
            </div>
        @endif

        <form method="POST" action="{{ route('login.password') }}">
            @csrf
            @method('PUT')
            <div>
                <x-neev-component::label for="email" value="{{ __(config('neev.support_username') ? 'Email or Username' : 'Email') }}" />
                <x-neev-component::input id="email" class="block mt-1 w-full" type="text" name="email" :value="old('email')" required autofocus autocomplete="username" />
            </div>
            <input type="hidden" name="redirect" value={{$redirect}}>
            <div class="flex flex-col gap-2 mt-4">
                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('register') }}">
                    {{ __('Have not registered?') }}
                </a>
                
                <div class="flex items-center justify-end">
                    <x-neev-component::button type="submit" class="ms-2">
                        {{ __('Continue') }}
                    </x-neev-component::button>
                </div>
            </div>
        </form>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
