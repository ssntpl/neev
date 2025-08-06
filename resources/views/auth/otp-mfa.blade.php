<x-guest>
    <x-authentication-card>
        <x-slot name="logo">
            <x-authentication-card-logo />
        </x-slot>

        <div class="mb-4 flex flex-col gap-4 text-center text-sm text-gray-600 dark:text-gray-400" style="height: 500px">
            <div class="font-bold text-xl">
                {{__('Multi-factor authentication')}}
            </div>
            <p>
                @if ($method === Ssntpl\Neev\Models\MultiFactorAuth::authenticator())
                    {{ __('Enter the code from your multi-factor authentication app or browser extension below.') }}
                @elseif ($method === Ssntpl\Neev\Models\MultiFactorAuth::email())
                    {{ __('We’ve sent a code to your email address. Enter it below.') }}
                @endif
            </p>
    
            <x-validation-errors class="mb-4" />
            <x-validation-status class="mb-4" />
    
            <form method="POST" action="{{ route('otp.mfa.store') }}">
                @csrf
    
                <input type="hidden" name="email" value="{{$email}}">
                <input type="hidden" name="auth_method" value="{{$method}}">
                <div class="block">
                    <x-input id="otp" class="block mt-1 w-full text-center" placeholder="XXXXXX" type="text" name="otp" required autofocus />
                </div>
    
                <div class="mt-4">
                    <x-button class="cursor-pointer w-full flex justify-center">
                        {{ __('Verify') }}
                    </x-button>
                </div>
            </form>
            <div x-data="{openMethod: false}">
                <x-secondary-button @click="openMethod = !openMethod" class="w-full flex gap-2 justify-center">
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
                </x-secondary-button>
                @php
                    $user = Ssntpl\Neev\Models\Email::where('email', $email)->first()?->user;
                @endphp
                <div x-show="openMethod" x-transition class="flex flex-col gap-4 mt-4">
                    <div class="border-t border border-4 border-gray-200 dark:border-gray-700"></div>
                    @if ($method === Ssntpl\Neev\Models\MultiFactorAuth::email())
                        <form method="POST" action="{{ route('otp.mfa.send') }}">
                            @csrf

                            <div>
                                <x-secondary-button class="w-full flex justify-center" type="submit">
                                    {{ __('Resend Email') }}
                                </x-secondary-button>
                            </div>
                        </form>
                    @endif
                    @foreach ($user?->multiFactorAuths()->whereNot('method', $method)->get() as $auth)
                        <a href="{{route('otp.mfa.create', $auth->method)}}">
                            <x-secondary-button class="w-full flex justify-center">{{Ssntpl\Neev\Models\MultiFactorAuth::UIName($auth->method)}}</x-secondary-button>
                        </a>
                    @endforeach
                    <a href="{{route('otp.mfa.create', 'recovery')}}">
                        <x-secondary-button class="w-full flex justify-center">{{ __('Recovery Code') }}</x-secondary-button>
                    </a>
                </div>
            </div>
        </div>
    </x-authentication-card>
</x-guest>