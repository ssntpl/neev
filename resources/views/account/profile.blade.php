<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-neev::card>
        <x-slot name="title">
            User Information
        </x-slot>
        <x-slot name="action" class="flex">
            <div class="edit-profile flex gap-4">
                @if (session('status') == 'verification-link-sent')
                    <div class="items-center text-sm text-green-600 dark:text-green-400">
                        {{ __('Verification link has been sent.') }}
                    </div>
                @endif
                @if (!$user['email_verified_at'])
                    <form method="GET" action="{{ route('verification.send') }}">
                        @csrf

                        <div>
                            <x-button type="submit">
                                {{ __('Verify Email') }}
                            </x-button>
                        </div>
                    </form>
                @endif
                <x-button id="editButton">
                    {{ __('Edit') }}
                </x-button>
            </div>
            <div class="save-profile flex gap-4 hidden">
                <x-button id="saveButton" type="submit" form="updateProfileForm">
                    {{ __('Save') }}
                </x-button>
                <x-secondary-button id="cancelButton">
                    {{ __('Cancel') }}
                </x-secondary-button>
            </div>
        </x-slot>
        <x-slot name="content" >
            <form id="updateProfileForm" method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PUT')

                <div id="userInfo">
                    <div class="flex justify-between items-center">
                        <label class="font-medium">Name</label>
                        <p data-field="name">{{ $user['name'] }}</p>
                        <input class="w-1/3 text-center hidden border px-2 py-1 rounded-md" type="text" name="name" value="{{ $user['name'] }}">
                    </div>

                    <div class="flex justify-between items-center mt-4">
                        <label class="font-medium">Email</label>
                        <div class="flex gap-1 items-center email-display">
                            <p data-field="email">{{ $user['email'] }}</p>
                            <span id="verifyBadge" class="inline-flex items-center justify-center h-6 w-6 rounded-full {{$user['email_verified_at'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'}}">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    @if ($user['email_verified_at'])
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    @else
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    @endif
                                </svg>
                            </span>
                        </div>
                        <input class="w-1/3 text-center hidden border px-2 py-1 rounded-md" type="email" name="email" value="{{ $user['email'] }}">
                    </div>
                </div>
            </form>
       </x-slot>
    </x-neev::card>
</x-app>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const editBtn = document.getElementById('editButton');
        const saveSection = document.querySelector('.save-profile');
        const editSection = document.querySelector('.edit-profile');
        const cancelBtn = document.getElementById('cancelButton');
        const emailDisplay = document.querySelector('.email-display');

        const inputs = document.querySelectorAll('#userInfo input');
        const displays = document.querySelectorAll('#userInfo p');

        // Save original values to restore on cancel
        const originalValues = {};
        inputs.forEach(input => {
            originalValues[input.name] = input.value;
        });

        editBtn.addEventListener('click', () => {
            inputs.forEach(input => input.classList.remove('hidden'));
            displays.forEach(p => p.classList.add('hidden'));
            saveSection.classList.remove('hidden');
            editSection.classList.add('hidden');
            emailDisplay.classList.add('hidden');
        });

        cancelBtn.addEventListener('click', () => {
            inputs.forEach(input => {
                input.classList.add('hidden');
                input.value = originalValues[input.name];
            });
            displays.forEach(p => {
                p.classList.remove('hidden');
                p.textContent = originalValues[p.dataset.field];
            });
            saveSection.classList.add('hidden');
            editSection.classList.remove('hidden');
            emailDisplay.classList.remove('hidden');
        });
    });
</script>