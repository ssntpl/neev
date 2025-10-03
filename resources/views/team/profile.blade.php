<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    <div  x-data="{ editing: false }">
        <x-neev-component::card>
            <x-slot name="title">
                {{__('Team Information')}}
            </x-slot>

            <x-slot name="action">
                @if ($user->id === $team->user_id)
                    <div class="flex items-center gap-4">
                        <div x-show="!editing" class="flex gap-4">
                            <x-neev-component::button x-on:click="editing = true">
                                {{ __('Edit') }}
                            </x-neev-component::button>
                        </div>

                        <div x-show="editing" class="flex gap-4">
                            <x-neev-component::button form="updateTeamForm">
                                {{ __('Save') }}
                            </x-neev-component::button>
                            <x-neev-component::button x-on:click="editing = false">
                                {{ __('Cancel') }}
                            </x-neev-component::button>
                        </div>
                    </div>
                @endif
            </x-slot>

            <x-slot name="content">
                <div class="flex flex-col gap-8">
                    <form id="updateTeamForm" class="flex flex-col gap-4" method="POST" action="{{ route('teams.update') }}">
                        @csrf
                        @method('PUT')

                        <input type="hidden" name="team_id" value="{{ $team->id }}">
                        <div class="items-center">
                            <p x-show="!editing" data-field="name">{{ $team->name }}</p>
                            <input x-show="editing" class="w-1/3 text-center border px-2 py-1 rounded-md" type="text" name="name" value="{{ $team->name }}">
                        </div>
                        
                        <div class="flex justify-between items-center">
                            <label for="public" class="flex items-center">
                                <x-neev-component::checkbox x-bind:disabled="!editing" id="public" name="public" :checked="$team->is_public" />
                                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Public Team') }}</span>
                            </label>
                        </div>
                    </form>

                    <div class="flex flex-col gap-4">
                        <h1 class="font-bold">Owner</h1>
                        <div class="flex gap-4">
                            <div class="w-12 h-12 bg-blue-100 text-blue-500 text-2xl rounded-full flex items-center justify-center font-medium">
                                {{ $team->owner->profile_photo_url }}
                            </div>
                            <div>
                                <p class="text-md">{{$team->owner->name}}</p>
                                <p class="text-sm">{{$team->owner->email->email}}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </x-slot>
        </x-neev-component::card>
    </div>
</x-neev-layout::app>
