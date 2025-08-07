<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <div x-data="permissonManager(@js($allPermissions))">
        {{-- Add API Token --}}
        <x-card>
            {{-- title --}}
            <x-slot name="title">
                {{ __('API Tokens') }}
            </x-slot>
            
            {{-- Action --}}
            <x-slot name="action" class="flex">
                <div>
                    <div x-show="!tokenOpen" x-on:click="tokenOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                        </svg>
                    </div>

                    <div x-show="tokenOpen" x-on:click="tokenOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                </div>
            </x-slot>

            {{-- Content --}}
            <x-slot name="content">
                <form method="POST" x-show="tokenOpen" x-transition action="{{ route('tokens.store') }}" class="flex flex-col gap-2">
                    @csrf

                    <div class="flex gap-4 justify-between">
                        <div class="flex gap-4 justify-start items-center w-1/2">
                            <x-label for="name" value="{{ __('Token Name') }}" class="w-1/3" />
                            <x-input id="name" class="block w-5/6" type="text" name="name" autocomplete="off" />
                        </div>
                        
                        <div class="flex gap-4 justify-center items-center w-1/4">
                            <x-label for="expiry" value="{{ __('Expiration') }}" />
                            <select name="expiry" class="border rounded-md px-2 py-1 w-2/3">
                                <option value="10080" selected>7 Days</option>
                                <option value="43200">30 Days</option>
                                <option value="129600">90 Days</option>
                                <option value="">No Expiry</option>
                            </select>
                        </div>
                        
                        @if (config('neev.roles'))
                            <div class="flex px-4 justify-start text-start items-center w-1/4" >
                                <template x-for="permission in newSelected" x-bind:key="permission">
                                    <input type="hidden" name="permissions[]" x-bind:value="permission">
                                </template>
                                <div @click="openModal(0)" class="underline cursor-pointer hover:text-gray-500">
                                    Permissions (<span x-text="newSelected.length"></span>)
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="relative flex items-center justify-end">
                        <x-button>
                            {{ __('Create') }}
                        </x-button>
                    </div>
                </form>
                @if (count($user->apiTokens ?? []) > 0)
                    <div>
                        <x-table>
                            <x-slot name="head">
                                <tr>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Name</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Last Used</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Expiration</th>
                                    <th class="px-6 py-3 text-center font-bold tracking-wide">Added</th>
                                    <th class="px-6 py-3 text-end font-bold tracking-wide">
                                        <form method="POST" action="{{route('tokens.deleteAll')}}">
                                            @csrf
                                            @method('DELETE')
                                            <x-danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete all api tokens?')}}')) $el.closest('form').submit();">{{ __('Delete All') }}</x-danger-button>
                                        </form>
                                    </th>
                                </tr>
                            </x-slot>
                            <x-slot name="body">
                                @foreach ($user->apiTokens()->orderByDesc('created_at')->get() as $token)
                                    <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                        <td class="px-6 py-4 text-center capitalize">{{ $token->name ?? '--' }}</td>
                                        <td class="px-6 py-4 text-center">{{ $token->last_used?->diffForHumans() ?? '--' }}</td>
                                        <td class="px-6 py-2 text-center">
                                            @if ($token->expires_at)
                                            <p>{{$token->expires_at?->diffForHumans()}}</p>
                                            <p>({{$token->expires_at}})</p>
                                            @else
                                                --
                                            @endif
                                        </td>
                                        <td class="px-6 py-4 text-center">{{ $token->created_at?->diffForHumans() ?? '--' }}</td>
                                        <td class="px-6 py-4 flex gap-4 items-center justify-end">
                                            @if (config('neev.roles'))
                                                <div @click="openModal('{{$token->id}}', @js($token->permissions))" class="underline cursor-pointer">Permissions (<span>{{in_array('*', $token->permissions) ? '*' : count($token->permissions)}}</span>)</div>
                                            @endif
                                            <form method="POST" action="{{route('tokens.delete')}}">
                                                @csrf
                                                @method('DELETE')
                                                <input type="hidden" name="token_id", value="{{$token->id}}">
                                                <x-danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete api token?')}}')) $el.closest('form').submit();">{{ __('Delete') }}</x-danger-button>
                                            </form>
                                        </td>
                                    </x-table-body-tr>
                                @endforeach
                            </x-slot>
                        </x-table>
                    </div>
                @endif
            </x-slot>
        </x-card>
        @if (session('token'))
            <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="closeModal" @click.away="closeModal">
                <x-slot name="title">
                    {{ __('New Token') }}
                </x-slot>
                
                <x-slot name="content">
                    {{ __('Make sure to copy your api token now. You won’t be able to see it again!') }}
                    <div class="bg-gray-100 px-1 rounded flex gap-2 justify-between items-center">
                        <input
                            type="text"
                            x-ref="newTokenInput"
                            readonly
                            class="bg-transparent border-0 px-1 w-full text-sm"
                            value="{{ session('token') }}"
                        >
                        <x-button type="button" @click="navigator.clipboard.writeText($refs.newTokenInput.value)">
                            {{ __('Copy') }}
                        </x-button>
                     </div>
                </x-slot>

                <x-slot name="footer">
                    <x-secondary-button @click="closeModal()">
                        {{ __('Done') }}
                    </x-secondary-button>
                </x-slot>
            </x-dialog-modal>
        @else
            <x-dialog-modal x-show="show" x-cloak @keydown.escape.window="closeModal" @click.away="closeModal">
                <x-slot name="title">
                    {{ __('API Token Permissions') }}
                </x-slot>
                
                <x-slot name="content">
                    {{ __('Choose the minimal permissions necessary for your needs.') }}
                    <div class="grid grid-cols-2 gap-2 max-h-64 overflow-y-auto">
                        <template x-for="permission in allPermissions" x-bind:key="permission.name">
                            <label class="flex items-center gap-2">
                                <template x-if="tokenId !== 0">
                                    <input
                                        type="checkbox"
                                        x-bind:value="permission.name"
                                        name="permissions[]"
                                        x-model="selected"
                                    >
                                </template>
                                <template x-if="tokenId === 0">
                                    <input
                                        type="checkbox"
                                        x-bind:value="permission.name"
                                        name="permissions[]"
                                        x-model="newSelected"
                                    >
                                </template>
                                <span x-text="permission.name"></span>
                            </label>
                        </template>
                    </div>

                    <template x-if="tokenId !== 0">
                        <form method="POST" action="{{ route('tokens.update') }}" x-ref="changePermissionForm" class="hidden">
                            @csrf
                            @method('PUT')
                            
                            <input type="hidden" name="token_id" x-bind:value="tokenId">
                            <template x-for="permission in selected" x-bind:key="permission">
                                <input type="hidden" name="permissions[]" x-bind:value="permission">
                            </template>
                        </form>
                    </template>
                </x-slot>

                <x-slot name="footer">
                    <template x-if="tokenId === 0">
                        <x-secondary-button @click="closeModal()">
                            {{ __('Done') }}
                        </x-secondary-button>
                    </template>

                    <template x-if="tokenId !== 0">
                        <div class="flex gap-2 justify-end w-full">
                            <x-secondary-button @click="closeModal()">
                                {{ __('Cancel') }}
                            </x-secondary-button>
                            <x-button @click.prevent="$refs.changePermissionForm.submit()">
                                {{ __('Save') }}
                            </x-button>
                        </div>
                    </template>
                </x-slot>
            </x-dialog-modal>
        @endif
    </div>
</x-app>
<script>
    function permissonManager(permissions) {
        return {
            tokenOpen: false,
            allPermissions: permissions,
            show: {{ session()->has('token') ? 'true' : 'false' }},
            tokenId: null,
            selected: [],
            newSelected: [],
            openModal(tokenId, currentPermissions = []) {
                this.show = true;
                this.tokenId = tokenId;

                if (tokenId !== 'new') {
                    this.selected = [...currentPermissions];
                }
            },

            closeModal() {
                this.show = false;

                if (this.tokenId !== 'new') {
                    this.selected = [];
                    this.tokenId = null;
                }
            }
        }
    }
</script>
