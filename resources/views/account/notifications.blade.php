<x-neev-layout::app>
    <div class="py-12">
        <div class="w-full mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div>
                    <div class="flex justify-between">
                        <h2 class="text-xl font-medium text-gray-900">Notifications {{ count($user->notifications) > 0 ? '('.count($user->notifications).')' : '' }}</h2>
                        @if(count($user->notifications) > 0)
                            <button onclick="markAllAsRead()" class="text-md mr-2 text-indigo-600 hover:text-indigo-500">Mark all as read</button>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-gray-600">View your recent notifications.</p>
                    
                    <div class="mt-6 space-y-4">
                        @if (count($user->notifications) > 0)
                            @foreach($user->unreadNotifications as $notification)
                                <div class="py-3 px-4 border rounded-lg {{ $notification->read_at ? 'bg-gray-50' : 'bg-blue-50' }}">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h3 class="font-medium">
                                                <span>
                                                    {{ $notification->data['title'] ?? 'Notification' }}
                                                </span>
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                    New
                                                </span>
                                            </h3>
                                            <p class="text-sm text-gray-600 mt-1">{{ $notification->data['body'] ?? '' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</p>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                            @foreach($user->readNotifications ?? [] as $notification)
                                <div class="py-3 px-4 border rounded-lg {{ $notification->read_at ? 'bg-gray-50' : 'bg-blue-50' }}">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h3 class="font-medium">{{ $notification->data['title'] ?? 'Notification' }}</h3>
                                            <p class="text-sm text-gray-600 mt-1">{{ $notification->data['body'] ?? '' }}</p>
                                        </div>
                                        <div>
                                            <p class="text-xs text-gray-500">{{ $notification->created_at->diffForHumans() }}</p>
                                            <div class="mt-2 flex justify-end">
                                                <button onclick="deleteNotification('{{ $notification->id }}')" class="text-red-600 hover:text-red-800 text-sm">Delete</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        @else
                            <p class="text-gray-500">No notifications yet.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-neev-layout::app>
<script>
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
    
    function deleteNotification(notificationId) {
        fetch('/neev/notifications', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ id: notificationId })
        }).then(() => {
            location.reload();
        });
    }
</script>