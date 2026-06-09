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
                {{ __('Change Password') }}
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
            </x-slot>
        </x-neev-component::card>

        {{-- Multi Factor Authentication? --}}
        @if (count(config('neev.multi_factor_auth')))
            <x-neev-component::card>
                <x-slot name="title">
                    {{ __('Multi Factor Authentication') }}
                </x-slot>
                <x-slot name="content">
                    <p class="text-sm">Multi-factor authentication adds an additional layer of security to your account by requiring more than just a password to log in. You can register more than one of each method (for example, several authenticator apps) and name each one.</p>

                    <ul class="overflow-x-auto rounded-lg shadow border border-gray-200 dark:border-gray-700">
                        {{-- Configured / pending instances — each row is one method instance --}}
                        @foreach ($user->multiFactorAuths as $auth)
                            <li class="border odd:bg-white even:bg-gray-50">
                                <div class="flex gap-2 py-2 px-4 items-center justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <div class="flex gap-2 items-center w-1/3">
                                        <p class="capitalize">{{ $auth->method }}</p>
                                        @if ($auth->name)
                                            <span class="text-sm text-gray-500">({{ $auth->name }})</span>
                                        @endif
                                        @if ($auth->method === 'email' && $auth->email)
                                            <span class="text-sm text-gray-500">{{ $auth->email }}</span>
                                        @endif
                                        @if ($auth->status === 'active')
                                            <span class="border border-green-700 rounded-full text-xs font-medium leading-[18px] px-2 tracking-tight text-green-700">{{ __('Active') }}</span>
                                        @else
                                            <span class="border border-yellow-600 rounded-full text-xs font-medium leading-[18px] px-2 tracking-tight text-yellow-600">{{ __('Pending') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex gap-2 items-center">
                                        <p class="text-sm">{{ $auth->last_used?->diffForHumans() ?? __('Never used') }}</p>
                                    </div>
                                    <div x-data class="text-end">
                                        <form method="POST" action="{{route('multi.auth')}}" class="flex gap-4 justify-end">
                                            @csrf

                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="{{ $auth->id }}">
                                            <x-neev-component::danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete?')}}')) $el.closest('form').submit();">{{ __('Delete') }}</x-neev-component::danger-button>
                                        </form>
                                    </div>
                                </div>

                                {{-- Setup (QR + verify) shown only for the authenticator instance just added --}}
                                @if (session('qr_code') && session('method') === 'authenticator' && (int) session('id') === $auth->id)
                                    <div class="text-center items-center py-4">
                                        <div class="mt-2">
                                            <p class="mb-2">Scan this QR code with your authenticator app:</p>
                                            <div class="flex justify-center">{!! session("qr_code") !!}</div>

                                            <p class="mt-4">
                                                Or manually enter this secret:
                                            </p>
                                            <code class="bg-gray-100 px-2 py-1 rounded select-all">{{ session('secret') }}</code>
                                        </div>
                                        <form method="POST" action="{{route('mfa.setup.otp.verify')}}" class="flex gap-2 justify-between mt-2 w-1/2 justify-self-center items-center p-4">
                                            @csrf

                                            <input type="hidden" name="auth_method" value="authenticator">
                                            <input type="hidden" name="id" value="{{ $auth->id }}">
                                            <div class="flex gap-2 items-center text-start w-2/3">
                                                <x-neev-component::label class="text-start" for="otp-{{ $auth->id }}" value="{{ __('OTP') }}" class="w-1/4" />
                                                <x-neev-component::input id="otp-{{ $auth->id }}" class="block w-3/4" type="text" name="otp" required />
                                            </div>

                                            <x-neev-component::button>
                                                {{ __('Verify') }}
                                            </x-neev-component::button>
                                        </form>
                                    </div>
                                @endif

                                {{-- A pending email instance: enter the code we emailed to that address --}}
                                @if ($auth->status !== 'active' && $auth->method === 'email')
                                    <div class="text-center items-center py-4">
                                        <p class="mb-2 text-sm">{{ __('We emailed a verification code to') }} <span class="font-medium">{{ $auth->email }}</span>. {{ __('Enter it to enable this email.') }}</p>
                                        <form method="POST" action="{{route('mfa.setup.otp.verify')}}" class="flex gap-2 justify-between mt-2 w-1/2 justify-self-center items-center p-4">
                                            @csrf

                                            <input type="hidden" name="auth_method" value="email">
                                            <input type="hidden" name="id" value="{{ $auth->id }}">
                                            <div class="flex gap-2 items-center text-start w-2/3">
                                                <x-neev-component::label class="text-start" for="email-otp-{{ $auth->id }}" value="{{ __('OTP') }}" class="w-1/4" />
                                                <x-neev-component::input id="email-otp-{{ $auth->id }}" class="block w-3/4" type="text" name="otp" required />
                                            </div>

                                            <x-neev-component::button>
                                                {{ __('Verify') }}
                                            </x-neev-component::button>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach

                        {{-- Add a method. Each method can be added repeatedly;
                             email addresses must be unique per user. --}}
                        @foreach (config('neev.multi_factor_auth') as $method)
                            <li class="border odd:bg-white even:bg-gray-50">
                                <form method="POST" action="{{route('multi.auth')}}" class="flex gap-2 py-2 px-4 items-center justify-between">
                                    @csrf

                                    <input type="hidden" name="auth_method" value="{{ $method }}">
                                    <div class="flex gap-2 items-center w-1/4">
                                        <p class="capitalize">{{ $method }}</p>
                                    </div>
                                    <div class="flex flex-1 gap-2 items-center">
                                        <x-neev-component::input class="block w-1/2" type="text" name="name" placeholder="{{ __('Name (optional)') }}" />
                                        @if ($method === 'email')
                                            <x-neev-component::input class="block w-1/2" type="email" name="email" placeholder="{{ __('Email (optional — defaults to account email)') }}" />
                                        @endif
                                    </div>
                                    <div class="text-end">
                                        <x-neev-component::button>{{ __('Add') }}</x-neev-component::button>
                                    </div>
                                </form>
                            </li>
                        @endforeach

                        @if (count($user->multiFactorAuths) > 0)
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
                    <div x-data="{ show: false }">
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
                                {{ __('Please enter your password to confirm you would like to delete of your account.') }}
                                
                                <form method="POST" action="{{ route('account.delete') }}" x-ref="deleteAccountForm">
                                    @csrf
                                    @method('DELETE')
                                    <div class="mt-4">
                                        <x-neev-component::input type="password"
                                            name="password"
                                            class="mt-1 block w-3/4"
                                            autocomplete="password"
                                            placeholder="{{ __('Password') }}"
                                            x-ref="password" />
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
    document.getElementById('start').addEventListener('click', async () => {
        const resp = await fetch('{{ route('passkeys.register.options') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
            },
        });
        const res = await resp.json();

        const attestation = await startRegistration({optionsJSON: res});

        const attestationInput = document.getElementById('attestation-input');
        attestationInput.value = JSON.stringify({
            ...attestation,
            challenge: res.challenge
        });

        document.getElementById('passkey-form').submit();
    });
</script>