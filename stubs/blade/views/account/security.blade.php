<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    <div class="flex flex-col gap-4">
        {{-- Change Password --}}
        <x-neev-component::card x-data="{changePasswordOpen: false}">
            {{-- title --}}
            <x-slot name="title">
                {{ $user->password ? __('Change Password') : __('Set Password') }}
            </x-slot>
            
            {{-- Action --}}
            <x-slot name="action" class="flex">
                <div>
                    <div x-show="!changePasswordOpen" x-on:click="changePasswordOpen = true" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                        </svg>
                    </div>

                    <div x-show="changePasswordOpen" x-on:click="changePasswordOpen = false" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </x-slot>

            {{-- Content --}}
            <x-slot name="content">
                {{-- Accounts registered through OAuth, a magic link or a passkey have
                     no current password to prove ownership with, so the only way to
                     set one is the link mailed to the address on the account. --}}
                @if (!$user->password)
                    <div x-show="changePasswordOpen" x-transition class="flex flex-col gap-3">
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ __('Your account has no password yet. We will email a link to') }}
                            <strong>{{ $user->email }}</strong>
                            {{ __('so you can set one.') }}
                        </p>
                        <form method="POST" action="{{ route('password.reset.link') }}" class="flex justify-end">
                            @csrf
                            <x-neev-component::button>
                                {{ __('Email me a link to set a password') }}
                            </x-neev-component::button>
                        </form>
                    </div>
                @else
                <form method="POST" x-show="changePasswordOpen" x-transition action="{{ route('password.change') }}" class="flex flex-col gap-2">
                    @csrf

                    <div class="flex gap-4 justify-between items-center w-2/3">
                        <x-neev-component::label for="current-password" value="{{ __('Current Password') }}" class="w-1/3" />
                        <x-neev-component::input id="current-password" class="block w-5/6" type="password" name="current_password" required autocomplete="current-password" />
                    </div>

                    <div class="flex gap-4 justify-between items-center w-2/3">
                        <x-neev-component::label for="password" value="{{ __('New Password') }}" class="w-1/3" />
                        <x-neev-component::input id="password" class="block w-5/6" type="password" name="password" required autocomplete="off" />
                    </div>

                    <div class="flex gap-4 justify-between items-center w-2/3">
                        <x-neev-component::label for="password_confirmation" value="{{ __('New Confirm Password') }}" class="w-1/3" />
                        <x-neev-component::input id="password_confirmation" class="block w-5/6" type="password" name="password_confirmation" required autocomplete="off" />
                    </div>

                    <div class="relative flex items-center justify-end">
                        <x-neev-component::button>
                            {{ __('Update Password') }}
                        </x-neev-component::button>
                    </div>
                </form>

                {{-- Forgotten the current password? The link goes to the address on
                     the account, so it works without knowing the old one. --}}
                <form method="POST" x-show="changePasswordOpen" x-transition action="{{ route('password.reset.link') }}" class="mt-3 flex items-center gap-2">
                    @csrf
                    <span class="text-sm text-gray-600 dark:text-gray-400">
                        {{ __("Don't remember your current password?") }}
                    </span>
                    <button type="submit" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">
                        {{ __('Email me a reset link') }}
                    </button>
                </form>
                @endif
            </x-slot>
        </x-neev-component::card>

        {{-- Multi Factor Authentication? --}}
        @if (count(config('neev.multi_factor_auth')))
            <x-neev-component::card>
                <x-slot name="title">
                    {{ __('Multi Factor Authentication') }}
                </x-slot>
                <x-slot name="content">
                    <p class="text-sm">Multi-factor authentication adds an additional layer of security to your account by requiring more than just a password to log in</p>
                    @if (count($user->activeMultiFactorAuths))
                        <div class="flex justify-between gap-2 items-center border shadow px-4 py-2 rounded-lg">
                            <div>
                                <h1 class="font-bold">Preferred MFA method</h1>
                                <p class="text-sm">Set your preferred method to use for multi-factor authentication when login.</p>
                            </div>
                            <form method="POST" action="{{route('multi.preferred')}}">
                                @csrf
                                @method('PUT')
                                <select name="auth_method" class="border rounded-md px-2 py-1" onchange="this.form.submit()">
                                    @foreach ($user->activeMultiFactorAuths as $method)
                                        <option value="{{$method->method}}" {{ $method->id === $user->preferredMultiFactorAuth?->id ? 'selected' : '' }}>{{$method->method}}</option>
                                    @endforeach
                                </select>
                            </form>
                        </div>
                    @endif

                    <ul class="overflow-x-auto rounded-lg shadow border border-gray-200 dark:border-gray-700">
                        @foreach (config('neev.multi_factor_auth') as $method)
                            <li class="border odd:bg-white even:bg-gray-50">
                                <div class="flex gap-2 py-2 px-4 items-center justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <div class="flex gap-2 items-center w-1/4">
                                        <p>{{$method}}</p>
                                        @if ($user->multiFactorAuth($method)?->isActive())
                                            <span class="border border-green-700 rounded-full text-xs font-medium leading-[18px] px-2 tracking-tight text-green-700">{{ 'Configured' }}</span>
                                        @elseif ($user->multiFactorAuth($method))
                                            <span class="border border-yellow-600 rounded-full text-xs font-medium leading-[18px] px-2 tracking-tight text-yellow-700">{{ 'Pending verification' }}</span>
                                        @endif
                                    </div>
                                    <div class="flex gap-2 items-center">
                                        @if ($user->multiFactorAuth($method))
                                            <p>{{$user->multiFactorAuth($method)?->last_used?->diffForHumans()}}</p>
                                        @endif
                                    </div>
                                    <div x-data="{ show: false, sending: false, sent: false, sendError: null }" class="text-end">
                                        <form method="POST" action="{{route('multi.auth')}}" class="flex gap-4">
                                            @csrf

                                            <input type="hidden" name="auth_method" value="{{$method}}">
                                            <input type="hidden" name="action" x-ref="action">
                                            @if ($user->multiFactorAuth($method))
                                                <x-neev-component::secondary-button type="submit">{{ __('Edit') }}</x-neev-component::secondary-button>
                                                {{-- Removal is confirmed, so it opens a dialog rather than
                                                     submitting this form: the password or code has to be
                                                     typed somewhere. --}}
                                                <x-neev-component::danger-button type="button" class="cursor-pointer" @click="show = true">{{ __('Delete') }}</x-neev-component::danger-button>
                                            @else
                                                <x-neev-component::button>{{ __('Add') }}</x-neev-component::button>
                                            @endif
                                        </form>

                                        @if ($user->multiFactorAuth($method))
                                            <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                <x-slot name="title">
                                                    {{ __('Remove') }} {{ $method }}
                                                </x-slot>

                                                <x-slot name="content">
                                                    <p class="text-start">
                                                        {{ __('Removing this factor makes every future sign-in easier, so confirm it is you.') }}
                                                    </p>

                                                    @if (!$user->password)
                                                        {{-- No password to confirm with — accounts from a
                                                             provider, and auto-provisioned SSO accounts.
                                                             Requested with fetch, not a form post: a redirect
                                                             would reset x-data and close this dialog. --}}
                                                        <div class="mt-4 text-start">
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
                                                    @endif

                                                    <form method="POST" action="{{ route('multi.auth') }}" x-ref="removeForm">
                                                        @csrf

                                                        <input type="hidden" name="auth_method" value="{{ $method }}">
                                                        <input type="hidden" name="action" value="delete">

                                                        <div class="mt-4 text-start">
                                                            @if ($user->password)
                                                                <x-neev-component::input type="password"
                                                                    name="password"
                                                                    class="mt-1 block w-3/4"
                                                                    autocomplete="current-password"
                                                                    placeholder="{{ __('Password') }}" />
                                                            @else
                                                                <x-neev-component::input type="text"
                                                                    name="otp"
                                                                    class="mt-1 block w-3/4"
                                                                    autocomplete="one-time-code"
                                                                    inputmode="numeric"
                                                                    placeholder="{{ __('Code') }}" />
                                                            @endif
                                                        </div>
                                                    </form>
                                                </x-slot>

                                                <x-slot name="footer">
                                                    <x-neev-component::secondary-button class="cursor-pointer" @click="show = false">
                                                        {{ __('Cancel') }}
                                                    </x-neev-component::secondary-button>

                                                    <x-neev-component::danger-button class="ms-2 cursor-pointer" @click="$refs.removeForm.submit()">
                                                        {{ __('Remove') }}
                                                    </x-neev-component::danger-button>
                                                </x-slot>
                                            </x-neev-component::dialog-modal>
                                        @endif
                                    </div>
                                </div>
                                @if (session("qr_code") && $method === session("method"))
                                    <div class="text-center items-center py-4">
                                        <div class="mt-2">
                                            <p class="mb-2">Scan this QR code with your authenticator app:</p>
                                            <div class="flex justify-center">{!! session("qr_code") !!}</div>

                                            <p class="mt-4">
                                                Or manually enter this secret:
                                            </p>
                                            <code class="bg-gray-100 px-2 py-1 rounded select-all">{{ session('secret') }}</code>
                                        </div>
                                        <form method="POST" action="{{route('otp.mfa.store')}}" class="flex gap-2 justify-between mt-2 w-1/2 justify-self-center items-center p-4">
                                            @csrf

                                            <input type="hidden" name="auth_method" value="{{$method}}">
                                            <input type="hidden" name="email" value="{{$user->email}}">
                                            <div class="flex gap-2 items-center text-start w-2/3">
                                                <x-neev-component::label class="text-start" for="otp" value="{{ __('OTP') }}" class="w-1/4" />
                                                <x-neev-component::input id="otp" class="block w-3/4" type="text" name="otp" required />
                                            </div>

                                            <x-neev-component::button name="action" value="verify">
                                                {{ __('Verify') }}
                                            </x-neev-component::button>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                        @if (count($user->activeMultiFactorAuths) > 0)
                            <li class="border odd:bg-white even:bg-gray-50">
                                <div class="flex gap-2 py-2 px-4 items-center justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <div class="flex gap-2 items-center">
                                        <p>{{ __('Recovery Codes') }}</p>
                                    </div>
                                    <div class="text-end">
                                        <a href="{{route('recovery.codes')}}" target="_blank">
                                            <x-neev-component::secondary-button>{{ $user->recoveryCodes?->count() > 0 ? __('Reset') : 'Create' }}</x-neev-component::secondary-button>
                                        </a>
                                    </div>
                                </div>
                            </li>
                        @endif
                    </ul>
                </x-slot>
            </x-neev-component::card>
        @endif
        
        {{-- Manage Passkeys --}}
        <x-neev-component::card x-data="{openPasskey: false}">
            {{-- Title --}}
            <x-slot name="title">
                {{ __('Manage Passkeys') }}
            </x-slot>

            {{-- Action --}}
            <x-slot name="action" class="flex">
                <div>
                    <div x-show="!openPasskey" x-on:click="openPasskey = true" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                        </svg>
                    </div>

                    <div x-show="openPasskey" x-on:click="openPasskey = false" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </x-slot>

            {{-- Content --}}
            <x-slot name="content">
                <p class="text-sm">Paaskeys allow for a more secure, seamless authentication experience on supported devices.</p>
                <div class="flex flex-col gap-4">
                    <form id="passkey-form" method="POST" class="flex gap-2 items-center justify-between" x-show="openPasskey" x-transition action="{{ route('passkeys.register') }}" class="flex flex-col gap-2">
                        @csrf
                        <input type="hidden" name="attestation" id="attestation-input">

                        <div class="flex gap-2 items-center w-1/2">
                            <x-neev-component::label for="name" value="{{ __('Passkey Name') }}" class="w-1/3" />
                            <x-neev-component::input id="name" class="block w-2/3" type="text" name="name" required />
                        </div>

                        <div class="text-end">
                            <x-neev-component::button id="start" type="button">
                                {{ __('Add Passkey') }}
                            </x-neev-component::button>
                        </div>
                    </form>
                    {{-- Ceremonies that fail in the browser never reach the server, so they are reported here. --}}
                    <p id="passkey-error" class="text-sm text-red-600 dark:text-red-400" role="alert" x-show="openPasskey" hidden></p>
                    @if (count($user->passkeys) > 0)
                        <x-neev-component::table>
                            <x-slot name="head">
                                <tr>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Name</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Location</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Last Used</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Added</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide"></th>
                                </tr>
                            </x-slot>
                            <x-slot name="body">
                                @foreach ($user->passkeys()->orderBy('created_at')->get() as $passkey)
                                    <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="px-6 py-2 text-center">{{ $passkey->name }}</td>
                                        <td class="px-6 py-2 text-center">{{ isset($passkey->location['city']) && isset($passkey->location['state']) && isset($passkey->location['country_iso']) ? $passkey->location['city'].', '.$passkey->location['state'].', '.$passkey->location['country_iso'] : '--' }}</td>
                                        <td class="px-6 py-2 text-center">{{ $passkey->last_used?->diffForHumans() ?? '--' }}</td>
                                        <td class="px-6 py-2 text-center">{{ $passkey->created_at?->diffForHumans() ?? '--' }}</td>
                                        <td class="px-6 py-2 text-center">
                                            <div class="flex gap-4 justify-center">
                                                <form method="POST" action="{{route('passkeys.delete')}}">
                                                    @csrf
                                                    @method('DELETE')

                                                    <input type="hidden" name="passkey_id" value="{{$passkey->id}}">
                                                    <x-neev-component::danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete the passkey?')}}')) $el.closest('form').submit();">{{ __('Delete') }}</x-neev-component::danger-button>
                                                </form>
                                            </div>
                                        </td>
                                    </x-neev-component::table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-neev-component::table>
                    @endif
                </div>
            </x-slot>
        </x-neev-component::card>

        {{-- Danger Zone --}}
        <x-neev-component::card class="border border-red-600">
            {{-- Title --}}
            <x-slot name="title">
                <p class="text-red-600">{{__('Danger Zone')}}</p>
            </x-slot>

            {{-- Content --}}
            <x-slot name="content">
                {{-- Delete Account --}}
                @if ($delete_account)
                    <div x-data="{ show: false, sending: false, sent: false, sendError: null }">
                        <div class="flex justify-between gap-2">
                            <div>
                                <p class="font-medium text-lg">Delete Account</p>
                                <p class="text-sm">Once deleted, it will be gone forever. Please be certain.</p>
                            </div>
                            <x-neev-component::danger-button class="cursor-pointer h-10" @click="show = true">
                                {{ __('Delete Account') }}
                            </x-neev-component::danger-button>
                        </div>

                        <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                            <x-slot name="title">
                                {{ __('Delete Account') }}
                            </x-slot>
                            
                            <x-slot name="content">
                                @if ($user->password)
                                    {{ __('Please enter your password to confirm you would like to delete of your account.') }}
                                @else
                                    {{ __('This cannot be undone. Accounts registered through a provider have no password, so we email a code instead. Send one, then enter it below to confirm.') }}

                                    {{-- Requested with fetch, not a form post: a redirect
                                         would reload the page, reset x-data and close this
                                         dialog before the code could be typed into it. --}}
                                    <div class="mt-4">
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
                                @endif

                                <form method="POST" action="{{ route('account.delete') }}" x-ref="deleteAccountForm">
                                    @csrf
                                    @method('DELETE')
                                    {{-- Accounts registered through OAuth have no password to
                                         confirm with, so they confirm with an emailed code. --}}
                                    <div class="mt-4">
                                        @if ($user->password)
                                            <x-neev-component::input type="password"
                                                name="password"
                                                class="mt-1 block w-3/4"
                                                autocomplete="password"
                                                placeholder="{{ __('Password') }}"
                                                x-ref="password" />
                                        @else
                                            <x-neev-component::input type="text"
                                                name="otp"
                                                class="mt-1 block w-3/4"
                                                autocomplete="one-time-code"
                                                inputmode="numeric"
                                                placeholder="{{ __('Code') }}"
                                                x-ref="otp" />
                                        @endif
                                    </div>
                                </form>
                            </x-slot>

                            <x-slot name="footer">
                                <x-neev-component::secondary-button class="cursor-pointer" @click="show = false">
                                    {{ __('Cancel') }}
                                </x-neev-component::secondary-button>

                                <x-neev-component::danger-button type="submit" class="ms-3 cursor-pointer" @click.prevent="$refs.deleteAccountForm.submit()">
                                    {{ __('Delete Account') }}
                                </x-neev-component::danger-button>
                            </x-slot>
                        </x-neev-component::dialog-modal>
                    </div>
                @endif
            </x-slot>
        </x-neev-component::card>
    </div>
</x-neev-layout::app>
<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.es5.umd.min.js"></script>
<script>
    const { startRegistration } = SimpleWebAuthnBrowser;
    const passkeyError = document.getElementById('passkey-error');

    const showPasskeyError = (message) => {
        passkeyError.textContent = message;
        passkeyError.hidden = false;
    };

    document.getElementById('start').addEventListener('click', async () => {
        passkeyError.hidden = true;
        passkeyError.textContent = '';

        const resp = await fetch('{{ route('passkeys.register.options') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
        });

        if (!resp.ok) {
            showPasskeyError('{{ __('Could not start passkey registration. Please try again.') }}');
            return;
        }

        const res = await resp.json();

        let attestation;
        try {
            attestation = await startRegistration({optionsJSON: res});
        } catch (e) {
            {{-- Registration options carry excludeCredentials, so an authenticator already
                 enrolled on this relying party refuses the ceremony with InvalidStateError. --}}
            if (e.name === 'InvalidStateError' || e.code === 'ERROR_AUTHENTICATOR_PREVIOUSLY_REGISTERED') {
                showPasskeyError('{{ __('This device already has a passkey for this account. Use it to sign in, or delete it below before adding a new one.') }}');
            {{-- SimpleWebAuthn wraps the browser's error, so the original name is on e.cause. --}}
            } else if (e.name === 'NotAllowedError' || e.cause?.name === 'NotAllowedError') {
                showPasskeyError('{{ __('Passkey registration was cancelled or timed out.') }}');
            } else {
                showPasskeyError('{{ __('Could not register a passkey on this device.') }}' + (e.message ? ' ' + e.message : ''));
            }

            return;
        }

        const attestationInput = document.getElementById('attestation-input');
        attestationInput.value = JSON.stringify({
            ...attestation,
            challenge: res.challenge
        });

        document.getElementById('passkey-form').submit();
    });
</script>