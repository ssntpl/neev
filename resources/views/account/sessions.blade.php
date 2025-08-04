<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <x-card>
        <x-slot name="title">
            {{ __('Browser Sessions') }}
        </x-slot>

        <x-slot name="action">
            <button onclick="location.reload();" class="cursor-pointer ml-4">
                <x-refresh-button/>
            </button>
        </x-slot>

        <x-slot name="content">
            <div class="max-w-xl text-sm text-gray-600 dark:text-gray-400">
                {{ __('Manage and log out your active sessions on other browsers and devices.') }}
            </div>

            @if (count($sessions) > 0)
                <div class="mt-2 space-y-6">
                    @foreach ($sessions as $session)
                        <div class="flex justify-between">
                            <div class="flex items-center">
                                <div>
                                    @if ($session->agent->isDesktop())
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-gray-500">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                                        </svg>
                                    @else
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-8 text-gray-500">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                                        </svg>
                                    @endif
                                </div>
                                
                                <div class="ms-3">
                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                        {{ $session->agent->platform() ? $session->agent->platform() : __('Unknown') }} - {{ $session->agent->browser() ? $session->agent->browser() : __('Unknown') }}
                                    </div>
                                    
                                    <div>
                                        <div class="text-xs text-gray-500">
                                            {{ $session->ip_address }},
                                            
                                            @if ($session->is_current_device)
                                            <span class="text-green-500 font-semibold">{{ __('This device') }}</span>
                                            @else
                                            {{ __('Last active') }} {{ $session->last_active }}
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @if (!$session->is_current_device)
                                <form method="POST" action="{{ route('logout.sessions') }}">
                                    @csrf
                                    <input type="hidden" name="session_id" value="{{ $session->id }}">
                                    <button class="cursor-pointer" @click.prevent="$refs.logoutOtherSessionsForm.submit()">
                                        <svg class="h-6 w-6 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1m0-10V5m0 0a2 2 0 00-2-2H6a2 2 0 00-2 2v14a2 2 0 002 2h5a2 2 0 002-2v-1" />
                                        </svg>
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif

            <div x-data="{ show: false }">
                <x-button @click="show = true">
                    {{ __('Log Out Other Browser Sessions') }}
                </x-button>

                <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                    <x-slot name="title">
                        {{ __('Log Out Other Browser Sessions') }}
                    </x-slot>
                    
                    <x-slot name="content">
                        {{ __('Please enter your password to confirm you would like to log out of your other browser sessions across all of your devices.') }}
                        
                        <form method="POST" action="{{ route('logout.sessions') }}" x-ref="logoutOtherSessionsForm">
                            @csrf

                            <div class="mt-4">
                                <x-input type="password"
                                    name="password"
                                    class="mt-1 block w-3/4"
                                    autocomplete="current-password"
                                    placeholder="{{ __('Password') }}"
                                    x-ref="password" />
                            </div>
                        </form>
                    </x-slot>

                    <x-slot name="footer">
                        <x-secondary-button @click="show = false">
                            {{ __('Cancel') }}
                        </x-secondary-button>

                        <x-button class="ms-3" @click.prevent="$refs.logoutOtherSessionsForm.submit()">
                            {{ __('Log Out Other Browser Sessions') }}
                        </x-button>
                    </x-slot>
                </x-dialog-modal>
            </div>
        </x-slot>
    </x-card>
</x-app>