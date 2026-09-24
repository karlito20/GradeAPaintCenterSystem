<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Paint center overview</h1>
            <p class="mt-1 text-sm text-gray-600">A practical snapshot of stock and daily work.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <a href="{{ route('products.index') }}" wire:navigate
                class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
                <p class="text-sm text-gray-500">Product catalog</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">Manage SKUs</p>
            </a>
            <a href="{{ route('inventory.stock-in') }}" wire:navigate
                class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
                <p class="text-sm text-gray-500">Inventory</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">Stock In</p>
            </a>
            <a href="{{ route('sales.index') }}" wire:navigate
                class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
                <p class="text-sm text-gray-500">Sales</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">New Sale</p>
            </a>
            <a href="{{ route('inventory.physical-count') }}" wire:navigate
                class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
                <p class="text-sm text-gray-500">Weekly count</p>
                <p class="mt-2 text-2xl font-bold text-gray-900">Reconcile stock</p>
            </a>
        </div>
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-8 text-center text-gray-600">Start by
            adding products to the catalog. Stock-in, sales, mixing, and physical count workflows will use these
            package-level SKUs.</div>
    </div>
</x-app-layout>
