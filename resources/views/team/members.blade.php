<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    <div class="flex flex-col gap-4">
        @if ($team->user_id === $user->id)
            <x-neev-component::card x-data="{memberOpen: false}">
                <x-slot name="title">
                    {{__('Add Member')}}
                </x-slot>
                <x-slot name="action">
                    <div>
                        <div x-show="!memberOpen" x-on:click="memberOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                            </svg>
                        </div>

                        <div x-show="memberOpen" x-on:click="memberOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                </x-slot>

                <x-slot name="content">
                    <div x-show="memberOpen" x-transition>
                        <form method="POST" class="flex gap-2 mx-4 items-center justify-between" action="{{ route('teams.invite') }}">
                            @csrf
                            @method('PUT')

                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <div class="flex gap-4 justify-between w-full">
                                <div class="flex gap-2 items-center w-2/3">
                                    <x-neev-component::label for="email" value="{{ __('Email') }}" />
                                    <x-neev-component::input id="email" class="block w-full" type="email" name="email" required autofocus />
                                </div>
                                <div class="flex gap-2 items-center w-1/3">
                                    <x-neev-component::label for="role" value="{{ __('Role') }}" />
                                    <select name="role" id="role" class="w-full border rounded-md p-2">
                                        @foreach ($team->roles() ?? [] as $role)
                                            @if (config('neev.roles'))
                                                <option value="{{$role->name}}">{{$role->name}}</option>
                                            @else
                                                <option value="{{$role}}">{{$role}}</option>
                                            @endif
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                            <div class="w-1/2 text-end">
                                <x-neev-component::button>
                                    {{__('Invite')}}
                                </x-neev-component::button>
                            </div>
                        </form>
                    </div>
                </x-slot>
            </x-neev-component::card>
        @endif

        <x-neev-component::card>
            <x-slot name="title">
                {{__('Members')}}
            </x-slot>
            <x-slot name="action">
                <span>
                    {{__('Total Members')}}
                    <span class="font-bold">{{ __(count($team->allUsers)) }}</span>
                </span>

                <button onclick="location.reload();" class="cursor-pointer ml-4">
                    <x-neev-component::refresh-button/>
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
                    <x-neev-component::table>
                        <x-slot name="body">
                            @foreach ($team->joinRequests as $member)
                            <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
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
                                            <div class="flex gap-2 items-center">
                                                <x-neev-component::label for="role" value="{{ __('Role') }}" />
                                                <select name="role" id="role" class="w-full border rounded-md p-2">
                                                    @foreach ($team->roles() as $role)
                                                        @if (config('neev.roles'))
                                                            <option value="{{$role->name}}">{{$role->name}}</option>
                                                        @else
                                                            <option value="{{$role}}">{{$role}}</option>
                                                        @endif
                                                    @endforeach
                                                </select>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <div class="flex gap-4 justify-end">
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                @if (config('neev.roles'))
                                                    <input type="hidden" name="resource_type" value="{{ class_basename(Ssntpl\Neev\Models\Team::class) }}">
                                                @endif
                                                <x-neev-component::button name="action" value="accept">
                                                    {{ __('Accept') }}
                                                </x-neev-component::button>
                                                <x-neev-component::danger-button type="submit" name="action" value="reject">
                                                    {{ __('Reject') }}
                                                </x-neev-component::danger-button>
                                            </div>
                                        </td>
                                    </form>
                                </x-neev-component::table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-neev-component::table>
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
                    <x-neev-component::table>
                        <x-slot name="body">
                            @foreach ($team->users as $member)
                                <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50 {{$team->enforce_domain && $team->domain_verified_at && !str_ends_with(strtolower($member->email), '@' . strtolower($team->federated_domain)) ? 'text-red-400' : ''}}">
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
                                            <div class="text-start" x-data="{ show: false, role: @js($member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '')), userRole: @js($member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '')) }">
                                                <button class="capitalize underline cursor-pointer" @click="show = true">{{ $member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '--')}}</button>
                                                <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                    <x-slot name="title">
                                                        {{ __('Change Role') }}
                                                    </x-slot>
                                                    
                                                    <x-slot name="content">
                                                        <p>{{$member->name}}</p>
                                                        <form method="POST" action="{{ route('teams.roles.change') }}" x-ref="changeRoleForm" @keydown.enter.prevent="if (role !== userRole) { $refs.changeRoleForm.submit() }">
                                                            @csrf
                                                            @method('PUT')

                                                            <input type="hidden" name="resource_id" value="{{ $team->id }}">
                                                            <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                            @if (config('neev.roles'))
                                                                <input type="hidden" name="resource_type" value="{{ class_basename(Ssntpl\Neev\Models\Team::class) }}">
                                                            @endif
                                                            <div class="mt-4 flex gap-2 items-center w-2/3">
                                                                <x-neev-component::label for="role" value="{{ __('Role') }}" />
                                                                <select name="role" id="role" x-model="role" class="w-full border rounded-md p-2">
                                                                    @foreach ($team->roles() as $role)
                                                                        @if (config('neev.roles'))
                                                                            <option value="{{$role->name}}" x-bind:selected="role === '{{$role->name}}'">{{$role->name}}</option>
                                                                        @else
                                                                            <option value="{{$role}}" x-bind:selected="role === '{{$role}}'">{{$role}}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                        </form>
                                                    </x-slot>

                                                    <x-slot name="footer">
                                                        <x-neev-component::secondary-button @click="show = false">
                                                            {{ __('Cancel') }}
                                                        </x-neev-component::secondary-button>

                                                        <x-neev-component::button type="submit" x-bind:disabled="role === userRole" class="ms-3" @click.prevent="$refs.changeRoleForm.submit()">
                                                            {{ __('Change Role') }}
                                                        </x-neev-component::button>
                                                    </x-slot>
                                                </x-neev-component::dialog-modal>
                                            </div>
                                        @else
                                            {{ $member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '') }}
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
                                                <x-neev-component::button>{{__('Invite')}}</x-neev-component::button>
                                            </form>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                            @csrf
                                            @method('DELETE')

                                            <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                            <input type="hidden" name="user_id" value="{{ $member->id }}"/>
                                            @if (!$member->active && $team->domain_verified_at && $user->id !== $member->id)
                                                <x-neev-component::button
                                                    type="submit"
                                                    class="w-2/3" 
                                                    x-bind:disabled="{{$team->user_id === $member->id || ($team->user_id !== $user->id && $user->id !== $member->id) || ($team->domain_verified_at && $member->id === $user->id)}}"
                                                    @click.prevent="if (confirm('{{ __('Are you sure you want to activate the user?') }}')) $el.closest('form').submit();">
                                                    {{ __('Activate') }}
                                                </x-neev-component::button>
                                            @else
                                                <x-neev-component::danger-button
                                                    type="submit"
                                                    class="w-2/3" 
                                                    x-bind:disabled="{{$team->user_id === $member->id || ($team->user_id !== $user->id && $user->id !== $member->id) || ($team->domain_verified_at && $member->id === $user->id)}}"
                                                    @click.prevent="if (confirm('{{ $user->id === $member->id ? __('Are you sure you want to leave the team?') : __('Are you sure you want to remove/deactivate user from the team?') }}')) $el.closest('form').submit();">
                                                    {{ $user->id === $member->id ? __('Leave') : ($team->domain_verified_at && str_ends_with(strtolower($member->email), '@' . strtolower($team->federated_domain)) ? __('Deactivate') : __('Remove')) }}
                                                </x-neev-component::danger-button>
                                            @endif
                                        </form>
                                    </td>
                                </x-neev-component::table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-neev-component::table>
                @endif

                {{-- Invited Users --}}
                @if (count($team->invitedUsers) > 0 || $team->invitations->count() > 0)
                    <div class="flex justify-between">
                        <h1 class="font-bold">{{__('Invited Members')}}</h1>
                        <span>
                            {{__('Total Invited')}}
                            <span class="font-bold">{{ __(count($team->invitedUsers) + count($team->invitations)) }}</span>
                        </span>
                    </div>
                    <x-neev-component::table>
                        <x-slot name="body">
                            @foreach ($team->invitedUsers as $member)
                                <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
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
                                        @if (config('neev.roles'))
                                            <td class="px-4 py-2 text-center capitalize">
                                                <div class="text-start" x-data="{ show: false, role: @js($member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '')), userRole: @js($member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '')) }">
                                                    <button class="capitalize underline cursor-pointer" @click="show = true">{{ $member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '--')}}</button>
                                                    <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                        <x-slot name="title">
                                                            {{ __('Change Role') }}
                                                        </x-slot>
                                                        
                                                        <x-slot name="content">
                                                            <p>{{$member->name}}</p>
                                                            <form method="POST" action="{{ route('teams.roles.change') }}" x-ref="changeRoleForm" @keydown.enter.prevent="if (role !== userRole) { $refs.changeRoleForm.submit() }">
                                                                @csrf
                                                                @method('PUT')

                                                                <input type="hidden" name="resource_id" value="{{ $team->id }}">
                                                                <input type="hidden" name="user_id" value="{{ $member->id }}">
                                                                @if (config('neev.roles'))
                                                                    <input type="hidden" name="resource_type" value="{{ class_basename(Ssntpl\Neev\Models\Team::class) }}">
                                                                @endif
                                                                <div class="mt-4 flex gap-2 items-center w-2/3">
                                                                    <x-neev-component::label for="role" value="{{ __('Role') }}" />
                                                                    <select name="role" id="role" x-model="role" class="w-full border rounded-md p-2">
                                                                        @foreach ($team->roles() as $role)
                                                                            @if (config('neev.roles'))
                                                                                <option value="{{$role->name}}" x-bind:selected="role === '{{$role->name}}'">{{$role->name}}</option>
                                                                            @else
                                                                                <option value="{{$role}}" x-bind:selected="role === '{{$role}}'">{{$role}}</option>
                                                                            @endif
                                                                        @endforeach
                                                                    </select>
                                                                </div>
                                                            </form>
                                                        </x-slot>

                                                        <x-slot name="footer">
                                                            <x-neev-component::secondary-button @click="show = false">
                                                                {{ __('Cancel') }}
                                                            </x-neev-component::secondary-button>

                                                            <x-neev-component::button type="submit" x-bind:disabled="role === userRole" class="ms-3" @click.prevent="$refs.changeRoleForm.submit()">
                                                                {{ __('Change Role') }}
                                                            </x-neev-component::button>
                                                        </x-slot>
                                                    </x-neev-component::dialog-modal>
                                                </div>
                                            </td>
                                        @endif
                                        <td class="px-4 py-2"></td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{route('teams.invite')}}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="email" value="{{ $member->email }}">
                                                <input type="hidden" name="role" value="{{ $member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '') }}">
                                                <x-neev-component::button>{{__('Invite')}}</x-neev-component::button>
                                            </form>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                                @csrf
                                                @method('DELETE')
                                                
                                                <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                                <input type="hidden" name="user_id" value="{{ $member->id }}"/>
                                                <x-neev-component::danger-button
                                                    type="submit"
                                                    class="w-2/3" 
                                                    @click.prevent="if (confirm('{{ __('Are you sure you want to remove user from the team?') }}')) $el.closest('form').submit();">
                                                    {{ __('Remove') }}
                                                </x-neev-component::danger-button>
                                            </form>
                                        </td>
                                    @else
                                        <td class="px-4 py-2 text-center capitalize">
                                            {{ $member->role($team)->first()?->role?->name ?? ($member->membership->role ?? '') }}
                                        </td>
                                    @endif
                                </x-neev-component::table-body-tr>
                            @endforeach
                            @foreach ($team->invitations as $invitation)
                                <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
                                    <td class="flex gap-2 px-4 py-2">
                                        <div class="w-10 h-10 bg-blue-100 text-blue-500 text-xl rounded-full flex items-center justify-center font-medium">
                                            {{ $invitation->profile_photo_url }}
                                        </div>
                                        <div class="flex justify-center items-center">
                                            <p class="text-sm">{{$invitation->email}}</p>
                                        </div>
                                    </td>
                                    @if ($team->owner->id === $user->id)
                                        <td class="px-4 py-2 text-center capitalize">
                                            <div class="text-start" x-data="{ show: false, role: @js($invitation->role ?? ''), userRole: @js($invitation->role ?? '') }">
                                                <button class="capitalize underline cursor-pointer" @click="show = true">{{ $invitation->role ?? '--'}}</button>
                                                <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                                                    <x-slot name="title">
                                                        {{ __('Change Role') }}
                                                    </x-slot>
                                                    
                                                    <x-slot name="content">
                                                        <p class="normal-case">{{$invitation->email}}</p>
                                                        <form method="POST" action="{{ route('teams.roles.change') }}" x-ref="changeRoleForm" @keydown.enter.prevent="if (role !== userRole) { $refs.changeRoleForm.submit() }">
                                                            @csrf
                                                            @method('PUT')

                                                            <input type="hidden" name="resource_id" value="{{ $team->id }}">
                                                            <input type="hidden" name="invitation_id" value="{{ $invitation->id }}">
                                                            <div class="mt-4 flex gap-2 items-center w-2/3">
                                                                <x-neev-component::label for="role" value="{{ __('Role') }}" />
                                                                <select name="role" id="role" x-model="role" class="w-full border rounded-md p-2">
                                                                    @foreach ($team->roles() as $role)
                                                                        @if (config('neev.roles'))
                                                                            <option value="{{$role->name}}" x-bind:selected="role === '{{$role->name}}'">{{$role->name}}</option>
                                                                        @else
                                                                            <option value="{{$role}}" x-bind:selected="role === '{{$role}}'">{{$role}}</option>
                                                                        @endif
                                                                    @endforeach
                                                                </select>
                                                            </div>
                                                        </form>
                                                    </x-slot>

                                                    <x-slot name="footer">
                                                        <x-neev-component::secondary-button @click="show = false">
                                                            {{ __('Cancel') }}
                                                        </x-neev-component::secondary-button>

                                                        <x-neev-component::button type="submit" x-bind:disabled="role === userRole" class="ms-3" @click.prevent="$refs.changeRoleForm.submit()">
                                                            {{ __('Change Role') }}
                                                        </x-neev-component::button>
                                                    </x-slot>
                                                </x-neev-component::dialog-modal>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2">
                                            <div class="flex justify-center items-center">
                                                 <p>Expire in {{ $invitation->expires_at->diffForHumans() }}</p>
                                            </div>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{route('teams.invite')}}">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <input type="hidden" name="email" value="{{ $invitation->email }}">
                                                <input type="hidden" name="role" value="{{ $invitation->role }}">
                                                <x-neev-component::button>{{__('Invite')}}</x-neev-component::button>
                                            </form>
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                                @csrf
                                                @method('DELETE')
                                                
                                                <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                                <input type="hidden" name="invitation_id" value="{{ $invitation->id }}"/>
                                                <x-neev-component::danger-button
                                                    type="submit"
                                                    class="w-2/3" 
                                                    @click.prevent="if (confirm('{{ __('Are you sure you want to remove user from the team?') }}')) $el.closest('form').submit();">
                                                    {{ __('Remove') }}
                                                </x-neev-component::danger-button>
                                            </form>
                                        </td>
                                    @else
                                        @if (config('neev.roles'))
                                            <td class="px-4 py-2 text-center capitalize">
                                                {{ $invitation->role ?? ''}}
                                            </td>
                                        @endif
                                        <td class="px-4 py-2">
                                            <div class="flex justify-center items-center">
                                                <p class="text-sm">{{$invitation->expires_at}}</p>
                                            </div>
                                        </td>
                                    @endif
                                </x-neev-component::table-body-tr>
                            @endforeach
                        </x-slot>
                    </x-neev-component::table>
                @endif
            </x-slot>
        </x-neev-component::card>
    </div>
</x-neev-layout::app>
