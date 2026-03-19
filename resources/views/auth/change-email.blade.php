<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <x-neev-component::validation-errors class="mb-4" />

        @if (session('status'))
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ __(session('status')) }}
            </div>
        @endif

        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Your current email address is:') }}
            <strong>{{ $email }}</strong>
        </div>

        <form method="POST" action="{{ route('email.update') }}">
            @csrf
            @method('PUT')

            <div class="block">
                <x-neev-component::label for="email" value="{{ __('New Email Address') }}" />
                <x-neev-component::input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            </div>

            <div class="mt-4">
                <x-neev-component::label for="password" value="{{ __('Current Password') }}" />
                <x-neev-component::input id="password" class="block mt-1 w-full" type="password" name="password" required />
            </div>

            <div class="flex items-center justify-end mt-4">
                <x-neev-component::button>
                    {{ __('Send Verification Link') }}
                </x-neev-component::button>
            </div>
        </form>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
