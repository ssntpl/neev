<x-neev-layout::guest>
    <x-neev-component::authentication-card>
        <x-slot name="logo">
            <x-neev-component::authentication-card-logo />
        </x-slot>

        <div class="mb-4 flex flex-col gap-4 text-center text-sm text-gray-600 dark:text-gray-400" style="height: 500px">
            <div class="font-bold text-xl">
                {{__('Multi-factor authentication')}}
            </div>
            <p>
                @if ($method === 'authenticator')
                    {{ __('Enter the code from your multi-factor authentication app or browser extension below.') }}
                @elseif ($method === 'email')
                    {{ __('We’ve sent a code to your email address. Enter it below.') }}
                @endif
            </p>

            <x-neev-component::validation-errors class="mb-4" />
            <x-neev-component::validation-status class="mb-4" />

            <form method="POST" action="{{ route('otp.mfa.store') }}">
                @csrf
    
                <input type="hidden" name="email" value="{{$email}}">
                <input type="hidden" name="auth_method" value="{{$method}}">
                <input type="hidden" name="attempt_id" value="{{$attempt_id}}">
                <div class="block">
                    <x-input id="otp" class="block mt-1 w-full text-center" placeholder="XXXXXX" type="text" name="otp" required autofocus />
                </div>
    
                <div class="mt-4">
                    <x-neev-component::button class="cursor-pointer w-full flex justify-center">
                        {{ __('Verify') }}
                    </x-neev-component::button>
                </div>
            </form>
            <div x-data="{openMethod: false}">
                <x-neev-component::secondary-button @click="openMethod = !openMethod" class="w-full flex gap-2 justify-center">
                    {{ __('More Options') }}
                    <span x-show="!openMethod" x-cloak>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </span>
                    <span x-show="openMethod" x-cloak>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                        </svg>
                    </span>
                </x-neev-component::secondary-button>
                @php
                    $user = Ssntpl\Neev\Models\Email::where('email', $email)->first()?->user;
                @endphp
                <div x-show="openMethod" x-transition class="flex flex-col gap-4 mt-4">
                    <div class="border-t border border-4 border-gray-200 dark:border-gray-700"></div>
                    @if ($method === 'email')
                        <form method="POST" action="{{ route('otp.mfa.send') }}">
                            @csrf

                            <div>
                                <x-neev-component::secondary-button class="w-full flex justify-center" type="submit">
                                    {{ __('Resend Email') }}
                                </x-neev-component::secondary-button>
                            </div>
                        </form>
                    @endif
                    @foreach ($user?->multiFactorAuths()->whereNot('method', $method)->get() as $auth)
                        <a href="{{route('otp.mfa.create', $auth->method)}}">
                            <x-neev-component::secondary-button class="w-full flex justify-center">{{$auth->method}}</x-neev-component::secondary-button>
                        </a>
                    @endforeach
                    <a href="{{route('otp.mfa.create', 'recovery')}}">
                        <x-neev-component::secondary-button class="w-full flex justify-center">{{ __('Recovery Code') }}</x-neev-component::secondary-button>
                    </a>
                </div>
            </div>
        </div>
    </x-neev-component::authentication-card>
</x-neev-layout::guest>