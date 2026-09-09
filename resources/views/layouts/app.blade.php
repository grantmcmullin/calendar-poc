<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title', config('app.name', 'Laravel'))</title>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-gray-50 text-gray-900">
        <header class="border-b border-gray-200 bg-white">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-6 py-4">
                <span class="font-semibold">{{ config('app.name', 'Laravel') }}</span>
                <nav class="flex items-center gap-4 text-sm">
                    <a href="{{ route('setup.show') }}" class="text-gray-700 hover:text-gray-900">Setup</a>
                    <a href="{{ route('demo.show') }}" class="text-gray-700 hover:text-gray-900">Demo</a>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-6 py-8">
            @if (session('status'))
                <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            @yield('content')
        </main>
    </body>
</html>
