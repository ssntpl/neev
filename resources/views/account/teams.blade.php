<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    
    <div class="flex flex-col gap-4" x-data="{ showForm: false }">
        <x-card>
            <x-slot name="title">
                {{__('Join Team')}}
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
                                    <td class="px-4 py-2 text-center capitalize">
                                        {{$team->membership->role_id}}
                                    </td>
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
                                    <td class="px-4 py-2 text-center capitalize">
                                        {{$team->membership->role_id}}
                                    </td>
                                    <td class="px-4 py-2 text-center capitalize">
                                        {{$team->user_id === $user->id ? 'Owner' : 'Member'}}
                                    </td>
                                    <td class="px-4 py-2 text-end">
                                        @if ($team->user_id !== $user->id)
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

</x-app>