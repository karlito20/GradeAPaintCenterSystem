<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component {
    public bool $open = false;
    public bool $collapsed = false;

    /**
     * Toggle the mobile navigation menu.
     */
    public function toggleNavigation(): void
    {
        $this->open = !$this->open;
    }

    /**
     * Toggle the desktop sidebar width.
     */
    public function toggleSidebar(): void
    {
        $this->collapsed = !$this->collapsed;
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<nav
    class="bg-white border-b border-gray-100 shadow-[4px_0_12px_rgba(0,0,0,0.12)] sm:sticky sm:top-0 sm:flex sm:h-screen sm:shrink-0 sm:flex-col sm:overflow-y-auto sm:border-b-0 sm:border-r {{ $collapsed ? 'sm:w-20' : 'sm:w-64' }}">
    <!-- Primary Navigation Menu -->
    <div class="px-4 sm:px-6">
        <div class="flex h-16 items-center justify-between sm:h-auto sm:flex-col sm:items-stretch sm:gap-8 sm:py-6">
            <div class="flex sm:flex-col">

                <!-- Sidebar Toggle -->
                <button wire:click="toggleSidebar" type="button"
                    aria-label="{{ $collapsed ? __('Expand sidebar') : __('Collapse sidebar') }}"
                    class="hidden items-center justify-center rounded-md p-2 text-gray-500 transition hover:bg-gray-100 hover:text-gray-700 sm:flex {{ $collapsed ? 'self-center' : 'self-end' }}">
                    <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        @if ($collapsed)
                            <path d="m9 18 6-6-6-6" />
                        @else
                            <path d="m15 18-6-6 6-6" />
                        @endif
                    </svg>
                </button>

                <!-- Navigation Links -->
                <div class="mt-6 hidden space-y-4 sm:block">
                    @if (!$collapsed)
                        <p
                            class="border-b border-gray-200 pb-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            Operations</p>
                    @endif
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <rect width="7" height="9" x="3" y="3" rx="1" />
                            <rect width="7" height="5" x="14" y="3" rx="1" />
                            <rect width="7" height="9" x="14" y="12" rx="1" />
                            <rect width="7" height="5" x="3" y="16" rx="1" />
                        </svg>

                        @if (!$collapsed)
                            <span>{{ __('Dashboard') }}</span>
                        @endif
                    </x-nav-link>
                    @if (!$collapsed)
                        <p
                            class="border-b border-gray-200 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            Products</p>
                    @endif
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('products.index')" :active="request()->routeIs('products.*')" wire:navigate>
                        <svg class="h-5 w-5 shrink-0" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                            fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 3h18v18H3z" />
                            <path d="M3 9h18M9 21V9" />
                        </svg>
                        @if (!$collapsed)
                            <span>{{ __('Products') }}</span>
                        @endif
                    </x-nav-link>
                    @if (auth()->user()->canAccessAdministration())
                        <x-nav-link
                            class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                            :href="route('references.brands')" :active="request()->routeIs('references.brands')" wire:navigate><span
                                class="h-5 w-5 text-center font-bold">B</span>
                            @if (!$collapsed)
                                <span>{{ __('Brands') }}</span>
                            @endif
                        </x-nav-link>
                        <x-nav-link
                            class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                            :href="route('references.categories')" :active="request()->routeIs('references.categories')" wire:navigate><span
                                class="h-5 w-5 text-center font-bold">C</span>
                            @if (!$collapsed)
                                <span>{{ __('Categories') }}</span>
                            @endif
                        </x-nav-link>
                        <x-nav-link
                            class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                            :href="route('references.package-units')" :active="request()->routeIs('references.package-units')" wire:navigate><span
                                class="h-5 w-5 text-center font-bold">U</span>
                            @if (!$collapsed)
                                <span>{{ __('Package Units') }}</span>
                            @endif
                        </x-nav-link>
                    @endif
                    @if (!$collapsed)
                        <p
                            class="border-b border-gray-200 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            Inventory</p>
                    @endif
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('inventory.stock-in')" :active="request()->routeIs('inventory.*')" wire:navigate>
                        <span class="h-5 w-5 text-center font-bold">+</span>
                        @if (!$collapsed)
                            <span>{{ __('Inventory') }}</span>
                        @endif
                    </x-nav-link>
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('inventory.movements')" :active="request()->routeIs('inventory.movements')" wire:navigate><span
                            class="h-5 w-5 text-center font-bold">↕</span>
                        @if (!$collapsed)
                            <span>{{ __('Movements') }}</span>
                        @endif
                    </x-nav-link>
                    @if (!$collapsed)
                        <p
                            class="border-b border-gray-200 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                            Sales &amp; Records</p>
                    @endif
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('sales.index')" :active="request()->routeIs('sales.*')" wire:navigate>
                        <span class="h-5 w-5 text-center font-bold">$</span>
                        @if (!$collapsed)
                            <span>{{ __('Sales') }}</span>
                        @endif
                    </x-nav-link>
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('sales.history')" :active="request()->routeIs('sales.history')" wire:navigate><span
                            class="h-5 w-5 text-center font-bold">H</span>
                        @if (!$collapsed)
                            <span>{{ __('Sales History') }}</span>
                        @endif
                    </x-nav-link>
                    <x-nav-link
                        class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                        :href="route('reports.inventory')" :active="request()->routeIs('reports.*')" wire:navigate>
                        <span class="h-5 w-5 text-center font-bold">R</span>
                        @if (!$collapsed)
                            <span>{{ __('Reports') }}</span>
                        @endif
                    </x-nav-link>
                    @if (auth()->user()->canAccessAdministration())
                        @if (!$collapsed)
                            <p
                                class="border-b border-gray-200 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                                Administration</p>
                        @endif
                        <x-nav-link
                            class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                            :href="route('audit.index')" :active="request()->routeIs('audit.*')" wire:navigate><span
                                class="h-5 w-5 text-center font-bold">A</span>
                            @if (!$collapsed)
                                <span>{{ __('Audit Log') }}</span>
                            @endif
                        </x-nav-link>
                        <x-nav-link
                            class="flex w-full items-center gap-2 px-3 py-2 {{ $collapsed ? 'justify-center' : 'justify-start text-start' }}"
                            :href="route('user-access.index')" :active="request()->routeIs('user-access.*')" wire:navigate><span
                                class="h-5 w-5 text-center font-bold">U</span>
                            @if (!$collapsed)
                                <span>{{ __('User Access') }}</span>
                            @endif
                        </x-nav-link>
                    @endif
                </div>
            </div>

            <!-- Hamburger -->
            <div class="-me-2 flex items-center sm:hidden">
                <button wire:click="toggleNavigation" type="button" aria-controls="mobile-navigation"
                    aria-expanded="{{ $open ? 'true' : 'false' }}"
                    class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path class="{{ $open ? 'hidden' : 'inline-flex' }}" stroke-linecap="round"
                            stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path class="{{ $open ? 'inline-flex' : 'hidden' }}" stroke-linecap="round"
                            stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div id="mobile-navigation" class="{{ $open ? 'block' : 'hidden' }} border-t border-gray-100 sm:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <p class="border-b border-gray-200 px-3 pb-2 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                Operations</p>
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')" wire:navigate>
                {{ __('Dashboard') }}
            </x-responsive-nav-link>
            <p
                class="border-b border-gray-200 px-3 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                Products</p>
            <x-responsive-nav-link :href="route('products.index')" :active="request()->routeIs('products.*')" wire:navigate>
                {{ __('Products') }}
            </x-responsive-nav-link>
            <p
                class="border-b border-gray-200 px-3 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                Inventory</p>
            <x-responsive-nav-link :href="route('inventory.stock-in')" :active="request()->routeIs('inventory.*')" wire:navigate>
                {{ __('Inventory') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('inventory.movements')" :active="request()->routeIs('inventory.movements')" wire:navigate>
                {{ __('Movements') }}
            </x-responsive-nav-link>
            <p
                class="border-b border-gray-200 px-3 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                Sales &amp; Records</p>
            <x-responsive-nav-link :href="route('sales.index')" :active="request()->routeIs('sales.*')" wire:navigate>
                {{ __('Sales') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('sales.history')" :active="request()->routeIs('sales.history')" wire:navigate>
                {{ __('Sales History') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('reports.inventory')" :active="request()->routeIs('reports.*')" wire:navigate>
                {{ __('Reports') }}
            </x-responsive-nav-link>
            @if (auth()->user()->canAccessAdministration())
                <p
                    class="border-b border-gray-200 px-3 pb-2 pt-3 text-[10px] font-bold uppercase tracking-wider text-gray-400">
                    Administration</p>
                <x-responsive-nav-link :href="route('references.brands')" :active="request()->routeIs('references.*')" wire:navigate>
                    {{ __('Reference Settings') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('audit.index')" :active="request()->routeIs('audit.*')" wire:navigate>
                    {{ __('Audit Log') }}
                </x-responsive-nav-link>
                <x-responsive-nav-link :href="route('user-access.index')" :active="request()->routeIs('user-access.*')" wire:navigate>
                    {{ __('User Access') }}
                </x-responsive-nav-link>
            @endif
        </div>

        <!-- Responsive Settings Options -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name"
                    x-on:profile-updated.window="name = $event.detail.name"></div>
                <div class="font-medium text-sm text-gray-500">{{ auth()->user()->username }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile')" wire:navigate>
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <!-- Authentication -->
                <button wire:click="logout" class="w-full text-start">
                    <x-responsive-nav-link>
                        {{ __('Log Out') }}
                    </x-responsive-nav-link>
                </button>
            </div>
        </div>
    </div>
</nav>
