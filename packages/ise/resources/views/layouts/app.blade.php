<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? config('ise.app_name', 'Marque') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <div class="flex min-h-screen flex-col">
        {{-- Navigation --}}
        <livewire:id-navigation />

        {{-- Page Content --}}
        <main class="flex-1">
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                {{ $slot }}
            </div>
        </main>

        {{-- Footer --}}
        @if(config('ise.show_footer', true))
            @include('ise::components.footer')
        @endif
    </div>

    @livewireScripts
</body>
</html>
