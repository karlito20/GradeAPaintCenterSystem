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
                const data = Array.isArray(toast) ? toast[0] : (toast || {});
                const type = data.type || (typeof data === 'string' ? 'info' : 'success');
                const message = data.message || (typeof data === 'string' ? data : '');
                if (!message) return;
                const id = Date.now() + Math.random();
                this.toasts.push({ id, type, message });
                setTimeout(() => this.remove(id), 3500);
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
        class="fixed top-4 right-4 z-50 flex flex-col items-end gap-2 pointer-events-none max-w-sm">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-transition:enter="transition ease-out duration-200 transform"
                 x-transition:enter-start="opacity-0 translate-y-1 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-150 transform"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-1 scale-95"
                 class="pointer-events-auto flex items-center justify-between gap-3 px-3.5 py-2.5 rounded-lg border bg-white shadow-lg text-xs font-medium text-slate-800"
                 :class="{
                     'border-emerald-300 bg-emerald-50/50 text-emerald-950': toast.type === 'success',
                     'border-rose-300 bg-rose-50/50 text-rose-950': toast.type === 'error',
                     'border-amber-300 bg-amber-50/50 text-amber-950': toast.type === 'warning',
                     'border-sky-300 bg-sky-50/50 text-sky-950': toast.type === 'info'
                 }">
                <div class="flex items-center gap-2">
                    <template x-if="toast.type === 'success'">
                        <svg class="h-4 w-4 shrink-0 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </template>
                    <template x-if="toast.type === 'error'">
                        <svg class="h-4 w-4 shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    </template>
                    <template x-if="toast.type === 'warning'">
                        <svg class="h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </template>
                    <template x-if="toast.type === 'info'">
                        <svg class="h-4 w-4 shrink-0 text-[#00a3cc]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </template>
                    <span class="leading-snug" x-text="toast.message"></span>
                </div>
                <button type="button" @click="remove(toast.id)" class="text-slate-400 hover:text-slate-700 text-sm font-bold shrink-0 ml-2">&times;</button>
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

                    <!-- Right: Live Date/Time + Authenticated User Display & Logout -->
                    <div class="flex items-center gap-3">
                        <!-- Live Date & Time -->
                        <span x-data="{
                                time: '{{ now()->format('D, M j, Y · H:i:s') }}',
                                update() {
                                    const now = new Date();
                                    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                    const pad = (n) => String(n).padStart(2, '0');
                                    this.time = `${days[now.getDay()]}, ${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()} · ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
                                }
                            }"
                            x-init="update(); setInterval(() => update(), 1000)"
                            x-text="time"
                            class="hidden sm:inline-block text-xs tabular-nums text-slate-500 font-mono">
                            {{ now()->format('D, M j, Y · H:i:s') }}
                        </span>
                        <span class="text-slate-200 hidden sm:inline">|</span>
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-slate-800">{{ auth()->user()->name }}</span>
                            <!-- Role label: no bullet point -->
                            <span class="text-[10px] font-bold uppercase tracking-wider text-slate-500">
                                {{ auth()->user()->role }}
                            </span>
                        </div>
                        <span class="text-slate-200">|</span>
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
