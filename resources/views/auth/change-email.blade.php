<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>
        <x-neev-component::validation-errors class="mb-4" />
        <x-neev-component::validation-status class="mb-4" />
        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Change your email, we will send you verification link on updated email.') }}
        </div>

        <form method="GET" action="{{ route('verification.notice') }}">
            @csrf
            <x-neev-component::secondary-button type="submit">
                {{ __('Back') }}
            </x-neev-component::secondary-button>
        </form>
        <div class="mt-2">
            <form method="POST" action="{{ route('email.update') }}" class="inline">
                @csrf
                @method('PUT')

                <div class="block">
                    <x-neev-component::label for="email" value="{{ __('Email') }}" />
                    <x-neev-component::input id="email" class="block mt-1 w-full" type="email" name="email" :value="$email" required autofocus />
                </div>
                <div class="mt-2 flex justify-end">
                    <x-neev-component::button type="submit">
                        {{ __('Save') }}
                    </x-neev-component::button>
                </div>
            </div>
            </form>
        </div>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>
