<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    {{-- <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" /> --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=liter:400" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans text-gray-900 antialiased">
    <div class="flex min-h-screen">
        {{-- Left panel: background image --}}
        <div class="hidden sm:flex sm:w-2/5 bg-slate-900 relative overflow-hidden">
            <img
                src="{{ asset('images/login_background.jpg') }}"
                alt="Grade A Paint Center"
                class="absolute inset-0 w-full h-full object-cover opacity-80"
            >
            <div class="absolute inset-0 bg-gradient-to-t from-slate-900/70 to-slate-900/20"></div>
            <div class="relative z-10 flex flex-col justify-end p-10 text-white">
                <h2 class="text-2xl font-bold tracking-tight leading-snug">Grade A Paint Center</h2>
                <p class="mt-1 text-sm text-white/70">Lapu-Lapu St., Agdao, Davao City</p>
            </div>
        </div>

        {{-- Right panel: login form --}}
        <div class="flex flex-1 flex-col bg-white shadow-2xl">
            <div class="flex flex-1 items-center px-6 py-12 sm:px-12">
                <div class="mx-auto w-full max-w-md rounded-lg border border-gray-100 bg-white p-6 shadow-lg sm:p-8">
                    <div class="flex flex-col gap-6">
                        <div>
                            <h1 class="text-3xl font-bold text-gray-900">Welcome back!</h1>
                            <p class="mt-2 text-md font-medium text-gray-700">Please enter your authenticated
                                credentials to continue.
                            </p>
                        </div>
                        {{ $slot }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>
