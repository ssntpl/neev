<x-app>
    @if (in_array($user->email,config('neev.app_owner')))
        @php
            $users = \Ssntpl\Neev\Models\User::orderBy('name')->get();
        @endphp
        <div class="flex flex-col gap-6">
            <x-card x-data="{ open: true }" contentAttr='x-show=open x-transition'>
                <x-slot name="title">
                    {{__('Users')}}
                </x-slot>
    
                <x-slot name="action">
                    <span>
                        {{__('Total Users')}}
                        <span class="font-bold">{{ __(count($users)) }}</span>
                    </span>
    
                    <div>
                        <div x-show="!open" x-on:click="open = true" class="cursor-pointer border border-2 border-black rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                            </svg>
                        </div>

                        <div x-show="open" class="flex gap-4 items-center">
                            <button onclick="location.reload();" class="cursor-pointer ml-4">
                                <x-refresh-button/>
                            </button>
                            <div x-on:click="open = false" class="cursor-pointer border border-2 border-black rounded-full shadow">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </x-slot>
                
                <x-slot name="content">
                    @if (count($users ?? []) > 0)
                        <x-table>
                            <x-slot name="head">
                                <tr>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Name</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Email</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Email Verified</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Registered</th>
                                    @if (config('neev.team'))
                                        <th class="px-6 py-3 text-center font-bold tracking-wide">Teams</th>
                                    @endif
                                </tr>
                            </x-slot>
                            <x-slot name="body">
                                @foreach ($users as $user)
                                    <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="px-6 py-4 text-start capitalize">{{ $user->name ?? '--' }}</td>
                                        <td class="px-6 py-4 text-center">{{ $user->email ?? '--' }}</td>
                                        <td class="px-6 py-4 text-center">{{ $user->email_verified_at->diffForHumans() ?? '--' }}</td>
                                        <td class="px-6 py-4 text-center">{{ $user->created_at->diffForHumans() ?? '--' }}</td>
                                        @if (config('neev.team'))
                                            <td class="px-6 py-4 text-center">{{ count($user->allTeams) ?? '--' }}</td>
                                        @endif
                                    </x-table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-table>
                    @endif
                </x-slot>
            </x-card>

            @if (config('neev.team'))
                @php
                    $teams = \Ssntpl\Neev\Models\Team::orderBy('name')->get();
                @endphp
                <x-card x-data="{ open: true }" contentAttr='x-show=open x-transition'>
                    <x-slot name="title">
                        {{__('Teams')}}
                    </x-slot>
        
                    <x-slot name="action">
                        <span>
                            {{__('Total Users')}}
                            <span class="font-bold">{{ __(count($teams)) }}</span>
                        </span>
        
                        <div>
                            <div x-show="!open" x-on:click="open = true" class="cursor-pointer border border-2 border-black rounded-full shadow">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                                </svg>
                            </div>

                            <div x-show="open" class="flex gap-4 items-center">
                                <button onclick="location.reload();" class="cursor-pointer ml-4">
                                    <x-refresh-button/>
                                </button>
                                <div x-on:click="open = false" class="cursor-pointer border border-2 border-black rounded-full shadow">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </x-slot>
        
                    <x-slot name="content">
                        @if (count($teams ?? []) > 0)
                            <x-table>
                                <x-slot name="head">
                                    <tr>
                                        <th class="px-6 py-3 text-center font-bold tracking-wide">Name</th>
                                        <th class="px-6 py-3 text-center font-bold tracking-wide">Owner</th>
                                        <th class="px-6 py-3 text-center font-bold tracking-wide">Members</th>
                                        <th class="px-6 py-3 text-center font-bold tracking-wide">Created</th>
                                    </tr>
                                </x-slot>
                                <x-slot name="body">
                                    @foreach ($teams as $team)
                                        <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                            <td class="px-6 py-4 text-start capitalize">
                                                <div class="flex gap-2">
                                                    <p>{{ $team->name ?? '--' }}</p> 
                                                    <p class="border px-1 rounded-full text-gray-400">{{$team->is_public ? 'Public' : 'Private'}}</p>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">
                                                <div>
                                                    <p>{{ $team->owner->name ?? '--' }}</p>
                                                    <p class="text-xs">{{ $team->owner->email ?? '--' }}</p>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 text-center">{{ count($team->allUsers) ?? '--' }}</td>
                                            <td class="px-6 py-4 text-center">{{ $team->created_at->diffForHumans() ?? '--' }}</td>
                                        </x-table-body-tr>
                                    @endforeach
                                </x-slot>
                            </x-table>
                        @endif
                    </x-slot>
                </x-card>
            @endif
           
            @if (config('neev.roles'))
                @php
                    $permissions = \Ssntpl\Neev\Models\Permission::orderBy('name')->get();
                @endphp
                <x-card x-data="{ open: true }" contentAttr='x-show=open x-transition'>
                    <x-slot name="title">
                        {{__('Permissions')}}
                    </x-slot>
        
                    <x-slot name="action">
                        @session('p_status')
                            <div class="text-green-600">
                                {{ session('p_status') }}
                            </div>
                        @endsession

                        <x-input-error for="p_message"/>
                        <span>
                            {{__('Total Permissions')}}
                            <span class="font-bold">{{ __(count($permissions)) }}</span>
                        </span>
        
                        <div>
                            <div x-show="!open" x-on:click="open = true" class="cursor-pointer border border-2 border-black rounded-full shadow">
                                <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                    <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                                </svg>
                            </div>

                            <div x-show="open" class="flex gap-4 items-center">
                                <button onclick="location.reload();" class="cursor-pointer ml-4">
                                    <x-refresh-button/>
                                </button>
                                <div x-on:click="open = false" class="cursor-pointer border border-2 border-black rounded-full shadow">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </x-slot>
        
                    <x-slot name="content">
                        <form method="POST" action="{{route('permissions.store')}}" class="flex gap-4 justify-between px-8">
                            @csrf

                            <div class="flex gap-4 justify-between w-full">
                                <div class="flex gap-2 items-center w-2/3">
                                    <x-label for="name" value="{{ __('Name') }}" />
                                    <x-input id="name" class="block w-full" type="text" name="name" required />
                                </div>
                            </div>
                            <div class="w-1/2 text-end">
                                <x-button>
                                    {{__('Add')}}
                                </x-button>
                            </div>
                        </form>
                        @if (count($permissions ?? []) > 0)
                            <x-table>
                                <x-slot name="body">
                                    @foreach ($permissions as $permission)
                                        <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                            <td class="px-6 py-2 text-start">{{$permission->name}}</td>
                                            <td class="px-6 py-2 text-end">
                                                <form method="POST" action="{{route('permissions.delete')}}">
                                                    @csrf
                                                    @method('DELETE')

                                                    <input type="hidden" name="permission_id" value="{{$permission->id}}">
                                                    <x-danger-button 
                                                        @click.prevent="if (confirm('{{ __('Are you sure you want to delete permission?') }}')) $el.closest('form').submit();"
                                                        type="submit">{{__('Delete')}}</x-danger-button>
                                                </form>
                                            </td>
                                        </x-table-body-tr>
                                    @endforeach
                                </x-slot>
                            </x-table>
                        @endif
                    </x-slot>
                </x-card>
            @endif
        </div>
    @endif
</x-app>
