<x-app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <div class="flex flex-col gap-4">
        @if ($team->user_id === $user->id && config('neev.roles'))
            <x-card x-data="{roleOpen: false}">
                <x-slot name="title">
                    {{__('Add Role')}}
                </x-slot>
                <x-slot name="action">
                    <div>
                        <div x-show="!roleOpen" x-on:click="roleOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                            </svg>
                        </div>

                        <div x-show="roleOpen" x-on:click="roleOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                </x-slot>

                <x-slot name="content">
                    <div x-show="roleOpen" x-transition>
                        <form method="POST" class="flex gap-2 mx-4 items-center justify-between" action="{{ route('teams.roles.store') }}">
                            @csrf

                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <div class="flex gap-4 justify-between w-full">
                                <div class="flex gap-2 items-center w-2/3">
                                    <x-label for="name" value="{{ __('Name') }}" />
                                    <x-input id="name" class="block w-full" type="text" name="name" required autofocus />
                                </div>
                            </div>
                            <div class="w-1/2 text-end">
                                <x-button>
                                    {{__('create')}}
                                </x-button>
                            </div>
                        </form>
                    </div>
                </x-slot>
            </x-card>
        @endif

        <x-card>
           <x-slot name="title">
                {{__('Roles')}}
           </x-slot>
           
           <x-slot name="action">
                <span>
                    {{__('Total Roles')}}
                    <span class="font-bold">{{ __(count($team->roles)) }}</span>
                </span>
           </x-slot>
           
           <x-slot name="content">
                @if (count($team->roles) > 0 && $team->owner->id === $user->id)
                    <ul class="select-none overflow-x-auto rounded-lg shadow border border-gray-200 dark:border-gray-700">
                        @foreach ($team->roles()->orderBy('name')->get() as $role)
                            <li x-data="{ open: false }" class="border odd:bg-white even:bg-gray-50">
                                <div @click="open = !open" class="flex cursor-pointer gap-2 py-2 px-4 justify-between hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                    <div class="font-bold w-1/3 text-start">{{$role->name}}</div>
                                    <div class="w-1/3 text-center">{{count($role->permissions)}}</div>
                                    <div class="w-1/3 text-end">
                                        <span x-show="!open" x-cloak>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </span>
                                        <span x-show="open" x-cloak>
                                            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                                            </svg>
                                        </span>
                                    </div>
                                </div>
                                <div x-show="open" x-data="permissionManager(@js($role->permissions()->orderBy('name')->get()), @js($allPermissions->diff($role->permissions)->sortBy('name')))" x-init="init()" class="py-2 px-8 border bg-gray-50">
                                    <div class="flex gap-2 justify-between mb-2">
                                        <h3 class="font-semibold">{{__('Permissions')}}</h3>
                                        <form method="POST" action="{{route('teams.roles.delete')}}">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="team_id" value="{{$team->id}}">
                                            <input type="hidden" name="role_id" value="{{$role->id}}">
                                            <x-danger-button
                                                @click.prevent="if (confirm('{{ __('Are you sure you want to delete role from the team?') }}')) $el.closest('form').submit();"
                                                type="submit">{{__('Delete Role')}}</x-danger-button>
                                        </form>
                                    </div>
                                    <form method="POST" action="{{ route('roles.permissions.update') }}" class="mt-2 px-4">
                                        @csrf
                                        @method('PUT')

                                        <input type="hidden" name="role_id" value="{{ $role->id }}">
                                        <input type="hidden" name="permissions" :value="selected.map(p => p.id)">
                                        <template x-for="(permission, index) in selected" :key="permission.id">
                                            <div @click="removePermission(index)" class="cursor-pointer flex items-center justify-between px-3 py-1 bg-green-100 rounded mb-1">
                                                <span x-text="permission.name"></span>
                                                <div class="border rounded-full shadow text-red-600">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                                        <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </template>
                                        <template x-for="(permission, index) in unselected" :key="permission.id">
                                            <div @click="addPermission(index)" class="cursor-pointer flex items-center justify-between px-3 py-1 bg-gray-200 rounded mb-1">
                                                <span x-text="permission.name"></span>
                                                <div class="border rounded-full shadow text-green-500">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                                        <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                                                    </svg>
                                                </div>
                                            </div>
                                        </template>

                                        <div class="mt-4 flex justify-end gap-4">
                                            <x-secondary-button type="button" @click="reset">{{ __('Reset') }}</x-secondary-button>
                                            <x-button>{{ __('Save') }}</x-button>
                                        </div>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
           </x-slot>
        </x-card>
    </div>
</x-app>

<script>
    function permissionManager(selectedPermissions, unselectedPermissions) {
        return {
            selected: selectedPermissions,
            unselected: unselectedPermissions,
            originalSelected: [],
            originalUnselected: [],
            init() {
                this.originalSelected = [...this.selected];
                this.originalUnselected = [...this.unselected];
            },
            addPermission(index) {
                this.selected.push(this.unselected[index]);
                this.unselected.splice(index, 1);
                this.selected.sort((a, b) => a.name.localeCompare(b.name));
            },
            removePermission(index) {
                this.unselected.push(this.selected[index]);
                this.selected.splice(index, 1);
                this.unselected.sort((a, b) => a.name.localeCompare(b.name));
            },
            reset() {
                this.selected = [...this.originalSelected];
                this.unselected = [...this.originalUnselected];
            }
        }
    }
</script>


