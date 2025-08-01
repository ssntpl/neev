<x-app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>

    <div class="flex flex-col gap-4">
        <x-card>
           <x-slot name="title">
                {{__('Settings')}}
           </x-slot>
           
           <x-slot name="action">
                @session('status')
                    <div class="text-green-600">
                        {{ session('status') }}
                    </div>
                @endsession

                <x-input-error for="message"/>
           </x-slot>
           
           <x-slot name="content">

           </x-slot>
        </x-card>

        <x-card class="border border-red-600">
            <x-slot name="title">
                <p class="text-red-600">{{__('Danger Zone')}}</p>
            </x-slot>
            <x-slot name="content">
                @if ($team->user_id === $user->id)
                    <x-input-error for="message"/>
                    {{-- Change Owner --}}
                    <div x-data="{ show: false, user_id: @js($team->user_id), owner_id: @js($team->user_id) }">
                        <div class="flex justify-between items-center gap-2">
                            <div>
                                <p class="font-medium text-lg">Change Ownership</p>
                            </div>
                            <x-danger-button class="cursor-pointer h-10" @click="show = true">
                                {{ __('Change Owner') }}
                            </x-danger-button>
                        </div>

                        <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                            <x-slot name="title">
                                {{ __('Change Owner') }}
                            </x-slot>
                            
                            <x-slot name="content">
                                <form method="POST" action="{{ route('teams.owner.change') }}" x-ref="changeOwnerForm" @keydown.enter.prevent="if (user_id !== owner_id) { $refs.changeOwnerForm.submit() }">
                                    @csrf
                                    @method('PUT')

                                    <input type="hidden" name="team_id" value="{{ $team->id }}">
                                    <div class="mt-4 flex gap-2 items-center w-2/3">
                                        <x-label for="user_id" value="{{ __('Member') }}" />
                                        <select x-model="user_id" name="user_id" id="user_id" class="w-full border rounded-md p-2">
                                            @foreach ($team->users as $member)
                                                <option value="{{$member->id}}" x-bind:selected={{$member->id === $team->owner->id}}>{{$member->name}}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </form>
                            </x-slot>

                            <x-slot name="footer">
                                <x-secondary-button @click="show = false">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-button type="submit" x-bind:disabled="user_id === owner_id" class="ms-3" @click.prevent="$refs.changeOwnerForm.submit()">
                                    {{ __('Make Owner') }}
                                </x-button>
                            </x-slot>
                        </x-dialog-modal>
                    </div>

                    <div class="py-1">
                        <div class="border-t border-gray-200 dark:border-gray-700"></div>
                    </div>

                    {{-- Delete Team --}}
                    <div x-data="{ show: false, inputName: '', teamName: @js($team->name) }">
                        <div class="flex justify-between items-center gap-2">
                            <div>
                                <p class="font-medium text-lg">Delete Team</p>
                                <p class="text-sm">Once deleted, it will be gone forever. Please be certain.</p>
                            </div>
                            <x-danger-button class="cursor-pointer h-10" @click="show = true">
                                {{ __('Delete Team') }}
                            </x-danger-button>
                        </div>

                        <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                            <x-slot name="title">
                                {{ __('Delete Team') }}
                            </x-slot>
                            
                            <x-slot name="content">
                                {{ __('Please enter team name to confirm you would like to delete of this team.') }}
                                
                                <form method="POST" action="{{ route('teams.delete') }}" x-ref="deleteTeamForm" @keydown.enter.prevent="if (inputName === teamName) { $refs.deleteTeamForm.submit() }">
                                    @csrf
                                    @method('DELETE')

                                    <input type="hidden" name="team_id" value="{{ $team->id }}">
                                    <div class="mt-4">
                                        <x-input type="text"
                                            name="name"
                                            class="mt-1 block w-3/4"
                                            placeholder="{{ __('Team Name') }}"
                                            x-model="inputName" />
                                    </div>
                                </form>
                            </x-slot>

                            <x-slot name="footer">
                                <x-secondary-button @click="show = false">
                                    {{ __('Cancel') }}
                                </x-secondary-button>

                                <x-danger-button type="submit" x-bind:disabled="inputName !== teamName" class="ms-3" @click.prevent="$refs.deleteTeamForm.submit()">
                                    {{ __('Delete Team') }}
                                </x-danger-button>
                            </x-slot>
                        </x-dialog-modal>
                    </div>
                @endif
            </x-slot>
        </x-card>
    </div>
</x-app>
