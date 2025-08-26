<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    @if (config('neev.team'))
        <div class="flex flex-col gap-4" x-data="{ showForm: false }">
            @if ($join_team)
                <x-card x-data="{joinTeamOpen: false}">
                    <x-slot name="title">
                        {{__('Join Team')}}
                    </x-slot>
                    <x-slot name="action" class="flex">
                        <div>
                            <div x-show="!joinTeamOpen" x-on:click="joinTeamOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                                </svg>
                            </div>

                            <div x-show="joinTeamOpen" x-on:click="joinTeamOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </x-slot>

                    <x-slot name="content">
                        <div x-show="joinTeamOpen" x-transition>
                            <form method="POST" class="flex gap-2 mx-4 items-center justify-between" action="{{ route('teams.request') }}">
                                @csrf

                                <div class="flex gap-4 justify-between w-full">
                                    <div class="flex gap-2 items-center w-2/3">
                                        <x-label for="team" value="{{ __('Team') }}" />
                                        <x-input id="team" class="block w-full" type="text" name="team" required autofocus />
                                    </div>
                                    <div class="flex gap-2 items-center w-2/3">
                                        <x-label for="email" value="{{ __('Owner Email') }}" />
                                        <x-input id="email" class="block w-full" type="email" name="email" required autofocus />
                                    </div>
                                </div>
                                <div class="w-1/2 text-end">
                                    <x-button>
                                        {{__('Send Request')}}
                                    </x-button>
                                </div>
                            </form>
                        </div>
                    </x-slot>
                </x-card>
            @endif

            <x-card>
                <x-slot name="title">
                    {{ __('Teams') }}
                </x-slot>
                
                <x-slot name="action">
                    <span>
                        {{__('All Teams')}}
                        <span class="font-bold">{{ __(count($user->allTeams)) }}</span>
                    </span>
        
                    <button onclick="location.reload();" class="cursor-pointer">
                        <x-refresh-button/>
                    </button>
                </x-slot>
        
                <x-slot name="content">

                    {{-- Team Request --}}
                    @if (count($user->teamRequests) > 0)
                        <div class="flex justify-between">
                            <h1 class="font-bold">{{__('Team Requests')}}</h1>
                            <span>
                                {{__('Total Requests')}}
                                <span class="font-bold">{{ __(count($user->teamRequests)) }}</span>
                            </span>
                        </div>
                        <x-table>
                            <x-slot name="body">
                                @foreach ($user->teamRequests as $team)
                                    <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="flex gap-2 px-4 py-2">
                                            <a href="{{route('teams.profile', $team->id)}}" class="flex gap-1 items-center">
                                                <p class="text-lg hover:underline">{{$team->name}}</p>
                                                <p class="text-xs font-semibold text-gray-400 border rounded-full px-1">{{$team->is_public ? 'Public' : 'Private'}}</p>
                                            </a>
                                        </td>
                                        @if (config('neev.roles'))
                                            <td class="px-4 py-2 text-center capitalize">
                                                {{$team->membership->role->name ?? ''}}
                                            </td>
                                        @endif
                                        <td class="px-4 py-2 text-end">
                                            <form class="flex gap-4 justify-end" method="POST" action="{{ route('teams.invite.action') }}">
                                                @csrf
                                                @method('PUT')
        
                                                <input type="hidden" name="team_id" value="{{ $team->id }}">
                                                <x-button name="action" value="accept">
                                                    {{ __('Accept') }}
                                                </x-button>
                                                <x-danger-button type="submit" name="action" value="reject">
                                                    {{ __('Reject') }}
                                                </x-danger-button>
                                            </form>
                                        </td>
                                    </x-table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-table>
                    @endif

                    {{-- Your Teams --}}
                    @if (count($user->teams) > 0)
                        <div class="flex justify-between">
                            <h1 class="font-bold">{{__('Your Teams')}}</h1>
                            <span>
                                {{__('Total Teams')}}
                                <span class="font-bold">{{ __(count($user->teams)) }}</span>
                            </span>
                        </div>
                        <x-table>
                            <x-slot name="body">
                                @foreach ($user->teams as $team)
                                    <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="flex gap-2 px-4 py-2">
                                            <a href="{{route('teams.profile', $team->id)}}" class="flex gap-1 items-center">
                                                <p class="text-lg hover:underline">{{$team->name}}</p>
                                                <p class="text-xs font-semibold text-gray-400 border rounded-full px-1">{{$team->is_public ? 'Public' : 'Private'}}</p>
                                            </a>
                                        </td>
                                        @if (config('neev.roles'))
                                            <td class="px-4 py-2 text-center capitalize">
                                                {{$team->membership->role->name ?? ''}}
                                            </td>
                                        @endif
                                        <td class="px-4 py-2 text-center capitalize">
                                            {{$team->user_id === $user->id ? 'Owner' : 'Member'}}
                                        </td>
                                        <td class="px-4 py-2 text-end">
                                            @if ($team->user_id !== $user->id && !$team->domain_verified_at)
                                                <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                                    @csrf
                                                    @method('DELETE')
        
                                                    <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                                    <x-danger-button
                                                        type="submit"
                                                        @click.prevent="if (confirm('{{ __('Are you sure you want to leave the team?') }}')) $el.closest('form').submit();">
                                                        {{ __('Leave') }}
                                                    </x-danger-button>
                                                </form>
                                            @endif
                                        </td>
                                    </x-table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-table>
                    @endif

                    {{-- Sent Requests --}}
                    @if (count($user->sendRequests) > 0)
                        <div class="flex justify-between">
                            <h1 class="font-bold">{{__('Request Sent')}}</h1>
                            <span>
                                {{__('Total Requests')}}
                                <span class="font-bold">{{ __(count($user->sendRequests)) }}</span>
                            </span>
                        </div>
                        <x-table>
                            <x-slot name="body">
                                @foreach ($user->sendRequests as $team)
                                    <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="flex gap-2 px-4 py-2">
                                            <a href="{{route('teams.profile', $team->id)}}" class="flex gap-1 items-center">
                                                <p class="text-lg hover:underline">{{$team->name}}</p>
                                                <p class="text-xs font-semibold text-gray-400 border rounded-full px-1">{{$team->is_public ? 'Public' : 'Private'}}</p>
                                            </a>
                                        </td>
                                        <td class="px-4 py-2 text-end">
                                            <div class="flex gap-4 justify-end">
                                                <form method="POST" action="{{ route('teams.request') }}">
                                                    @csrf
            
                                                    <x-input type="hidden" name="team" value="{{ $team->name }}" />
                                                    <x-input type="hidden" name="email" value="{{ $team->owner->email }}" />
                                                    <x-button>
                                                        {{__('Send Request')}}
                                                    </x-button>
                                                </form>
                                                <form method="POST" action="{{ route('teams.leave') }}" x-data>
                                                    @csrf
                                                    @method('DELETE')

                                                    <input type="hidden" name="team_id" value="{{ $team->id }}"/>
                                                    <x-danger-button
                                                        type="submit"
                                                        @click.prevent="if (confirm('{{ __('Are you sure you want to cancel the request?') }}')) $el.closest('form').submit();">
                                                        {{ __('Revoke') }}
                                                    </x-danger-button>
                                                </form>
                                            </div>
                                        </td>
                                    </x-table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-table>
                    @endif
                </x-slot>
            </x-card>
        </div>
    @endif
</x-app>