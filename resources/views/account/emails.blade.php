<x-app>
    <x-slot name="leftsection">
        {{ view('neev::account.left-section', ['user' => $user]) }}
    </x-slot>
    <x-validation-errors class="mb-4" />
    <x-validation-status class="mb-4" />
    <x-card x-data="{addEmailOpen: false}" class="select-none">
        {{-- Title --}}
        <x-slot name="title">
            Emails
        </x-slot>

        {{-- Action --}}
        <x-slot name="action" class="flex">
            <div>
                <div x-show="!addEmailOpen" x-on:click="addEmailOpen = true" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" />
                    </svg>
                </div>

                <div x-show="addEmailOpen" x-on:click="addEmailOpen = false" class="cursor-pointer border border-2 border-gray-500 text-gray-500 rounded-full shadow">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M5 10a1 1 0 011-1h8a1 1 0 110 2H6a1 1 0 01-1-1z" clip-rule="evenodd" />
                    </svg>
                </div>
            </div>
        </x-slot>

        {{-- Content --}}
        <x-slot name="content" >
            <div class="flex flex-col gap-4">
                <form method="POST" class="flex gap-2 items-center justify-between" x-show="addEmailOpen" x-transition action="{{ route('emails.add') }}" class="flex flex-col gap-2">
                    @csrf

                    <div class="flex gap-2 items-center w-1/2">
                        <x-label for="email" value="{{ __('New Email Address') }}" class="w-1/3" />
                        <x-input id="email" class="block w-2/3" type="email" name="email" required />
                    </div>

                    <div class="text-end">
                        <x-button>
                            {{ __('Add') }}
                        </x-button>
                    </div>
                </form>
                <x-table>
                    <x-slot name="body">
                        @foreach ($user->emails()->orderBy('created_at')->get() as $email)
                            <x-table-body-tr class="odd:bg-white even:bg-gray-50">
                                <td class="px-6 py-2 text-start">
                                    <div class="flex gap-2">
                                        <p>{{ $email->email ?? '--' }}</p>
                                        @if ($email->email === $user->email)
                                            <span class="border border-blue-600 text-xs tracking-tight text-blue-600 rounded-full px-2">{{ 'Primary' }}</span>
                                        @endif

                                        @if ($email->verified_at)
                                            <span class="border border-green-700 text-xs tracking-tight text-green-700 rounded-full px-2">{{ 'Verified' }}</span>
                                        @else
                                            <span class="border border-yellow-700 text-xs tracking-tight text-yellow-700 rounded-full px-2">{{ 'Unverified' }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-6 py-2 text-center">{{ $email->created_at->diffForHumans() ?? '--' }}</td>
                                <td class="px-6 py-2 text-end">
                                    <div class="flex gap-4 justify-end">
                                        @if (!$email->verified_at)
                                            <form method="GET" action="{{ route('verification.send') }}">
                                                @csrf
                                                <input type="hidden" name="email" value="{{$email->email}}">
                                                <div>
                                                    <x-button>
                                                        {{ __('Verify Email') }}
                                                    </x-button>
                                                </div>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{route('emails.delete')}}">
                                            @csrf
                                            @method('DELETE')

                                            <input type="hidden" name="email_id" value="{{$email->id}}">
                                            <x-danger-button x-bind:disabled="{{$email->email === $user->email ? 'true' : 'false'}}" type="submit">{{ __('Delete') }}</x-danger-button>
                                        </form>
                                    </div>
                                </td>
                            </x-table-body-tr>
                        @endforeach
                    </x-slot>
                </x-table>
                <div class="flex justify-between gap-2 items-center border shadow px-4 py-2 rounded-lg">
                    <div>
                        <h1 class="font-bold">Primary email address</h1>
                        <p class="text-sm">Select an email to be used for account-related notifications and can be used for password reset.</p>
                    </div>
                    <form method="POST" action="{{route('emails.primary')}}">
                        @csrf
                        @method('PUT')
                        <select name="email" class="border rounded-md px-2 py-1" onchange="this.form.submit()">
                            @foreach ($user->emails->whereNotIn('verified_at', ['', null]) as $email)
                                <option value="{{$email->email}}" x-bind:selected="{{$user->email === $email->email ? 'true' : 'false'}}">{{$email->email}}</option>
                            @endforeach
                        </select>
                    </form>
                </div>
            </div>
       </x-slot>
    </x-card>
</x-app>