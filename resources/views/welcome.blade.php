<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'Laravel') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="font-sans antialiased">
    <main class="flex min-h-screen items-center justify-center bg-gray-100 px-6 py-12">
        <div class="w-full max-w-xl rounded-lg bg-white p-8 text-center shadow-lg">
            <h1 class="text-3xl font-bold text-gray-900">Grade A Paint Center</h1>
            <p class="mt-2 text-gray-600">Paint sales and inventory management.</p>
            <div class="mt-6 flex justify-center gap-3">
                @auth
                    <a href="{{ route('dashboard') }}"
                        class="rounded-md bg-[#00a3cc] px-4 py-2 text-sm font-semibold text-white">Dashboard</a>
                @else
                    <a href="{{ route('login') }}"
                        class="rounded-md bg-[#00a3cc] px-4 py-2 text-sm font-semibold text-white">Log in</a>
                @endauth
            </div>
        </div>
    </main>
</body>

</html>
