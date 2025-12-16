<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    @if (config('neev.domain_federation') && $team)
        <div x-data="{show: {{session('token') ? 'true' : 'false'}} }" class="flex flex-col gap-4">
            {{-- Domain Federation --}}
            <x-neev-component::card x-data="{addDomainOpen: false}">
                {{-- Title --}}
                <x-slot name="title">
                    {{__('Domain Federation')}}
                </x-slot>

                {{-- Action --}}
                <x-slot name="action" class="flex">
                    <div>
                        <div x-show="!addDomainOpen" x-on:click="addDomainOpen = true" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                            </svg>
                        </div>

                        <div x-show="addDomainOpen" x-on:click="addDomainOpen = false" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                    </div>
                </x-slot>

                {{-- Content --}}
                <x-slot name="content">
                    <form class="flex flex-col gap-4" x-show="addDomainOpen" x-transition method="POST" action="{{ route('teams.domain', $team->id) }}">
                        @csrf

                        <div class="flex gap-4 items-center">
                            <x-neev-component::label for="domain" value="{{ __('Domain') }}" />
                            <x-neev-component::input id="domain" class="block mt-1 w-1/2" type="text" name="domain" required autofocus />
                            <label for="enforce" class="flex items-center">
                                <x-neev-component::checkbox id="enforce" name="enforce" checked/>
                                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Enforce Domain') }}</span>
                            </label>
                        </div>
                        <div class="flex gap-4 justify-end">
                            <x-neev-component::button>{{__('Add Domain')}}</x-neev-component::button>
                        </div>
                    </form>

                    {{-- List of Domains --}}
                    <div class="text-lg font-semibold select-none">Domains</div>
                    <div class="flex flex-col gap-2 px-2">
                        @foreach ($domains ?? [] as $domain)
                            <div class="relative" x-data="{ show: false }">
                                <div class="flex gap-2 items-center justify-between">
                                    <div class="font-semibold flex items-center gap-2 w-1/5" @mouseenter="show = true" @mouseleave="show = false">
                                        {{ $domain->domain }}
                                        @if ($domain->outside_members)
                                            <div class="relative flex items-center">
                                                <span class="absolute inline-flex h-5 w-5 rounded-full bg-red-400 bg-opacity-40 animate-ping"></span>
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor"class="w-5 h-5 text-red-600 cursor-pointer">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M4.93 19h14.14c1.1 0 1.84-1.18 1.27-2.15L13.27 4.85c-.55-.97-2-.97-2.55 0L3.66 16.85c-.57.97.17 2.15 1.27 2.15z"/>
                                                </svg>
                                            </div>
                                        @endif
                                    </div>

                                    <form class="w-1/5" method="POST" action="{{route('teams.domain', $domain->id)}}">
                                        @csrf
                                        @method('PUT')
                                        <label for="enforce" class="flex items-center">
                                            <x-neev-component::checkbox onchange="this.form.submit()" id="enforce" name="enforce" class="cursor-pointer" x-bind:checked="{{$domain->enforce ? 'true' : 'false'}}"/>
                                            <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Enforce Domain') }}</span>
                                        </label>
                                    </form>
                                    @if (!$domain->verified_at)
                                        <form method="POST" class="w-1/5 text-center" action="{{route('teams.domain', $domain->id)}}">
                                            @csrf
                                            @method('PUT')
                                            <x-neev-component::button name="token" value="token">{{__('Get Token')}}</x-neev-component::button>
                                        </form>
                                        <form method="POST" class="w-1/5 text-center" action="{{route('teams.domain', $domain->id)}}">
                                            @csrf
                                            @method('PUT')
                                            <x-neev-component::button name="verify" value="verify">{{ __('Verify') }}</x-neev-component::button>
                                        </form>
                                    @else
                                        <div class="w-1/5 text-center">
                                            @if ($domain->is_primary)
                                                <span class="border border-blue-600 text-sm tracking-tight text-blue-600 rounded-full px-2">{{ 'Primary' }}</span>
                                            @endif
                                        </div>
                                        <div class="w-1/5 text-center">
                                            @if ($domain->verified_at)
                                                <span class="border border-green-700 text-sm tracking-tight text-green-700 rounded-full px-2">{{ 'Verified' }}</span>
                                            @endif
                                        </div>
                                    @endif
                                    <form method="POST" action="{{route('teams.domain', $domain->id)}}">
                                        @csrf
                                        @method('DELETE')
                                        <x-neev-component::danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete the domain?')}}')) $el.closest('form').submit();">{{__('Delete')}}</x-neev-component::danger-button>
                                    </form>
                                </div>
                                <!-- Tooltip -->
                                @if ($domain->outside_members)
                                    <div x-show="show" x-transition
                                        class="absolute w-2/5 z-50 left-0 mt-1 bg-red-100 text-red-800 text-xs font-medium px-2 py-1 rounded shadow-md">
                                        {{$domain->outside_members}} team members are using email addresses outside your verified domain ({{ '@'.$domain->domain }}). To keep your team secure, remove these users or ask them to rejoin using a matching email address.
                                    </div>
                                @endif
                            </div>
                            <div class="border-b"></div>
                        @endforeach
                    </div>

                </x-slot>
            </x-neev-component::card>

            <div class="flex justify-between gap-2 items-center border shadow px-4 py-2 rounded-lg">
                <div>
                    <h1 class="font-bold">Primary Domain</h1>
                    <p class="text-sm">Select a domain.</p>
                </div>
                <form method="POST" action="{{route('domain.primary')}}">
                    @csrf
                    @method('PUT')

                    <select name="domain_id" class="border rounded-md px-2 py-1" onchange="this.form.submit()">
                        @foreach ($domains->whereNotIn('verified_at', ['', null]) as $domain)
                            <option value="{{$domain->id}}" x-bind:selected="{{$domain->is_primary ? 'true' : 'false'}}">{{$domain->domain}}</option>
                        @endforeach
                    </select>
                </form>
            </div>
            
            {{-- Domain Rules --}}
            {{-- <x-neev-component::card>
                <x-slot name="title">
                    {{__('Domain Rules')}}
                </x-slot>
                
                <x-slot name="action">
                    <x-neev-component::button form="updateDomainRulesForm">{{__('Save')}}</x-neev-component::button>
                </x-slot>

                <x-slot name="content">
                    @if (count($team->rules) > 0)
                        <form id="updateDomainRulesForm" method="POST" action="{{route('domain.rules', $team->id)}}">
                            @csrf
                            @method('PUT')
                            <x-neev-component::table>
                                <x-slot name="body">
                                    @foreach ($team->rules as $rule)
                                        <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
                                            <td class="px-4 py-2 w-1/2 text-start">
                                                {{ $rule->name }}
                                            </td>
                                            <td class="px-4 py-2 text-start">
                                                <label for="{{$rule->name}}">
                                                    <x-neev-component::checkbox id="{{$rule->name}}" name="{{$rule->name}}" x-bind:checked="{{$rule->value ?? 0}}"/>
                                                </label>
                                            </td>
                                        </x-neev-component::table-body-tr>
                                    @endforeach
                                </x-slot>
                            </x-neev-component::table>
                        </form>
                    @endif
                </x-slot>
            </x-neev-component::card> --}}

            <x-neev-component::dialog-modal x-show="show" x-cloak @keydown.escape.window="show = false" @click.away="show = false">
                <x-slot name="title">
                    {{ __('DNS Token') }}
                </x-slot>
                
                <x-slot name="content">
                    {{ __('Add this TXT record to your domain’s DNS.') }}
                    <div class="bg-gray-100 px-1 rounded flex gap-2 justify-between items-center">
                        <input
                            type="text"
                            x-ref="newTokenInput"
                            readonly
                            class="bg-transparent border-0 px-1 w-full text-sm"
                            value="{{ session('token') }}"
                        >
                        <x-neev-component::button type="button" @click="navigator.clipboard.writeText($refs.newTokenInput.value)">
                            {{ __('Copy') }}
                        </x-neev-component::button>
                    </div>
                </x-slot>

                <x-slot name="footer">
                </x-slot>
            </x-neev-component::dialog-modal>
        </div>
    @endif
</x-neev-layout::app>
