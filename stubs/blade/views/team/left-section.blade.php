<nav class="space-y-2">
    <a href="{{ route('teams.profile', $team->id) }}"
       class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('teams.profile') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Profile
    </a>
    @if ($team->allUsers->where('id', $user->id)->first()?->membership->joined)
        <a href="{{ route('teams.members', $team->id) }}"
        class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('teams.members') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
            Members
        </a>
        <a href="{{ route('teams.email-domains', $team->id) }}"
        class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('teams.email-domains') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
            Email Domains
        </a>
        <a href="{{ route('teams.hostnames', $team->id) }}"
        class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('teams.hostnames') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
            Hostnames
        </a>
        <a href="{{ route('teams.settings', $team->id) }}"
        class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('teams.settings') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
            Settings
        </a>
    @endif
</nav>
