<x-guest>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>
        <div class="flex flex-col gap-4">
            <div class="border rounded-lg p-4 text-center">
                <form id="login-form" method="POST" action="{{ route('passkeys.login') }}">
                    @csrf
                    <input id="email" type="hidden" name="email" value="{{$email}}" required />

                    <input type="hidden" name="assertion" id="assertion">

                    <x-button type="button" id="login-button">
                        {{__('Login with Passkey')}}
                    </x-button>
                </form>
            </div>
            <div class="border rounded-lg p-4">
                <div class="mb-4 text-sm text-gray-600 dark:text-gray-400">
                    {{ __("Email: $email") }}
                </div>


                <form method="POST" action="{{ route('login') }}">
                    @csrf
                    <div>
                        <input type="hidden" name="email" value="{{$email}}" required />
                    </div>
                    <div>
                        <x-label for="password" value="{{ __('Password') }}" />
                        <x-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" autofocus />
                    </div>
                    <x-validation-errors class="mb-4" />
                    <div class="block mt-4 flex justify-between">
                        <label for="remember_me" class="flex items-center">
                            <x-checkbox id="remember_me" name="remember" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>
                        </label>
                    </div>

                    <div class="flex items-center justify-end mt-4">
                        <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-blue-600 dark:hover:text-blue-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">
                            {{ __('Forgot Password?') }}
                        </a>

                        <x-button class="ms-4">
                            {{ __('Log In') }}
                        </x-button>
                    </div>
                </form>
            </div>
        </div>
    </x-authentication-card>
</x-guest>
<script src="https://unpkg.com/@simplewebauthn/browser/dist/bundle/index.es5.umd.min.js"></script>
<script>
    const { startAuthentication } = SimpleWebAuthnBrowser;
    document.getElementById('login-button').addEventListener('click', async () => {
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
        const res = await resp.json();

        const assertion = await startAuthentication({optionsJSON : res});

        const attestationInput = document.getElementById('assertion');
        attestationInput.value = JSON.stringify({
            ...assertion,
            challenge: res.challenge
        });

        document.getElementById('login-form').submit();
    });
</script>