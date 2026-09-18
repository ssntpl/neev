<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>
        <div class="flex flex-col gap-4">
            <div class="flex flex-col gap-2 border rounded-lg p-4 text-center">
                <div class="flex gap-2 justify-around flex-wrap">
                    @foreach (config('neev.oauth') as $oauth)
                        <form method="GET" action="{{ route('oauth.redirect', $oauth) }}">
                            <input type="hidden" name="email" value="{{$email}}" required>
                            <x-neev-component::secondary-button type="submit">{{ __($oauth) }}</x-neev-component::secondary-button>
                        </form>
                    @endforeach
                    {{-- Passkey --}}
                    @if (in_array('passkey', $login_options))
                        <form id="login-form" method="POST" action="{{ route('passkeys.login') }}">
                            @csrf
                            <input id="email" type="hidden" name="email" value="{{$email}}" required />

                            <input type="hidden" name="assertion" id="assertion">

                            <x-neev-component::secondary-button type="button" id="login-button">
                                {{__('Login with Passkey')}}
                            </x-neev-component::secondary-button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('login.link.send') }}">
                        @csrf
                        <input type="hidden" name="email" value="{{$email}}" required>
                        <x-neev-component::secondary-button type="submit" class="ms-2">
                            {{ __('Login Via Link') }}
                        </x-neev-component::secondary-button>
                    </form>
                </div>
                @if (in_array('passkey', $login_options))
                    {{-- Options requests the server refuses, and ceremonies that fail in the
                         browser, never reach the form post — so they are reported here. --}}
                    <p id="passkey-error" class="text-sm text-red-600 dark:text-red-400" role="alert" hidden></p>
                @endif
            </div>
            <div class="border rounded-lg p-4">
                <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    {{ __((config('neev.support_username') && ($username ?? false)) ? "Username: $username" : "Email: $email") }}
                </div>

                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div>
                        <input type="hidden" name="email" value="{{$email}}" required />
                        <input type="hidden" name="redirect" value="{{ $redirect }}" />
                        @if (config('neev.support_username') && ($username ?? false))
                            <input type="hidden" name="username" value="{{$username}}" required />
                        @endif
                    </div>
                    <div>
                        <x-neev-component::label for="password" value="{{ __('Password') }}" />
                        <x-neev-component::input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
                    </div>
                    <x-neev-component::validation-errors class="mb-4" />
                    <div class="flex items-center justify-end mt-4">
                        <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                            {{ __('Forgot Password?') }}
                        </a>

                        <x-neev-component::button class="ms-4">
                            {{ __('Log In') }}
                        </x-neev-component::button>
                    </div>
                </form>
            </div>
        </div>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>

@if (in_array('passkey', $login_options))
{{-- Guarded with the control it drives: without it there is no #login-button to
     bind to, and the library is a network fetch nothing on the page would use. --}}
<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.es5.umd.min.js"></script>
<script>
    const { startAuthentication } = SimpleWebAuthnBrowser;
    const showPasskeyError = (message) => {
        const passkeyError = document.getElementById('passkey-error');
        passkeyError.textContent = message;
        passkeyError.hidden = false;
    };

    document.getElementById('login-button').addEventListener('click', async () => {
        document.getElementById('passkey-error').hidden = true;

        const email = document.getElementById('email').value;
        const resp = await fetch('{{ route('passkeys.login.options') }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ email })
        });

        {{-- Login options are refused for an account holding no credential on this
             relying party, which is ordinary on a tenant's own domain: the user's
             passkey may be enrolled under the platform relying party instead. --}}
        if (!resp.ok) {
            showPasskeyError('{{ __('No passkey is available for this account on this site. Use another sign-in method.') }}');
            return;
        }

        const res = await resp.json();

        let assertion;
        try {
            assertion = await startAuthentication({optionsJSON : res});
        } catch (e) {
            {{-- SimpleWebAuthn wraps the browser's error, so the original name is on e.cause. --}}
            if (e.name === 'NotAllowedError' || e.cause?.name === 'NotAllowedError') {
                showPasskeyError('{{ __('Passkey sign-in was cancelled or timed out.') }}');
            } else {
                showPasskeyError('{{ __('Could not sign in with a passkey on this device.') }}' + (e.message ? ' ' + e.message : ''));
            }

            return;
        }

        const attestationInput = document.getElementById('assertion');
        attestationInput.value = JSON.stringify({
            ...assertion,
            challenge: res.challenge
        });

        document.getElementById('login-form').submit();
    });
</script>
@endif