<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ env('APP_NAME') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="font-sans antialiased">
        <div class="h-screen flex flex-col bg-gray-100 dark:bg-gray-900">
            <header class="bg-white dark:bg-gray-800 shadow px-2">
                {{ view('neev::navigation-menu') }}
            </header>

            <div class="flex flex-1 overflow-hidden">
                @if (isset($leftsection))
                    <aside class="w-1/6 bg-white dark:bg-gray-800 shadow overflow-y-auto">
                        <div class="py-6 px-4 sm:px-6 lg:px-8">
                            {{ $leftsection }}
                        </div>
                    </aside>
                    
                    <main class="w-5/6 overflow-y-auto">
                        <div class="py-6 px-4 sm:px-6 lg:px-8">
                            {{ $slot }}
                        </div>
                    </main>
                @else
                    <main class="w-full overflow-y-auto">
                        <div class="py-6 px-4 sm:px-6 lg:px-8">
                            {{ $slot }}
                        </div>
                    </main>
                @endif
            </div>
        </div>

        <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js"></script>
        <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-messaging-compat.js"></script>

        <script type="module">
            (async function() {
                const firebaseConfig = {
                    apiKey: "{{ config('neev.firebase.api_key') }}",
                    authDomain: "{{ config('neev.firebase.auth_domain') }}",
                    projectId: "{{ config('neev.firebase.project_id') }}",
                    storageBucket: "{{ config('neev.firebase.storage_bucket') }}",
                    messagingSenderId: "{{ config('neev.firebase.messaging_sender_id') }}",
                    appId: "{{ config('neev.firebase.app_id') }}"
                };

                firebase.initializeApp(firebaseConfig);

                async function getFirebaseToken() {
                    try {
                        if (!('Notification' in window)) return null;

                        const permission = await Notification.requestPermission();
                        if (permission !== 'granted') return null;

                        const registration = await navigator.serviceWorker.ready;
                        const messaging = firebase.messaging();
                        const vapidKey = "{{ config('neev.firebase.vapid_key') }}";
                        return await messaging.getToken({ vapidKey, serviceWorkerRegistration: registration });
                    } catch (error) {
                        console.error('Firebase token error:', error);
                        return null;
                    }
                }

                async function registerDevice() {
                    const device_id = localStorage.getItem('fcm_device_id');
                    if (device_id > 0) {
                        try {
                            if (Notification.permission !== 'granted') {
                                localStorage.removeItem('fcm_device_id');
                                const response = await fetch('/neev/device/register', {
                                    method: 'DELETE',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                        'Accept': 'application/json'
                                    },
                                    body: JSON.stringify({ device_id: device_id })
                                });
    
                                if (response.ok) {
                                    console.log('Device deletion successfully');
                                }
                                return;
                            } else {
                                const response = await fetch('/neev/device/register', {
                                    method: 'PUT',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                        'Accept': 'application/json'
                                    },
                                    body: JSON.stringify({ device_id: device_id })
                                });
    
                                if (response.ok) {
                                    const data = await response.json();
                                    if (data?.status == 'unauthorized') {
                                        localStorage.removeItem('fcm_device_id');
                                    } else {
                                        console.log('Device updated successfully');
                                        return;
                                    }
                                }
                            }
                        } catch (error) {
                            console.error('Device update error:', error);
                        }
                    };
                    const token = await getFirebaseToken();
                    if (!token) return;

                    try {
                        const response = await fetch('/neev/device/register', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({ device_token: token })
                        });

                        if (response.ok) {
                            const data = await response.json();
                            if (data?.device_id) {
                                localStorage.setItem('fcm_device_id', data?.device_id);
                                console.log('Device registered successfully');
                            }
                        }
                    } catch (error) {
                        console.error('Device registration error:', error);
                    }
                }

                if ('serviceWorker' in navigator) {
                    try {
                        const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
                        console.log('Service Worker registered with scope:', registration.scope);
                        await registerDevice(); // Only register device after SW is ready
                    } catch (err) {
                        console.error('Service Worker registration failed:', err);
                    }
                }
            })();
        </script>
    </body>

</html>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>