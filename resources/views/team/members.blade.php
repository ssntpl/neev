<x-app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>

    <div class="flex flex-col gap-4" x-data="{ showForm: false }">
        @if ($team->user_id === $user->id)
            <x-card>
                <x-slot name="title">
                    {{__('Add Member')}}
                </x-slot>
                <x-slot name="action">
                    @session('status')
                        <div class="text-green-600">
                            {{ session('status') }}
                        </div>
                    @endsession

                    <x-input-error for="message"/>

                    <div>
                        <x-button x-show="!showForm" x-on:click="showForm = true">
                            {{ __('Add') }}
                        </x-button>

                        <x-secondary-button x-show="showForm" x-on:click="showForm = false">
                            {{ __('Close') }}
                        </x-secondary-button>
                    </div>
                </x-slot>

                <x-slot name="content">
                    <div x-show="showForm" x-transition>
                        <form method="POST" class="flex gap-2 mx-4 items-center justify-between" action="{{ route('teams.invite') }}">
                            @csrf
                            @method('PUT')

                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <div class="flex gap-4 justify-between w-full">
                                <div class="flex gap-2 items-center w-2/3">
                                    <x-label for="email" value="{{ __('Email') }}" />
                                    <x-input id="email" class="block w-full" type="email" name="email" required autofocus />
                                </div>
                                @if (config('neev.roles'))
                                    <div class="flex gap-2 items-center w-1/3">
                                        <x-label for="role_id" value="{{ __('Role') }}" />
                                        <select name="role_id" id="role_id" class="w-full border rounded-md p-2">
                                            @foreach ($team->roles as $role)
                                                <option value="{{$role->id}}">{{$role->name}}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                @endif
                            </div>
                            <div class="w-1/2 text-end">
                                <x-button>
                                    {{__('Invite')}}
                                </x-button>
                            </div>
                        </form>
                    </div>
                </x-slot>
            </x-card>
        @endif

        <x-card>
            <x-slot name="title">
                {{__('Members')}}
            </x-slot>
            <x-slot name="action">
                <span>
                    {{__('Total Members')}}
                    <span class="font-bold">{{ __(count($team->allUsers)) }}</span>
                </span>

                <button onclick="location.reload();" class="cursor-pointer ml-4">
                    <x-refresh-button/>
                </button>
            </x-slot>

            <x-slot name="content">
                
                {{-- Join Requests --}}
                @if (count($team->joinRequests) > 0 && $team->owner->id === $user->id)
                    <div class="flex justify-between">
                        <h1 class="font-bold">{{__('Join Requests')}}</h1>
                        <span>
                            {{__('Total Requests')}}
                            <span class="font-bold">{{ __(count($team->joinRequests)) }}</span>
                        </span>
                    </div>
                    <x-table>
                        <x-slot name="body">
                            @foreach ($team->joinRequests as $member)
                            <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                    <form method="POST" action="{{ route('teams.request.action') }}">
                                         @csrf
                                        @method('PUT')
                                        <td class="flex gap-2 px-4 py-2">
                                            <div class="w-10 h-10 bg-blue-100 text-blue-500 text-xl rounded-full flex items-center justify-center font-medium">
                                                {{ $member->profile_photo_url }}
                                            </div>
                                            <div>
                                                <p class="text-md">{{$member->name}}</p>
                                                <p class="text-sm">{{$member->email}}</p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-center capitalize">
                                            @if (config('neev.roles'))
                                                <div class="flex gap-2 items-center">
                                                    <x-label for="role_id" value="{{ __('Role') }}" />
                                                    <select name="role_id" id="role_id" class="w-full border rounded-md p-2">
                                                        @foreach ($team->roles as $role)
                                                            <option value="{{$role->id}}">{{$role->name}}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <div class="flex gap-4 justify-end">
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                <x-button name="action" value="accept">
                                                    {{ __('Accept') }}
                                                </x-button>
                                                <x-danger-button type="submit" name="action" value="reject">
                                                    {{ __('Reject') }}
                                                </x-danger-button>
                                            </div>
                                        </td>
                                    </form>
                                </x-table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-table>
                @endif

                {{-- Team Members --}}
                @if (count($team->users) > 0)
                    <div class="flex justify-between">
                        <h1 class="font-bold">{{__('Team Members')}}</h1>
                        <span>
                            {{__('Total Members')}}
                            <span class="font-bold">{{ __(count($team->users)) }}</span>
                        </span>
                    </div>
                    <x-table>
                        <x-slot name="body">
                            @foreach ($team->users as $member)
                                <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                    <td class="flex gap-2 px-4 py-2">
                                        <div class="w-10 h-10 bg-blue-100 text-blue-500 text-xl rounded-full flex items-center justify-center font-medium">
                                            {{ $member->profile_photo_url }}
                                        </div>
                                        <div>
                                            <p class="text-md">{{$member->name}}</p>
                                            <p class="text-sm">{{$member->email}}</p>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2 text-center capitalize">
                                        @if ($team->user_id === $user->id)
                                            <div class="text-start" x-data="{ show: false, roleId: @js($member->membership->role->id ?? 0), userRoleId: @js($member->membership->role->id ?? 1) }">
                                                <button class="capitalize underline cursor-pointer" @click="show = true">{{ $member->membership->role->name ?? '--'}}</button>
                                                <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                    <x-slot name="title">
                                                        {{ __('Change Role') }}
                                                    </x-slot>
                                                    
                                                    <x-slot name="content">
                                                        <p>{{$member->name}}</p>
                                                        <form method="POST" action="{{ route('teams.roles.change') }}" x-ref="changeRoleForm" @keydown.enter.prevent="if (roleId !== userRoleId) { $refs.changeRoleForm.submit() }">
                                                            @csrf
                                                            @method('PUT')

                                                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                            <div class="mt-4 flex gap-2 items-center w-2/3">
                                                                <x-label for="role_id" value="{{ __('Role') }}" />
                                                                <select name="role_id" id="role_id" x-model="roleId" class="w-full border rounded-md p-2">
                                                                    @foreach ($team->roles as $role)
                                                                        <option value="{{$role->id}}" x-bind:selected="{{($member->membership->role->id ?? 0) === $role->id}}">{{$role->name}}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                        </form>
                                                    </x-slot>

                                                    <x-slot name="footer">
                                                        <x-secondary-button @click="show = false">
                                                            {{ __('Cancel') }}
                                                        </x-secondary-button>

                                                        <x-button type="submit" x-bind:disabled="roleId === userRoleId" class="ms-3" @click.prevent="$refs.changeRoleForm.submit()">
                                                            {{ __('Change Role') }}
                                                        </x-button>
                                                    </x-slot>
                                                </x-dialog-modal>
                                            </div>
                                        @else
                                            {{ $member->membership->role->name ?? '' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center capitalize">
                                        {{ $team->owner->id === $member->id ? 'owner' : 'member' }}
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        @if ($member->membership->joined)
                                            <p>Added {{ $member->membership->created_at->diffForHumans() }}</p>
                                        @else
                                            <form method="POST" action="{{route('teams.invite')}}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="email" value="{{ $member->email }}">
                                                <x-button>{{__('Invite')}}</x-button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                            @csrf
                                            @method('DELETE')

                                            <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                            <input type="hidden" name="user_id" value="{{ $member->id }}"/>
                                            <x-danger-button
                                                type="submit"
                                                class="w-2/3" 
                                                x-bind:disabled="{{$team->user_id === $member->id}}"
                                                @click.prevent="if (confirm('{{ $user->id === $member->id ? __('Are you sure you want to leave the team?') : __('Are you sure you want to remove user from the team?') }}')) $el.closest('form').submit();">
                                                {{ $user->id === $member->id ? __('Leave') : __('Remove') }}
                                            </x-danger-button>
                                        </form>
                                    </td>
                                </x-table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-table>
                @endif

                {{-- Invited Users --}}
                @if (count($team->invitedUsers) > 0)
                    <div class="flex justify-between">
                        <h1 class="font-bold">{{__('Invited Members')}}</h1>
                        <span>
                            {{__('Total Invited')}}
                            <span class="font-bold">{{ __(count($team->invitedUsers)) }}</span>
                        </span>
                    </div>
                    <x-table>
                        <x-slot name="body">
                            @foreach ($team->invitedUsers as $member)
                                <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                    <td class="flex gap-2 px-4 py-2">
                                        <div class="w-10 h-10 bg-blue-100 text-blue-500 text-xl rounded-full flex items-center justify-center font-medium">
                                            {{ $member->profile_photo_url }}
                                        </div>
                                        <div>
                                            <p class="text-md">{{$member->name}}</p>
                                            <p class="text-sm">{{$member->email}}</p>
                                        </div>
                                    </td>
                                    @if ($team->owner->id === $user->id)
                                        <td class="px-4 py-2 text-center capitalize">
                                            <div class="text-start" x-data="{ show: false, roleId: @js($member->membership->role->id ?? 0), userRoleId: @js($member->membership->role->id ?? 1) }">
                                                <button class="capitalize underline cursor-pointer" @click="show = true">{{ $member->membership->role->name ?? '--'}}</button>
                                                <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                    <x-slot name="title">
                                                        {{ __('Change Role') }}
                                                    </x-slot>
                                                    
                                                    <x-slot name="content">
                                                        <p>{{$member->name}}</p>
                                                        <form method="POST" action="{{ route('teams.roles.change') }}" x-ref="changeRoleForm" @keydown.enter.prevent="if (roleId !== userRoleId) { $refs.changeRoleForm.submit() }">
                                                            @csrf
                                                            @method('PUT')

                                                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                            <div class="mt-4 flex gap-2 items-center w-2/3">
                                                                <x-label for="role_id" value="{{ __('Role') }}" />
                                                                <select name="role_id" id="role_id" x-model="roleId" class="w-full border rounded-md p-2">
                                                                    @foreach ($team->roles as $role)
                                                                        <option value="{{$role->id}}" x-bind:selected="{{($member->membership->role->id ?? 0) === $role->id}}">{{$role->name}}</option>
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                        </form>
                                                    </x-slot>

                                                    <x-slot name="footer">
                                                        <x-secondary-button @click="show = false">
                                                            {{ __('Cancel') }}
                                                        </x-secondary-button>

                                                        <x-button type="submit" x-bind:disabled="roleId === userRoleId" class="ms-3" @click.prevent="$refs.changeRoleForm.submit()">
                                                            {{ __('Change Role') }}
                                                        </x-button>
                                                    </x-slot>
                                                </x-dialog-modal>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{route('teams.invite')}}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="email" value="{{ $member->email }}">
                                                <x-button>{{__('Invite')}}</x-button>
                                            </form>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                                @csrf
                                                @method('DELETE')
                                                
                                                <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                                <input type="hidden" name="user_id" value="{{ $member->id }}"/>
                                                <x-danger-button
                                                    type="submit"
                                                    class="w-2/3" 
                                                    @click.prevent="if (confirm('{{ __('Are you sure you want to remove user from the team?') }}')) $el.closest('form').submit();">
                                                    {{ __('Remove') }}
                                                </x-danger-button>
                                            </form>
                                        </td>
                                    @else
                                        <td class="px-4 py-2 text-center capitalize">
                                            {{ $member->membership->role->name ?? ''}}
                                        </td>
                                    @endif
                                </x-table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-table>
                @endif
            </x-slot>
        </x-card>
    </div>
</x-app>
