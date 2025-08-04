<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <div class="flex flex-col gap-4">
        {{-- Change Password --}}
        <x-card x-data="{changePasswordOpen: false}">
            {{-- title --}}
            <x-slot name="title">
                {{ __('Change Password') }}
            </x-slot>
            
            {{-- Action --}}
            <x-slot name="action" class="flex">
                <div>
                    <div x-show="!changePasswordOpen" x-on:click="changePasswordOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                        </svg>
                    </div>

                    <div x-show="changePasswordOpen" x-on:click="changePasswordOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
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
                        <x-label for="current-password" value="{{ __('Current Password') }}" class="w-1/3" />
                        <x-input id="current-password" class="block w-5/6" type="password" name="current_password" required autocomplete="current-password" />
                    </div>

                    <div class="flex gap-4 justify-between items-center w-2/3">
                        <x-label for="password" value="{{ __('New Password') }}" class="w-1/3" />
                        <x-input id="password" class="block w-5/6" type="password" name="password" required autocomplete="off" />
                    </div>

                    <div class="flex gap-4 justify-between items-center w-2/3">
                        <x-label for="password_confirmation" value="{{ __('New Confirm Password') }}" class="w-1/3" />
                        <x-input id="password_confirmation" class="block w-5/6" type="password" name="password_confirmation" required autocomplete="off" />
                    </div>

                    <div class="relative flex items-center justify-end">
                        <x-button>
                            {{ __('Update Password') }}
                        </x-button>
                    </div>
                </form>
            </x-slot>
        </x-card>
        
        <x-card>
            <x-slot name="title">
                {{ __('Two Factor Authentication') }}
            </x-slot>
            <x-slot name="content">
                
            </x-slot>
        </x-card>
        
        <x-card>
            <x-slot name="title">
                {{ __('Passkeys') }}
            </x-slot>
            <x-slot name="content">
                
            </x-slot>
        </x-card>
        
        <x-card class="border border-red-600">
            <x-slot name="title">
                <p class="text-red-600">{{__('Danger Zone')}}</p>
            </x-slot>
            <x-slot name="content">
                <div x-data="{ show: false }">
                    <div class="flex justify-between gap-2">
                        <div>
                            <p class="font-medium text-lg">Delete Account</p>
                            <p class="text-sm">Once deleted, it will be gone forever. Please be certain.</p>
                        </div>
                        <x-danger-button class="cursor-pointer h-10" @click="show = true">
                            {{ __('Delete Account') }}
                        </x-danger-button>
                    </div>

                    <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                        <x-slot name="title">
                            {{ __('Delete Account') }}
                        </x-slot>
                        
                        <x-slot name="content">
                            {{ __('Please enter your password to confirm you would like to delete of your account.') }}
                            
                            <form method="POST" action="{{ route('account.delete') }}" x-ref="deleteAccountForm">
                                @csrf
                                @method('DELETE')
                                <div class="mt-4">
                                    <x-input type="password"
                                        name="password"
                                        class="mt-1 block w-3/4"
                                        autocomplete="password"
                                        placeholder="{{ __('Password') }}"
                                        x-ref="password" />
                                </div>
                            </form>
                        </x-slot>

                        <x-slot name="footer">
                            <x-secondary-button class="cursor-pointer" @click="show = false">
                                {{ __('Cancel') }}
                            </x-secondary-button>

                            <x-danger-button type="submit" class="ms-3 cursor-pointer" @click.prevent="$refs.deleteAccountForm.submit()">
                                {{ __('Delete Account') }}
                            </x-danger-button>
                        </x-slot>
                    </x-dialog-modal>
                </div>
            </x-slot>
        </x-card>
    </div>
</x-app>