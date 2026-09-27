<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Grade A Paint Center') }}</title>

    <!-- Fonts: Inter for UI body, Montserrat for headings/labels -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|montserrat:600,700&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="h-full font-sans antialiased bg-slate-100 text-slate-900 selection:bg-[#008fb3] selection:text-white">
    <!-- Global Toast Notification System -->
    <div x-data="{
            toasts: [],
            add(toast) {
                const id = Date.now() + Math.random();
                this.toasts.push({
                    id: id,
                    type: toast.type || 'success',
                    message: toast.message,
                });
                setTimeout(() => this.remove(id), 4000);
            },
            remove(id) {
                this.toasts = this.toasts.filter(t => t.id !== id);
            }
        }"
        x-init="
            @if (session('status')) add({ message: {{ json_encode(session('status')) }}, type: 'success' }); @endif
            @if (session('error')) add({ message: {{ json_encode(session('error')) }}, type: 'error' }); @endif
            window.addEventListener('toast', e => add(e.detail));
        "
        class="fixed top-4 right-4 z-50 flex flex-col gap-2 max-w-sm w-full pointer-events-none">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-transition:enter="transition ease-out duration-200 transform"
                 x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-150 transform"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-1 scale-95"
                 class="pointer-events-auto flex items-start justify-between gap-3 p-3 rounded border bg-white shadow-md text-xs font-medium text-slate-800"
                 :class="{
                     'border-slate-300 border-l-4 border-l-emerald-600': toast.type === 'success',
                     'border-slate-300 border-l-4 border-l-rose-600': toast.type === 'error',
                     'border-slate-300 border-l-4 border-l-amber-500': toast.type === 'warning',
                     'border-slate-300 border-l-4 border-l-[#008fb3]': toast.type === 'info'
                 }">
                <div class="flex items-center gap-2">
                    <template x-if="toast.type === 'success'">
                        <span class="text-emerald-700 font-bold">&#x2713;</span>
                    </template>
                    <template x-if="toast.type === 'error'">
                        <span class="text-rose-700 font-bold">&#x2715;</span>
                    </template>
                    <template x-if="toast.type === 'warning'">
                        <span class="text-amber-600 font-bold">&#x26A0;</span>
                    </template>
                    <template x-if="toast.type === 'info'">
                        <span class="text-[#008fb3] font-bold">&#x2139;</span>
                    </template>
                    <span class="text-slate-800 leading-snug" x-text="toast.message"></span>
                </div>
                <button type="button" @click="remove(toast.id)" class="text-slate-400 hover:text-slate-700 text-sm font-bold shrink-0 leading-none">&times;</button>
            </div>
        </template>
    </div>

    <!-- Application Shell: Persistent Vertical Sidebar + Content Area -->
    <div class="min-h-screen bg-slate-100 sm:flex sm:h-screen sm:overflow-hidden">
        <!-- Vertical Sidebar Navigation -->
        <livewire:layout.navigation />

        <!-- Main Window -->
        <div class="min-w-0 flex-1 flex flex-col sm:overflow-y-auto">
            <!-- Top Header with Brand & Authenticated User/Logout in Top-Right -->
            <header class="bg-white border-b border-slate-300 sticky top-0 z-30 shrink-0">
                <div class="flex h-12 items-center justify-between px-4 sm:px-6">
                    <!-- Left: Header / Breadcrumb / Store Info -->
                    <div class="flex items-center gap-3">
                        <span class="font-heading font-bold text-xs uppercase tracking-wider text-slate-800">
                            Grade A Paint Center
                        </span>
                        <span class="text-slate-300">|</span>
                        <span class="text-xs text-slate-500 hidden sm:inline">Lapu-Lapu St., Agdao, Davao City</span>
                        @if (isset($header))
                            <span class="text-slate-300 hidden md:inline">/</span>
                            <div class="text-xs font-semibold text-slate-700 hidden md:inline">{{ $header }}</div>
                        @endif
                    </div>

                    <!-- Right: Authenticated User Display & Logout Button in Top-Right -->
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-slate-800">{{ auth()->user()->name }}</span>
                            <!-- Clean minimal status badge: colored border + colored text + transparent background -->
                            <span class="px-1.5 py-0.5 rounded border border-slate-400 text-slate-700 bg-transparent text-[10px] font-bold uppercase tracking-wider">
                                {{ auth()->user()->role }}
                            </span>
                        </div>
                        <span class="text-slate-200">|</span>
                        <a href="{{ route('profile') }}" wire:navigate class="px-2 py-1 text-xs font-medium text-slate-600 hover:text-slate-900 rounded hover:bg-slate-100 transition" title="Profile Settings">
                            Profile
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="inline-flex items-center px-2.5 py-1 rounded border border-slate-300 bg-white text-xs font-medium text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition shadow-sm">
                                Log Out
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <!-- Page Content -->
            <main class="flex-1 bg-slate-100 p-4 sm:p-6 lg:p-8 min-w-0 w-full">
                {{ $slot }}
            </main>
        </div>
    </div>
</body>

</html>
