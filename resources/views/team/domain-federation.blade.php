<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    @if (config('neev.domain_federation') && $team)
        <div x-data="{show: {{session('token') ? 'true' : 'false'}} }" class="flex flex-col gap-4">
            {{-- Domain Federation --}}
            <x-neev-component::card>
                <x-slot name="title">
                    {{__('Domain Federation')}}
                </x-slot>

                <x-slot name="content">
                    @if (!$team->federated_domain)
                        <form class="flex flex-col gap-4" method="POST" action="{{ route('teams.domain', $team->id) }}">
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
                                <x-neev-component::button>{{__('Federate Domain')}}</x-neev-component::button>
                            </div>
                        </form>
                    @else
                        <div class="flex flex-col gap-2">
                            <div class="text-lg font-semibold">Federated Domain</div>
                            <div class="flex gap-4 items-center justify-between">
                                <div class="font-semibold">{{$team->federated_domain}}</div>
                                <form method="POST" action="{{route('teams.domain', $team->id)}}">
                                    @csrf
                                    @method('PUT')
                                    <label for="enforce" class="flex items-center">
                                        <x-neev-component::checkbox onchange="this.form.submit()" id="enforce" name="enforce" x-bind:checked="{{$team->enforce_domain}}"/>
                                        <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Enforce Domain') }}</span>
                                    </label>
                                </form>
                                @if (!$team->domain_verified_at)
                                    <form method="POST" action="{{route('teams.domain', $team->id)}}">
                                        @csrf
                                        @method('PUT')
                                        <x-neev-component::button name="token" value="token">{{__('Get Verification Token')}}</x-neev-component::button>
                                    </form>
                                @endif
                                <form method="POST" action="{{route('teams.domain', $team->id)}}">
                                    @csrf
                                    @method('DELETE')
                                    <x-neev-component::danger-button type="submit" @click.prevent="if (confirm('{{__('Are you sure you want to delete the domain?')}}')) $el.closest('form').submit();">{{__('Delete')}}</x-neev-component::danger-button>
                                </form>
                            </div>
                        </div>
                    @endif

                    @if ($outside_members)
                        <div class="flex gap-2 items-center justify-between">
                            <div class="text-2xl">🚨</div>
                            <div class="text-yellow-700">{{$outside_members}} team members are using email addresses outside your verified domain ({{ '@'.$team->federated_domain }}). To keep your team secure, remove these users or ask them to rejoin using a matching email address.</div>
                        </div>
                    @endif
                </x-slot>
            </x-neev-component::card>
            
            {{-- Domain Rules --}}
            <x-neev-component::card>
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
            </x-neev-component::card>

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
                    <form method="POST" action="{{route('teams.domain', $team->id)}}">
                        @csrf
                        @method('PUT')
                        <x-neev-component::button name="verify" value="verify">
                            {{ __('Verify') }}
                        </x-neev-component::button>
                    </form>
                </x-slot>
            </x-neev-component::dialog-modal>
        </div>
    @endif
</x-neev-layout::app>
