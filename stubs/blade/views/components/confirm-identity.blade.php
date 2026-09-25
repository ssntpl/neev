@props(['user'])

{{--
    The proof a sensitive action asks for: a password, or a one-time code for
    an account that has none — every OAuth and SSO registration, where
    Hash::check() against a null hash can never succeed.

    Self-contained on purpose. It carries its own x-data, so it can be dropped
    into any dialog without the surrounding scope having to declare the flags
    the "email me a code" button needs.
--}}
<div x-data="{ sending: false, sent: false, sendError: null }" class="mt-4 text-start">
    @if (!$user->password)
        <p class="text-sm">
            {{ __('Accounts registered through a provider have no password, so we email a code instead. Send one, then enter it below.') }}
        </p>

        {{-- Requested with fetch, not a form post: a redirect would reload the
             page, reset x-data and close the dialog before the code could be
             typed into it. --}}
        <div class="mt-2">
            <x-neev-component::secondary-button type="button" class="cursor-pointer"
                x-bind:disabled="sending"
                @click="sending = true; sendError = null;
                    fetch('{{ route('account.confirmation') }}', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    })
                    .then(response => { if (!response.ok) { throw new Error(); } sent = true; })
                    .catch(() => { sendError = '{{ __('The code could not be sent. Please try again.') }}'; })
                    .finally(() => { sending = false; })">
                <span x-show="!sent">{{ __('Email me a code') }}</span>
                <span x-show="sent" x-cloak>{{ __('Send another code') }}</span>
            </x-neev-component::secondary-button>

            <p class="mt-2 text-sm text-green-600 dark:text-green-400" x-show="sent" x-cloak>
                {{ __('Code sent. Check your email and enter it below.') }}
            </p>
            <p class="mt-2 text-sm text-red-600 dark:text-red-400" x-show="sendError" x-cloak x-text="sendError"></p>
        </div>

        <x-neev-component::input type="text"
            name="otp"
            class="mt-3 block w-3/4"
            autocomplete="one-time-code"
            inputmode="numeric"
            placeholder="{{ __('Code') }}" />
    @else
        <x-neev-component::input type="password"
            name="password"
            class="mt-1 block w-3/4"
            autocomplete="current-password"
            placeholder="{{ __('Password') }}" />
    @endif
</div>
