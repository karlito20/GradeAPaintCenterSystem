<?php

use App\Models\Product;
use App\Models\Sale;
use App\Models\StockIn;
use App\Models\InventoryMovement;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public function render(): mixed
    {
        $products = Product::query()
            ->with(['inventory', 'brand', 'category', 'packageUnit'])
            ->where('active', true)
            ->get();
        $today = Carbon::today();
        $lowStockProducts = $products->filter(fn(Product $product): bool => (float) ($product->inventory?->quantity ?? 0) <= (float) $product->low_stock_threshold);

        return view('livewire.pages.dashboard', [
            'productCount' => $products->count(),
            'lowStockCount' => $lowStockProducts->count(),
            'lowStockProducts' => $lowStockProducts->take(10),
            'todaySales' => Sale::query()->whereDate('sold_at', $today)->sum('total'),
            'recentSales' => Sale::query()->latest('sold_at')->limit(5)->get(),
            'recentStockIns' => StockIn::query()->with('items.product')->latest('received_at')->limit(5)->get(),
            'recentMovements' => InventoryMovement::query()->with('product')->latest()->limit(5)->get(),
            'currency' => Currency::class,
        ]);
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-5 px-4 py-5 text-sm sm:px-6 lg:px-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Paint center overview</h1>
        <p class="mt-1 text-sm text-gray-600">A practical snapshot of stock and daily work.</p>
    </div>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <a href="{{ route('products.index') }}" wire:navigate
            class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
            <p class="text-sm text-gray-500">Active SKUs</p>
            <p class="mt-2 text-2xl font-bold text-gray-900">{{ $productCount }}</p>
        </a>
        <a href="{{ route('reports.inventory') }}" wire:navigate
            class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
            <p class="text-sm text-gray-500">Low-stock items</p>
            <p class="mt-2 text-2xl font-bold text-red-600">{{ $lowStockCount }}</p>
        </a>
        <a href="{{ route('sales.index') }}" wire:navigate
            class="rounded-lg bg-white p-4 shadow-sm transition hover:shadow-md">
            <p class="text-gray-500">Sales today</p>
            <p class="mt-1 text-xl font-bold text-gray-900">{{ $currency::format($todaySales) }}</p>
        </a>
        <a href="{{ route('inventory.stock-in') }}" wire:navigate
            class="rounded-lg bg-white p-5 shadow-sm transition hover:shadow-md">
            <p class="text-sm text-gray-500">Quick action</p>
            <p class="mt-2 text-2xl font-bold text-gray-900">Stock In</p>
        </a>
    </div>
    <section class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="flex items-center justify-between border-b px-4 py-3">
            <h2 class="font-semibold text-gray-900">Low Stock Items</h2><a class="text-[#008fb3]"
                href="{{ route('reports.inventory') }}" wire:navigate>View inventory</a>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-xs">
                <thead class="bg-gray-50 uppercase text-gray-500">
                    <tr>
                        <th class="px-4 py-2">Product</th>
                        <th class="px-4 py-2">SKU</th>
                        <th class="px-4 py-2">Brand</th>
                        <th class="px-4 py-2">Category</th>
                        <th class="px-4 py-2">Package Unit</th>
                        <th class="px-4 py-2">Current Stock</th>
                        <th class="px-4 py-2">Threshold</th>
                        <th class="px-4 py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($lowStockProducts as $product)
                        <tr>
                            <td class="px-4 py-2 font-medium">{{ $product->name }}</td>
                            <td class="px-4 py-2 font-mono">{{ $product->sku }}</td>
                            <td class="px-4 py-2">{{ $product->brand?->name ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $product->category->name }}</td>
                            <td class="px-4 py-2">{{ $product->packageUnit?->abbreviation ?? '-' }}</td>
                            <td class="px-4 py-2 font-semibold">{{ $product->inventory?->quantity ?? 0 }}</td>
                            <td class="px-4 py-2">{{ $product->low_stock_threshold }}</td>
                            <td class="px-4 py-2 font-semibold text-red-600">
                                {{ ($product->inventory?->quantity ?? 0) == 0 ? 'Out of stock' : 'Low stock' }}</td>
                    </tr>@empty<tr>
                            <td colspan="8" class="px-4 py-6 text-center text-gray-500">No low-stock items.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="grid gap-5 lg:grid-cols-3">
        <section class="rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Recent Sales</h2>
            <div class="mt-2 divide-y">
                @forelse ($recentSales as $sale)
                    <div class="flex justify-between py-2"><span>{{ $sale->invoice_number }}</span><span
                        class="font-semibold">{{ $currency::format($sale->total) }}</span></div>@empty<p
                        class="py-3 text-gray-500">None</p>
                @endforelse
            </div>
        </section>
        <section class="rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Recent Stock-ins</h2>
            <div class="mt-2 divide-y">
                @forelse ($recentStockIns as $stockIn)
                    <div class="flex justify-between py-2"><span>Stock-in
                            #{{ $stockIn->id }}</span><span>{{ $stockIn->items->sum('quantity') }} packages</span>
                </div>@empty<p class="py-3 text-gray-500">None</p>
                @endforelse
            </div>
        </section>
        <section class="rounded-lg bg-white p-4 shadow-sm">
            <h2 class="font-semibold">Recent Movements</h2>
            <div class="mt-2 divide-y">
                @forelse ($recentMovements as $movement)
                    <div class="flex justify-between py-2"><span>{{ $movement->product->sku }}</span><span
                            class="{{ $movement->quantity_change < 0 ? 'text-red-600' : 'text-green-700' }}">{{ $movement->quantity_change }}</span>
                </div>@empty<p class="py-3 text-gray-500">None</p>
                @endforelse
            </div>
        </section>
    </div>
    <div class="flex flex-wrap gap-3"><a href="{{ route('mixing.index') }}" wire:navigate
            class="rounded-md bg-[#00a3cc] px-4 py-2 text-sm font-semibold text-white shadow-sm">Custom Mix</a><a
            href="{{ route('inventory.physical-count') }}" wire:navigate
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">Physical
            Inventory</a><a href="{{ route('reports.inventory') }}" wire:navigate
            class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700">Inventory
            Report</a></div>
</div>
