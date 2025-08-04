<x-app>
    <div class="w-1/3 flex justify-self-center items-center">
        <x-validation-errors class="mb-4" />
        <x-validation-status class="mb-4" />
        <x-card>
            <x-slot name="title">
                {{__('Create New Team')}}
            </x-slot>

            <x-slot name="content">
                <form class="flex flex-col gap-4" method="POST" action="{{ route('teams.store') }}">
                    @csrf

                    <div>
                        <x-label for="name" value="{{ __('Team Name') }}" />
                        <x-input id="name" class="block mt-1 w-full" type="text" name="name" required autofocus />
                    </div>
                    <div class="flex gap-4 justify-between">
                        <label for="public" class="flex items-center">
                            <x-checkbox id="public" name="public" />
                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Public Team') }}</span>
                        </label>
                        <x-button>{{__('Create Team')}}</x-button>
                    </div>
                </form>
            </x-slot>
        </x-card>
    </div>
</x-app>