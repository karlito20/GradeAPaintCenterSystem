<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public bool $collapsed = false;
    public bool $productsExpanded = false;
    public bool $settingsExpanded = false;

    public function mount(): void
    {
        $rawCollapsed = session('sidebar_collapsed', request()->cookie('sidebar_collapsed', false));
        $this->collapsed = filter_var($rawCollapsed, FILTER_VALIDATE_BOOLEAN);

        // Auto-expand Products sub-menu if on products, stock-in, movements, or physical-count route
        $this->productsExpanded = request()->routeIs('products.*')
            || request()->routeIs('inventory.stock-in')
            || request()->routeIs('inventory.movements')
            || request()->routeIs('inventory.physical-count');
        
        // Auto-expand Settings sub-menu if on a references route
        $this->settingsExpanded = request()->routeIs('references.*');
    }

    public function toggleNavigation(): void
    {
        $this->open = ! $this->open;
    }

    public function toggleSidebar(): void
    {
        $this->collapsed = ! $this->collapsed;
        session(['sidebar_collapsed' => $this->collapsed]);
        cookie()->queue(cookie()->forever('sidebar_collapsed', $this->collapsed ? '1' : '0'));
    }

    public function toggleProducts(): void
    {
        $this->productsExpanded = ! $this->productsExpanded;
    }

    public function toggleSettings(): void
    {
        $this->settingsExpanded = ! $this->settingsExpanded;
    }

    public function logout(Logout $logout): void
    {
        $logout();
        $this->redirect('/', navigate: true);
    }
}; ?>

<nav class="bg-white border-r border-slate-200 sm:sticky sm:top-0 sm:flex sm:h-screen sm:shrink-0 sm:flex-col transition-all duration-200 no-scrollbar {{ $collapsed ? 'sm:w-16 sm:overflow-visible z-40' : 'sm:w-64 sm:overflow-y-auto' }}">
    <!-- Top Part: Empty/Clean with Sidebar Collapse Toggle Button -->
    <div class="flex items-center justify-between p-3 border-b border-slate-100 min-h-[52px] shrink-0 sticky top-0 bg-white z-20">
        <!-- Mobile hamburger toggle -->
        <button wire:click="toggleNavigation" type="button" class="sm:hidden text-slate-500 hover:text-slate-700 p-1">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $open ? 'M6 18L18 6M6 6l12 12' : 'M4 6h16M4 12h16M4 18h16' }}" />
            </svg>
        </button>

        <!-- Desktop collapse / expand toggle button -->
        <button wire:click="toggleSidebar" type="button"
            aria-label="{{ $collapsed ? 'Expand Sidebar' : 'Collapse Sidebar' }}"
            class="relative group hidden sm:inline-flex items-center justify-center rounded p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition {{ $collapsed ? 'mx-auto' : 'ml-auto' }}">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                @if ($collapsed)
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7" />
                @else
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7" />
                @endif
            </svg>
            @if ($collapsed)
                <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                    <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                        <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                        Expand Sidebar
                    </div>
                </div>
            @endif
        </button>
    </div>

    <!-- Desktop Navigation Links (Light Theme, Bigger Text) -->
    <div class="hidden sm:flex flex-col flex-1 px-2.5 py-3 space-y-4 text-sm">
        <!-- 1. DASHBOARD -->
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate
               aria-label="Dashboard"
               class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('dashboard') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <rect width="7" height="9" x="3" y="3" rx="1" stroke-width="2" />
                    <rect width="7" height="5" x="14" y="3" rx="1" stroke-width="2" />
                    <rect width="7" height="9" x="14" y="12" rx="1" stroke-width="2" />
                    <rect width="7" height="5" x="3" y="16" rx="1" stroke-width="2" />
                </svg>
                @if (!$collapsed)
                    <span>Dashboard</span>
                @else
                    <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                        <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                            <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                            Dashboard
                        </div>
                    </div>
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
               aria-label="Point of Sale (POS)"
               class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('sales.index') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('sales.index') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                @if (!$collapsed)
                    <span>Point of Sale (POS)</span>
                @else
                    <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                        <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                            <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                            Point of Sale (POS)
                        </div>
                    </div>
                @endif
            </a>

            @if (auth()->user()?->canViewQuotations())
                <a href="{{ route('sales.quotations') }}" wire:navigate
                   aria-label="Quotations"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('sales.quotations') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('sales.quotations') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Quotations</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Quotations
                            </div>
                        </div>
                    @endif
                </a>
            @endif

            @if (auth()->user()?->canViewSalesHistory())
                <a href="{{ route('sales.history') }}" wire:navigate
                   aria-label="Sales History"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('sales.history') || request()->routeIs('sales.receipt') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('sales.history') || request()->routeIs('sales.receipt') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Sales History</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Sales History
                            </div>
                        </div>
                    @endif
                </a>
            @endif

            @if (auth()->user()?->canViewSalesReport())
                <a href="{{ route('reports.sales') }}" wire:navigate
                   aria-label="Sales Report"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('reports.sales') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('reports.sales') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Sales Report</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Sales Report
                            </div>
                        </div>
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

            <!-- Products (with All Products, Stock In, Movements, Physical Count submenu) -->
            @if (!$collapsed)
                <button wire:click="toggleProducts" type="button"
                    class="flex w-full items-center justify-start px-3 py-2.5 gap-3 rounded-md text-sm font-medium transition {{ request()->routeIs('products.*') || request()->routeIs('inventory.stock-in') || request()->routeIs('inventory.movements') || request()->routeIs('inventory.physical-count') ? 'text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('products.*') || request()->routeIs('inventory.stock-in') || request()->routeIs('inventory.movements') || request()->routeIs('inventory.physical-count') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <span class="flex-1 text-left">Products</span>
                    <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform {{ $productsExpanded ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                    </svg>
                </button>

                @if ($productsExpanded)
                    <div class="pl-4 space-y-0.5">
                        <a href="{{ route('products.index') }}" wire:navigate
                           title="All Products"
                           class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('products.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('products.*') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                            </svg>
                            <span>All Products</span>
                        </a>

                        <a href="{{ route('inventory.stock-in') }}" wire:navigate
                           title="Stock In"
                           class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.stock-in') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('inventory.stock-in') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3" />
                            </svg>
                            <span>Stock In</span>
                        </a>

                        <a href="{{ route('inventory.movements') }}" wire:navigate
                           title="Movements"
                           class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.movements') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('inventory.movements') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                            </svg>
                            <span>Movements</span>
                        </a>

                        <a href="{{ route('inventory.physical-count') }}" wire:navigate
                           title="Physical Count"
                           class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.physical-count') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('inventory.physical-count') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                            <span>Physical Count</span>
                        </a>
                    </div>
                @endif
            @else
                {{-- Collapsed: show Products icon linking to products index --}}
                <a href="{{ route('products.index') }}" wire:navigate
                   aria-label="Products"
                   class="relative group flex w-full items-center justify-center px-2 py-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('products.*') || request()->routeIs('inventory.stock-in') || request()->routeIs('inventory.movements') || request()->routeIs('inventory.physical-count') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('products.*') || request()->routeIs('inventory.stock-in') || request()->routeIs('inventory.movements') || request()->routeIs('inventory.physical-count') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                        <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                            <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                            Products
                        </div>
                    </div>
                </a>
            @endif

            <!-- Inventory Report Link -->
            <a href="{{ route('inventory.index') }}" wire:navigate
               aria-label="Inventory Report"
               class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('inventory.index') || request()->routeIs('reports.inventory') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('inventory.index') || request()->routeIs('reports.inventory') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                @if (!$collapsed)
                    <span>Inventory Report</span>
                @else
                    <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                        <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                            <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                            Inventory Report
                        </div>
                    </div>
                @endif
            </a>
        </div>

        <!-- 4. SYSTEM SETTINGS (dev, admin only) -->
        @if (auth()->user()->canAccessAdministration())
            <div class="space-y-1">
                @if (!$collapsed)
                    <p class="px-3 pt-2 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 mb-1">
                        System Settings
                    </p>
                @endif

                <a href="{{ route('audit.index') }}" wire:navigate
                   aria-label="Audit Trail"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('audit.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('audit.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Audit Trail</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Audit Trail
                            </div>
                        </div>
                    @endif
                </a>

                <a href="{{ route('user-access.index') }}" wire:navigate
                   aria-label="Users"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('user-access.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('user-access.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                    @if (!$collapsed)
                        <span>Users</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Users
                            </div>
                        </div>
                    @endif
                </a>

                <a href="{{ route('backup.index') }}" wire:navigate
                   aria-label="Backups"
                   class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('backup.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                    <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('backup.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                    </svg>
                    @if (!$collapsed)
                        <span>Backups</span>
                    @else
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Backups
                            </div>
                        </div>
                    @endif
                </a>

                @if (!$collapsed)
                    <button wire:click="toggleSettings" type="button"
                        class="flex w-full items-center justify-start px-3 py-2.5 gap-3 rounded-md text-sm font-medium transition {{ request()->routeIs('references.*') ? 'text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                        <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('references.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                        <span class="flex-1 text-left">Configuration</span>
                        <svg class="h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform {{ $settingsExpanded ? 'rotate-180' : '' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    @if ($settingsExpanded)
                        <div class="pl-4 space-y-0.5">
                            <a href="{{ route('references.brands') }}" wire:navigate
                               title="Brands"
                               class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('references.brands') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('references.brands') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                                </svg>
                                <span>Brands</span>
                            </a>
                            <a href="{{ route('references.categories') }}" wire:navigate
                               title="Categories"
                               class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('references.categories') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('references.categories') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                                </svg>
                                <span>Categories</span>
                            </a>
                            <a href="{{ route('references.package-units') }}" wire:navigate
                               title="Units"
                               class="flex w-full items-center justify-start px-3 py-2 gap-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('references.package-units') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                                <svg class="h-4 w-4 shrink-0 {{ request()->routeIs('references.package-units') ? 'text-[#008fb3]' : 'text-slate-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                </svg>
                                <span>Units</span>
                            </a>
                        </div>
                    @endif                
                @else
                    {{-- Collapsed: show Settings icon linking to references.brands --}}
                    <a href="{{ route('references.brands') }}" wire:navigate
                       aria-label="Configuration"
                       class="relative group flex w-full items-center justify-center px-2 py-2.5 rounded-md text-sm font-medium transition {{ request()->routeIs('references.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100 hover:text-slate-900' }}">
                        <svg class="h-5 w-5 shrink-0 {{ request()->routeIs('references.*') ? 'text-[#008fb3]' : 'text-slate-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                        </svg>
                        <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                            <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                Configuration
                            </div>
                        </div>
                    </a>
                @endif

                @if (auth()->user()->canAccessTroubleshooting())
                    <a href="{{ route('dev.troubleshooting') }}" wire:navigate
                       aria-label="Troubleshooting"
                       class="relative group flex w-full items-center {{ $collapsed ? 'justify-center px-2 py-2.5' : 'justify-start px-3 py-2.5 gap-3' }} rounded-md text-sm font-medium transition {{ request()->routeIs('dev.*') ? 'bg-purple-100 text-purple-900 font-semibold' : 'text-purple-700 hover:bg-purple-50' }}">
                        <svg class="h-5 w-5 shrink-0 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        @if (!$collapsed)
                            <span>Troubleshooting</span>
                        @else
                            <div class="pointer-events-none absolute left-full top-1/2 -translate-y-1/2 ml-2.5 z-50 hidden sm:flex items-center opacity-0 -translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all duration-150 ease-out">
                                <div class="relative px-2.5 py-1 text-xs font-semibold rounded-md shadow-lg bg-slate-900 text-white whitespace-nowrap">
                                    <div class="absolute -left-1 top-1/2 -translate-y-1/2 border-y-4 border-y-transparent border-r-4 border-r-slate-900"></div>
                                    Troubleshooting
                                </div>
                            </div>
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
        @if (auth()->user()?->canViewQuotations())
            <a href="{{ route('sales.quotations') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('sales.quotations') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Quotations</a>
        @endif
        @if (auth()->user()?->canViewSalesHistory())
            <a href="{{ route('sales.history') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('sales.history') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Sales History</a>
        @endif
        @if (auth()->user()?->canViewSalesReport())
            <a href="{{ route('reports.sales') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('reports.sales') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Sales Report</a>
        @endif

        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 pt-2">Inventory</p>
        <a href="{{ route('products.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('products.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Products</a>
        <a href="{{ route('inventory.stock-in') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.stock-in') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Stock In</a>
        <a href="{{ route('inventory.movements') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.movements') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Movements</a>
        <a href="{{ route('inventory.physical-count') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.physical-count') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Physical Count</a>
        <a href="{{ route('inventory.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('inventory.index') || request()->routeIs('reports.inventory') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Inventory Report</a>

        @if (auth()->user()->canAccessAdministration())
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 pb-1 pt-2">System Settings</p>
            <a href="{{ route('audit.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('audit.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Audit Trail</a>
            <a href="{{ route('user-access.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('user-access.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Users</a>
            <a href="{{ route('backup.index') }}" wire:navigate class="block px-3 py-2 rounded font-medium {{ request()->routeIs('backup.*') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-700 hover:bg-slate-100' }}">Backups</a>
            <div class="block px-3 py-2 font-medium text-slate-700">Configuration</div>
            <a href="{{ route('references.brands') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('references.brands') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Brands</a>
            <a href="{{ route('references.categories') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('references.categories') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Categories</a>
            <a href="{{ route('references.package-units') }}" wire:navigate class="block pl-8 px-3 py-2 rounded font-medium {{ request()->routeIs('references.package-units') ? 'bg-cyan-50 text-[#008fb3] font-semibold' : 'text-slate-600 hover:bg-slate-100' }}">↳ Units</a>
                                            @endif
    </div>
</nav>
