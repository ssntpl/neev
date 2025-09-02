<x-neev-layout::app>
    <x-slot name="leftsection">
        {{ view('neev.account.left-section', ['user' => $user]) }}
    </x-slot>

    <x-neev-component::card>
        <x-slot name="title">
            {{ __('Login History') }}
        </x-slot>

        <x-slot name="action">
            <button onclick="location.reload();" class="cursor-pointer ml-4">
                <x-neev-component::refresh-button/>
            </button>
        </x-slot>

        <x-slot name="content">
            @if (count($history ?? []) > 0)
                <x-neev-component::table>
                    <x-slot name="head">
                        <tr>
                            <th class="px-6 py-3 text-center font-bold tracking-wide">Method</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wide">MFA</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wide">Device</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wide">Location</th>
                            <th class="px-6 py-3 text-center font-bold tracking-wide">Logged in at</th>
                        </tr>
                    </x-slot>
                    <x-slot name="body">
                        @foreach ($history as $login)
                            <x-neev-component::table-body-tr class="odd:bg-white even:bg-gray-50">
                                <td class="px-6 py-4 text-center capitalize">{{ $login->method ?? '--' }}</td>
                                <td class="px-6 py-4 text-center">{{ $login->multi_factor_method ?? '--' }}</td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex gap-2 justify-self-center">
                                        @if ($login->device === 'Desktop')
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-10 text-gray-500">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                                            </svg>
                                        @else
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-8 h-10 text-gray-500">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 006 3.75v16.5a2.25 2.25 0 002.25 2.25h7.5A2.25 2.25 0 0018 20.25V3.75a2.25 2.25 0 00-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                                            </svg>
                                        @endif
                                        <div>
                                            <p>{{$login->platform ?? __('Unknown')}} - {{$login->browser ?? __('Unknown')}}</p>
                                            <p>{{$login->ip_address ?? ''}}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if ($login->location)
                                        {{ $login->location['city'] ?? '' }},
                                        {{ $login->location['state'] ?? '' }},
                                        {{ $login->location['country'] ?? '' }}
                                    @else
                                        --
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-center">{{ $login->created_at->diffForHumans() ?? '--' }}</td>
                            </x-neev-component::table-body-tr>
                        @endforeach
                    </x-slot>
                </x-neev-component::table>
            @endif
        </x-slot>
    </x-neev-component::card>
</x-neev-layout::app>