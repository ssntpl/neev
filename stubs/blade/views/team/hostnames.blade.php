<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev::team.left-section', ['team' => $team, 'user' => $user]) }}
    </x-slot>
    <x-neev-component::validation-errors class="mb-4" />
    <x-neev-component::validation-status class="mb-4" />
    @php($isOwner = $team->user_id === $user->id)
    <div x-data="{show: {{session('token') ? 'true' : 'false'}} }" class="flex flex-col gap-4">
        <x-neev-component::card x-data="{addHostOpen: false}">
            <x-slot name="title">
                {{ __('Hostnames') }}
            </x-slot>

            <x-slot name="action" class="flex">
                @if ($isOwner)
                    <div x-on:click="addHostOpen = !addHostOpen" class="cursor-pointer border-2 border-gray-500 text-gray-500 rounded-full shadow">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                            <path x-show="!addHostOpen" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                            <path x-show="addHostOpen" fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                        </svg>
                    </div>
                @endif
            </x-slot>

            <x-slot name="content">
                @if ($isOwner)
                    <form class="flex gap-4 items-center" x-show="addHostOpen" x-transition method="POST" action="{{ route('teams.hostnames.store', $team->id) }}">
                        @csrf
                        <x-neev-component::label for="host" value="{{ __('Host') }}" />
                        <x-neev-component::input id="host" class="block mt-1 w-1/2" type="text" name="host" placeholder="app.example.com" required autofocus />
                        <x-neev-component::button>{{ __('Add Host') }}</x-neev-component::button>
                    </form>
                @endif

                @if ($platformHost)
                    <div class="flex gap-2 items-center justify-between px-2">
                        <div class="font-semibold w-2/5">{{ $platformHost }}</div>
                        <div class="w-2/5 text-sm text-gray-600 dark:text-gray-400">{{ __('Platform subdomain, follows the team slug') }}</div>
                        <div class="w-1/5 text-center">
                            @if (!$team->primaryHostname?->isVerified())
                                <span class="border border-blue-600 text-sm tracking-tight text-blue-600 rounded-full px-2">{{ __('Primary') }}</span>
                            @endif
                        </div>
                    </div>
                    <div class="border-b"></div>
                @endif

                <div class="flex flex-col gap-2 px-2">
                    @forelse ($hostnames as $hostname)
                        <div class="flex gap-2 items-center justify-between">
                            <div class="font-semibold w-2/5">{{ $hostname->host }}</div>

                            @if ($hostname->isVerified())
                                <div class="w-1/5 text-center">
                                    <span class="border border-green-700 text-sm tracking-tight text-green-700 rounded-full px-2">{{ ucfirst($hostname->status) }}</span>
                                </div>
                                <div class="w-1/5 text-center">
                                    @if ($team->primary_hostname_id === $hostname->id)
                                        <span class="border border-blue-600 text-sm tracking-tight text-blue-600 rounded-full px-2">{{ __('Primary') }}</span>
                                    @elseif ($isOwner)
                                        <form method="POST" action="{{ route('teams.hostnames.update', $hostname->id) }}">
                                            @csrf
                                            @method('PUT')
                                            <x-neev-component::button name="primary" value="primary">{{ __('Make Primary') }}</x-neev-component::button>
                                        </form>
                                    @endif
                                </div>
                            @elseif ($isOwner)
                                <form method="POST" class="w-1/5 text-center" action="{{ route('teams.hostnames.update', $hostname->id) }}">
                                    @csrf
                                    @method('PUT')
                                    <x-neev-component::button name="token" value="token">{{ __('Get Token') }}</x-neev-component::button>
                                </form>
                                <form method="POST" class="w-1/5 text-center" action="{{ route('teams.hostnames.update', $hostname->id) }}">
                                    @csrf
                                    @method('PUT')
                                    <x-neev-component::button name="verify" value="verify">{{ __('Verify') }}</x-neev-component::button>
                                </form>
                            @else
                                <div class="w-2/5 text-center text-sm text-gray-600">{{ ucfirst($hostname->status) }}</div>
                            @endif

                            @if ($isOwner)
                                <form method="POST" action="{{ route('teams.hostnames.destroy', $hostname->id) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-neev-component::danger-button type="submit" @click.prevent="if (confirm('{{ __('Are you sure you want to delete the host?') }}')) $el.closest('form').submit();">{{ __('Delete') }}</x-neev-component::danger-button>
                                </form>
                            @endif
                        </div>
                        <div class="border-b"></div>
                    @empty
                        <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No custom hosts yet.') }}</p>
                    @endforelse
                </div>
            </x-slot>
        </x-neev-component::card>

        <x-neev-component::dialog-modal>
            <x-slot name="title">
                {{ __('DNS Token') }}
            </x-slot>

            <x-slot name="content">
                {{ __('Add this TXT record to your domain’s DNS.') }}
                <div class="mt-2 text-sm font-semibold">{{ __('Type') }}</div>
                <div class="bg-gray-100 px-2 py-1 rounded text-sm">TXT</div>

                <div class="mt-2 text-sm font-semibold">{{ __('Name') }}</div>
                <div class="bg-gray-100 px-1 rounded flex gap-2 justify-between items-center">
                    <input type="text" x-ref="dnsRecordNameInput" readonly class="bg-transparent border-0 px-1 w-full text-sm" value="{{ session('dns_record_name') }}">
                    <x-neev-component::button type="button" @click="navigator.clipboard.writeText($refs.dnsRecordNameInput.value)">
                        {{ __('Copy') }}
                    </x-neev-component::button>
                </div>

                <div class="mt-2 text-sm font-semibold">{{ __('Value') }}</div>
                <div class="bg-gray-100 px-1 rounded flex gap-2 justify-between items-center">
                    <input type="text" x-ref="newTokenInput" readonly class="bg-transparent border-0 px-1 w-full text-sm" value="{{ session('token') }}">
                    <x-neev-component::button type="button" @click="navigator.clipboard.writeText($refs.newTokenInput.value)">
                        {{ __('Copy') }}
                    </x-neev-component::button>
                </div>
            </x-slot>

            <x-slot name="footer">
            </x-slot>
        </x-neev-component::dialog-modal>
    </div>
</x-neev-layout::app>
