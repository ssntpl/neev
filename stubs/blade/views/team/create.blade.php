<x-neev-layout::app>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    <div class="w-1/3 flex justify-self-center items-center">
        <x-neev-component::card>
            <x-slot name="title">
                {{__('Create New Team')}}
            </x-slot>

            <x-slot name="content">
                <form class="flex flex-col gap-4" method="POST" action="{{ route('teams.store') }}">
                    @csrf

                    <div>
                        <x-neev-component::label for="name" value="{{ __('Team Name') }}" />
                        <x-neev-component::input id="name" class="block mt-1 w-full" type="text" name="name" required autofocus />
                    </div>
                    <div class="flex gap-4 justify-between">
                        <label for="public" class="flex items-center">
                            <x-neev-component::checkbox id="public" name="public" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Public Team') }}</span>
                        </label>
                        <x-neev-component::button>{{__('Create Team')}}</x-neev-component::button>
                    </div>
                </form>
            </x-slot>
        </x-neev-component::card>
    </div>
</x-neev-layout::app>