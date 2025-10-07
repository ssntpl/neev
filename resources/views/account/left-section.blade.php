<nav class="space-y-2">
    <a href="{{ route('account.profile') }}"
       class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.profile') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Profile
    </a>
    
    <a href="{{ route('account.emails') }}"
       class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.emails') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Emails
    </a>

    <a href="{{ route('account.security') }}"
    class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.security') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Security
    </a>

    <a href="{{ route('account.tokens') }}"
    class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.tokens') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        API Tokens
    </a>

    @if (config('neev.team'))
        <a href="{{ route('account.teams') }}"
        class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.teams') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
            Teams
        </a>
    @endif

    <a href="{{ route('account.sessions') }}"
    class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.sessions') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Sessions
    </a>

    <a href="{{ route('account.loginAttempts') }}"
    class="block px-4 py-2 rounded-md text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 {{ request()->routeIs('account.loginAttempts') ? 'bg-gray-200 dark:bg-gray-700 font-semibold' : '' }}">
        Login Attempts
    </a>
</nav>
