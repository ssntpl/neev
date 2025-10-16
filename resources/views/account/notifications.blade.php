<x-neev-layout::app>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="max-w-xl">
                    <h2 class="text-lg font-medium text-gray-900">Notifications</h2>
                    <p class="mt-1 text-sm text-gray-600">View your recent notifications.</p>
                    
                    <div class="mt-6 space-y-4">
                        @forelse($notifications as $notification)
                            <div class="p-4 border rounded-lg {{ $notification->read_at ? 'bg-gray-50' : 'bg-blue-50' }}">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <h3 class="font-medium">{{ $notification->data['title'] ?? 'Notification' }}</h3>
                                        <p class="text-sm text-gray-600 mt-1">{{ $notification->data['body'] ?? '' }}</p>
                                        <p class="text-xs text-gray-500 mt-2">{{ $notification->created_at->diffForHumans() }}</p>
                                    </div>
                                    @if(!$notification->read_at)
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            New
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @empty
                            <p class="text-gray-500">No notifications yet.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-neev-layout::app>