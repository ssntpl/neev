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
        {{-- @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.js']) --}}
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
    </body>

</html>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>