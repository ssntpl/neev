@props(['user'])

@php
    $unreadCount = $user->unreadNotifications()->count();
@endphp

<div class="relative">
    <button type="button" class="relative p-1 text-gray-400 hover:text-gray-500" onclick="toggleNotifications()">
        <span class="sr-only">View notifications</span>
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        @if($unreadCount > 0)
            <span class="absolute -top-1 -right-1 h-4 w-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div id="notifications-dropdown" class="hidden absolute -right-10 mt-3 w-96 bg-white rounded-xl shadow-lg ring-1 ring-black ring-opacity-5 z-50">
        <div class="py-1">
            <div class="flex justify-between px-4 py-2 border-b">
                <h3 class="text-lg font-medium text-gray-900">Notifications</h3>
                @if($unreadCount > 0)
                    <button onclick="markAllAsRead()" class="text-sm text-indigo-600 hover:text-indigo-500">Mark all as read</button>
                @endif
            </div>
            <div class="max-h-64 overflow-y-auto">
                @forelse($user->unreadNotifications()->limit(10)->get() as $notification)
                    <div class="px-4 py-1 hover:bg-gray-50 {{ $notification->read_at ? '' : 'bg-blue-50' }} cursor-pointer" 
                         onclick="handleNotificationClick('{{ $notification->id }}', '{{ $notification->data['link'] ?? '' }}')">
                        <p class="text-sm font-medium text-gray-900">{{ $notification->data['title'] ?? 'Notification' }}</p>
                        <p class="text-xs text-gray-400 mt-1">{{ $notification->created_at->diffForHumans() }}</p>
                    </div>
                @empty
                    <div class="px-4 py-3 text-sm text-gray-500">No notifications</div>
                @endforelse
            </div>
            <div class="border-t px-4 py-2 flex justify-end">
                <a href="{{ route('account.notifications') }}" class="text-sm text-indigo-600 hover:text-indigo-500">View all notifications</a>
            </div>
        </div>
    </div>
</div>

<script>
    function toggleNotifications() {
        const dropdown = document.getElementById('notifications-dropdown');
        dropdown.classList.toggle('hidden');
    }

    function handleNotificationClick(notificationId, link) {
        fetch('/neev/notifications/markRead', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ id: notificationId })
        }).then(() => {
            if (link) {
                window.open(link, '_blank');
            }
            location.reload();
        });
    }

    function markAllAsRead() {
        fetch('/neev/notifications/markAllRead', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json'
            },
        }).then(() => {
            location.reload();
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(event) {
        const dropdown = document.getElementById('notifications-dropdown');
        const button = event.target.closest('button');
        
        if (!button || !button.onclick || button.onclick.toString().indexOf('toggleNotifications') === -1) {
            dropdown.classList.add('hidden');
        }
    });
</script>