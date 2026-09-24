<?php

use App\Models\Category;
use App\Models\Brand;
use App\Models\InventoryMovement;
use App\Models\Product;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    public string $search = '';
    public string $categoryId = '';
    public string $brandId = '';
    public string $from = '';
    public string $to = '';

    public function setPreset(string $preset): void
    {
        $this->from = $preset === 'week' ? now()->startOfWeek()->toDateString() : now()->startOfYear()->toDateString();
        $this->to = now()->toDateString();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'categoryId', 'brandId', 'from', 'to']);
    }

    public function render(): mixed
    {
        return view('livewire.pages.reports.inventory', [
            'products' => Product::query()
                ->with(['inventory', 'category', 'brand', 'packageUnit'])
                ->when($this->search, fn($query) => $query->where('name', 'like', '%' . $this->search . '%')->orWhere('sku', 'like', '%' . $this->search . '%'))
                ->when($this->categoryId, fn($query) => $query->where('category_id', $this->categoryId))
                ->when($this->brandId, fn($query) => $query->where('brand_id', $this->brandId))
                ->where('active', true)
                ->orderBy('name')
                ->paginate(25),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(),
            'brands' => Brand::query()->where('active', true)->orderBy('name')->get(),
            'periodMovements' => InventoryMovement::query()->when($this->from, fn($query) => $query->whereDate('created_at', '>=', $this->from))->when($this->to, fn($query) => $query->whereDate('created_at', '<=', $this->to))->count(),
        ]);
    }
}; ?>

<div class="mx-auto max-w-7xl space-y-4 px-4 py-5 text-sm sm:px-6 lg:px-8">
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Inventory report</h1>
            <p class="mt-1 text-gray-600">Current stock is live; period activity covers {{ $periodMovements }} movements.
            </p>
        </div><a href="{{ route('reports.inventory.pdf') }}"
            class="rounded-md bg-[#00a3cc] px-4 py-2 font-semibold text-white shadow-sm">Print / Export PDF</a>
    </div>
    <div class="flex flex-wrap gap-2 rounded-lg bg-white p-3 shadow-sm"><input wire:model.live.debounce.300ms="search"
            type="search" placeholder="Search products" class="rounded-md border-gray-300"><select
            wire:model.live="brandId" class="rounded-md border-gray-300">
            <option value="">All brands</option>
            @foreach ($brands as $brand)
                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="categoryId" class="rounded-md border-gray-300">
            <option value="">All categories</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <button wire:click="setPreset('week')" type="button" class="rounded border px-3 py-2">This Week</button><button
            wire:click="setPreset('year')" type="button" class="rounded border px-3 py-2">This Year</button><input
            wire:model.live="from" type="date" class="rounded-md border-gray-300"><input wire:model.live="to"
            type="date" class="rounded-md border-gray-300"><button wire:click="resetFilters" type="button"
            class="rounded border px-3 py-2">Reset</button>
    </div>
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 text-left text-xs">
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
                    @forelse ($products as $product)
                        <tr wire:key="report-product-{{ $product->id }}">
                            <td class="px-4 py-2 font-medium">{{ $product->name }}</td>
                            <td class="px-4 py-2 font-mono">{{ $product->sku }}</td>
                            <td class="px-4 py-2">{{ $product->brand?->name ?? '-' }}</td>
                            <td class="px-4 py-2">{{ $product->category->name }}</td>
                            <td class="px-4 py-2">{{ $product->packageUnit?->abbreviation ?? '-' }}</td>
                            <td class="px-4 py-2 font-semibold">{{ $product->inventory?->quantity ?? 0 }}</td>
                            <td class="px-4 py-2">{{ $product->low_stock_threshold }}</td>
                            <td class="px-4 py-2">
                                {{ ($product->inventory?->quantity ?? 0) == 0 ? 'Out of stock' : (($product->inventory?->quantity ?? 0) <= $product->low_stock_threshold ? 'Low stock' : 'Healthy') }}
                            </td>
                    </tr>@empty<tr>
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">No matching products.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $products->links() }}</div>
    </div>
</div>
