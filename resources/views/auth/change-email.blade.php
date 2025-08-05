<x-guest>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>
        <x-validation-errors class="mb-4" />
        <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
            {{ __('Change your email, we will send you verification link on updated email.') }}
        </div>

        @session('status')
            <div class="mb-4 font-medium text-sm text-green-600 dark:text-green-400">
                {{ __(session('status')) }}
            </div>
        @endsession

        <x-input-error for="message" />

        <form method="GET" action="{{ route('verification.notice') }}">
            @csrf
            <x-secondary-button type="submit">
                {{ __('Back') }}
            </x-secondary-button>
        </form>
        <div class="mt-2">
            <form method="POST" action="{{ route('email.update') }}" class="inline">
                @csrf
                @method('PUT')

                <div class="block">
                    <x-label for="email" value="{{ __('Email') }}" />
                    <x-input id="email" class="block mt-1 w-full" type="email" name="email" :value="$email" required autofocus />
                </div>
                <div class="mt-2 flex justify-end">
                    <x-button type="submit">
                        {{ __('Save') }}
                    </x-button>
                </div>
            </div>
            </form>
        </div>
    </x-authentication-card>
</x-guest>
