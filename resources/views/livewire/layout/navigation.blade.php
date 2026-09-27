<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public bool $collapsed = false;

    public function toggleNavigation(): void
    {
        $this->open = !$this->open;
    }

    public function toggleSidebar(): void
    {
        $this->collapsed = !$this->collapsed;
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<nav class="bg-white border-r border-slate-200 sm:sticky sm:top-0 sm:flex sm:h-screen sm:shrink-0 sm:flex-col sm:overflow-y-auto transition-all duration-200 {{ $collapsed ? 'sm:w-16' : 'sm:w-64' }}">
    <!-- Top Part: Empty/Clean with Sidebar Collapse Toggle Button -->
    <div class="flex items-center justify-between p-3 border-b border-slate-100 min-h-[52px]">
        <!-- Mobile hamburger toggle -->
        <button wire:click="toggleNavigation" type="button" class="sm:hidden text-slate-500 hover:text-slate-700 p-1">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16' }}" />
            </svg>
        </button>

        <!-- Desktop collapse / expand toggle button -->
        <button wire:click="toggleSidebar" type="button"
            title="{{ $collapsed ? 'Expand Sidebar' : 'Collapse Sidebar' }}"
            class="hidden sm:inline-flex items-center justify-center rounded p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition {{ $collapsed ? 'mx-auto' : 'ml-auto' }}">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                @if ($collapsed)
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                @endif
            </svg>
        </button>
    </div>

    <!-- Desktop Navigation Links (Light Theme, Bigger Text) -->
    <div class="hidden sm:flex flex-col flex-1 px-2.5 py-3 space-y-4 text-sm">
        <!-- 1. DASHBOARD -->
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate
               title="Dashboard"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('dashboard') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <rect width="7" height="9" x="3" y="3" rx="1" stroke-width="2" />
                    <rect width="7" height="5" x="14" y="3" rx="1" stroke-width="2" />
                    <rect width="7" height="9" x="14" y="12" rx="1" stroke-width="2" />
                    <rect width="7" height="5" x="3" y="16" rx="1" stroke-width="2" />
                </svg>
                @if (!$collapsed)
                    <span>Dashboard</span>
                @endif
            </a>
        </div>

        <!-- 2. SALES OPERATIONS -->
        <div class="space-y-1">
            @if (!$collapsed)
                <p class="px-3 pt-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
                    Sales
                </p>
            @endif

            <a href="{{ route('sales.index') }}" wire:navigate
               title="Point of Sale (POS)"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('sales.index') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('sales.index') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                @if (!$collapsed)
                    <span>Point of Sale (POS)</span>
                @endif
            </a>

            <a href="{{ route('products.index') }}" wire:navigate
               title="Products"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('products.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('products.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                @if (!$collapsed)
                    <span>Products</span>
                @endif
            </a>

            @if (auth()->user()?->canViewSalesHistory())
                <a href="{{ route('sales.history') }}" wire:navigate
                   title="Sales History"
                   class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('sales.history') || request()->routeIs('sales.receipt') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('sales.history') || request()->routeIs('sales.receipt') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Sales History</span>
                    @endif
                </a>
            @endif
        </div>

        <!-- 3. INVENTORY -->
        <div class="space-y-1">
            @if (!$collapsed)
                <p class="px-3 pt-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
                    Inventory
                </p>
            @endif

            <a href="{{ route('inventory.index') }}" wire:navigate
               title="Inventory Report"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.index') || request()->routeIs('reports.inventory') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('inventory.index') || request()->routeIs('reports.inventory') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                @if (!$collapsed)
                    <span>Inventory Report</span>
                @endif
            </a>

            <a href="{{ route('inventory.stock-in') }}" wire:navigate
               title="Stock In"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.stock-in') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('inventory.stock-in') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                </svg>
                @if (!$collapsed)
                    <span>Stock In</span>
                @endif
            </a>

            <a href="{{ route('inventory.physical-count') }}" wire:navigate
               title="Physical Count"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.physical-count') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('inventory.physical-count') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                @if (!$collapsed)
                    <span>Physical Count</span>
                @endif
            </a>

            <a href="{{ route('inventory.movements') }}" wire:navigate
               title="Movements"
               class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.movements') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('inventory.movements') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                </svg>
                @if (!$collapsed)
                    <span>Movements</span>
                @endif
            </a>
        </div>

        <!-- 4. ADMINISTRATION (dev, admin only) -->
        @if (auth()->user()->canAccessAdministration())
            <div class="space-y-1">
                @if (!$collapsed)
                    <p class="px-3 pt-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
                        Administration
                    </p>
                @endif

                <a href="{{ route('references.brands') }}" wire:navigate
                   title="Settings"
                   class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('references.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('references.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Settings</span>
                    @endif
                </a>

                <a href="{{ route('audit.index') }}" wire:navigate
                   title="Audit Trail"
                   class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('audit.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('audit.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Audit Trail</span>
                    @endif
                </a>

                <a href="{{ route('user-access.index') }}" wire:navigate
                   title="Users"
                   class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('user-access.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('user-access.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Users</span>
                    @endif
                </a>

                <a href="{{ route('backup.index') }}" wire:navigate
                   title="Backups"
                   class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('backup.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('backup.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                    </svg>
                    @if (!$collapsed)
                        <span>Backups</span>
                    @endif
                </a>

                @if (auth()->user()->canAccessTroubleshooting())
                    <a href="{{ route('dev.troubleshooting') }}" wire:navigate
                       title="Troubleshooting"
                       class="flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('dev.*') ? 'bg-purple-100 text-purple-900 font-semibold' : 'text-purple-700 hover:bg-purple-50' }}">
                        <svg class="h-5 w-5 shrink-0 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        @if (!$collapsed)
                            <span>Troubleshooting</span>
                        @endif
                    </a>
                @endif
            </div>
        @endif
    </div>

    <!-- Mobile Navigation Menu -->
    <div class="{{ $open ? 'block' : 'hidden' }} border-t border-slate-200 bg-white sm:hidden px-4 py-3 space-y-2 text-sm">
        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1">Operations</p>
        <a href="{{ route('dashboard') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('dashboard') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Dashboard</a>
        <a href="{{ route('sales.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('sales.index') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Point of Sale</a>
        <a href="{{ route('products.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('products.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Products</a>
        @if (auth()->user()?->canViewSalesHistory())
            <a href="{{ route('sales.history') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('sales.history') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Sales History</a>
        @endif

        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 pt-2">Inventory</p>
        <a href="{{ route('inventory.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.index') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Inventory Report</a>
        <a href="{{ route('inventory.stock-in') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.stock-in') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Stock In</a>
        <a href="{{ route('inventory.physical-count') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.physical-count') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Physical Count</a>
        <a href="{{ route('inventory.movements') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.movements') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Movements</a>

        @if (auth()->user()->canAccessAdministration())
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 pt-2">Administration</p>
            <a href="{{ route('references.brands') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('references.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Settings</a>
            <a href="{{ route('audit.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('audit.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Audit Trail</a>
            <a href="{{ route('user-access.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('user-access.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Users</a>
            <a href="{{ route('backup.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('backup.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Backups</a>
        @endif
    </div>
</nav>
