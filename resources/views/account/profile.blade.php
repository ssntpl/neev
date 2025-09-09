<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::card x-data="{edit: false}">
        <x-slot name="title">
            User Information
        </x-slot>
        <x-slot name="action">
            <div class="flex gap-4 items-center">
                @if (session('status') == 'verification-link-sent')
                    <div class="items-center text-sm text-green-600 dark:text-green-400">
                        {{ __('Verification link has been sent.') }}
                    </div>
                @elseif (session('status'))
                    <div class="items-center text-sm text-green-600 dark:text-green-400">
                        {{ __(session('status')) }}
                    </div>
                @endif

                <x-neev-component::button x-show="!edit" id="editButton" @click="edit = true">
                    {{ __('Edit') }}
                </x-neev-component::button>
            </div>
            <div x-show="edit" class="save-profile flex gap-4">
                <x-neev-component::button id="saveButton" type="submit" form="updateProfileForm">
                    {{ __('Save') }}
                </x-neev-component::button>
                <x-neev-component::secondary-button id="cancelButton" @click="edit = false">
                    {{ __('Cancel') }}
                </x-neev-component::secondary-button>
            </div>
        </x-slot>
        <x-slot name="content" >
            <form id="updateProfileForm" method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PUT')

                <div id="userInfo" class="flex flex-col gap-4">
                    <div class="flex justify-between items-center">
                        <label class="font-medium">Name</label>
                        <p x-show="!edit" data-field="name">{{ $user->name }}</p>
                        <input x-show="edit" class="w-1/3 text-center border px-2 py-1 rounded-md" type="text" name="name" value="{{ $user->name }}">
                    </div>
                    @if (config('neev.support_username'))
                        <div class="flex justify-between items-center">
                            <label class="font-medium">Username</label>
                            <p x-show="!edit" data-field="username">{{ $user->username }}</p>
                            <input x-show="edit" class="w-1/3 text-center border px-2 py-1 rounded-md" type="text" name="username" value="{{ $user->username }}">
                        </div>
                    @endif
                </div>
            </form>
       </x-slot>
    </x-neev-component::card>
</x-neev-layout::app>