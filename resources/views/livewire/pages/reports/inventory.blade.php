<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\Currency;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $categoryId = '';
    public string $brandId = '';
    public string $stockStatus = 'all'; // 'all', 'healthy', 'low', 'out'
    public string $from = '';
    public string $to = '';
    public string $activePreset = '';

    public function mount(): void
    {
        // Default to no date restriction on stock balances, but available for movements count
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedStockStatus(): void
    {
        $this->resetPage();
    }

    public function selectBrand(string $id): void
    {
        $this->brandId = $id;
        $this->resetPage();
    }

    public function setPreset(string $preset): void
    {
        $this->activePreset = $preset;
        if ($preset === 'week') {
            $this->from = now()->startOfWeek()->toDateString();
            $this->to = now()->endOfWeek()->toDateString();
        } elseif ($preset === 'month') {
            $this->from = now()->startOfMonth()->toDateString();
            $this->to = now()->endOfMonth()->toDateString();
        } elseif ($preset === 'year') {
            $this->from = now()->startOfYear()->toDateString();
            $this->to = now()->endOfYear()->toDateString();
        } else {
            $this->from = '';
            $this->to = '';
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'categoryId', 'brandId', 'stockStatus', 'from', 'to', 'activePreset']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $productsQuery = Product::query()
            ->with(['inventory', 'category', 'brand', 'packageUnit'])
            ->where('active', true)
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->categoryId !== '', fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->brandId !== '', fn ($q) => $q->where('brand_id', $this->brandId))
            ->orderBy('name');

        // Status filter in PHP collection or query
        if ($this->stockStatus === 'low') {
            $productsQuery->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity > 0 AND inventories.quantity <= products.low_stock_threshold');
            });
        } elseif ($this->stockStatus === 'out') {
            $productsQuery->where(function ($q) {
                $q->whereDoesntHave('inventory')
                    ->orWhereHas('inventory', fn ($sub) => $sub->where('quantity', '<=', 0));
            });
        } elseif ($this->stockStatus === 'healthy') {
            $productsQuery->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity > products.low_stock_threshold');
            });
        }

        // Global KPIs across active products
        $allActive = Product::query()->with('inventory')->where('active', true)->get();
        $totalActiveSkus = $allActive->count();
        $totalLowStock = $allActive->filter(fn ($p) => (float) ($p->inventory?->quantity ?? 0) > 0 && (float) ($p->inventory?->quantity ?? 0) <= (float) $p->low_stock_threshold)->count();
        $totalOutOfStock = $allActive->filter(fn ($p) => (float) ($p->inventory?->quantity ?? 0) <= 0)->count();
        $totalHealthy = $totalActiveSkus - $totalLowStock - $totalOutOfStock;
        $totalStockValuation = $allActive->sum(fn ($p) => ((float) ($p->inventory?->quantity ?? 0)) * ((float) $p->selling_price));

        // Movement audit count for period
        $periodMovements = InventoryMovement::query()
            ->when($this->from !== '', fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to !== '', fn ($q) => $q->whereDate('created_at', '<=', $this->to))
            ->count();

        // PDF query params
        $pdfParams = http_build_query(array_filter([
            'search' => $this->search ?: null,
            'brand_id' => $this->brandId ?: null,
            'category_id' => $this->categoryId ?: null,
            'stock_status' => $this->stockStatus !== 'all' ? $this->stockStatus : null,
        ]));
        $pdfUrl = route('reports.inventory.pdf') . ($pdfParams ? '?' . $pdfParams : '');

        return view('livewire.pages.reports.inventory', [
            'products' => $productsQuery->paginate(25),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(),
            'brands' => Brand::query()->where('active', true)->orderBy('name')->get(),
            'totalActiveSkus' => $totalActiveSkus,
            'totalLowStock' => $totalLowStock,
            'totalOutOfStock' => $totalOutOfStock,
            'totalHealthy' => $totalHealthy,
            'totalStockValuation' => $totalStockValuation,
            'periodMovements' => $periodMovements,
            'pdfUrl' => $pdfUrl,
        ]);
    }
}; ?>

<div class="space-y-6">
    <!-- Header & PDF Export -->
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('products.index') }}" class="text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-600">Inventory</a>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Reporting</span>
            </div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Inventory Report</h1>
        </div>
        <div class="flex items-center gap-2">
            <a 
                href="{{ $pdfUrl }}" 
                target="_blank"
                class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition"
            >
                <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Export PDF
            </a>
        </div>
    </div>

    <!-- KPI Metric Cards (Right aligned, larger light numbers, neutral labels) -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Total Active SKUs</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ $totalActiveSkus }}</p>
            <p class="mt-1 text-[11px] font-medium text-emerald-700 text-right">{{ $totalHealthy }} Healthy</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Low Stock Warnings</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-amber-600 text-right">{{ $totalLowStock }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">&le; threshold</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Out of Stock</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-rose-600 text-right">{{ $totalOutOfStock }}</p>
            <p class="mt-1 text-[11px] font-medium text-rose-600 text-right">0 balance</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Total Stock Valuation</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ Currency::format($totalStockValuation) }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Retail</p>
        </div>
    </div>

    <!-- Visual Stock Distribution Bar -->
    @if ($totalActiveSkus > 0)
        @php
            $healthyPct = round(($totalHealthy / $totalActiveSkus) * 100);
            $lowPct = round(($totalLowStock / $totalActiveSkus) * 100);
            $outPct = round(($totalOutOfStock / $totalActiveSkus) * 100);
        @endphp
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-2">
            <div class="flex items-center justify-between text-xs font-medium text-slate-600">
                <span class="font-semibold text-slate-800">Overall Inventory Health Distribution</span>
                <div class="flex items-center gap-4 text-[11px]">
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-600"></span> Healthy ({{ $healthyPct }}%)</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-amber-500"></span> Low Stock ({{ $lowPct }}%)</span>
                    <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-rose-600"></span> Out of Stock ({{ $outPct }}%)</span>
                </div>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100 flex">
                <div style="width: {{ $healthyPct }}%" class="bg-emerald-600"></div>
                <div style="width: {{ $lowPct }}%" class="bg-amber-400"></div>
                <div style="width: {{ $outPct }}%" class="bg-rose-600"></div>
            </div>
        </div>
    @endif

    <!-- Brand Filter Bar -->
    <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-2">
        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Brand:</span>
        <div class="flex flex-wrap items-center gap-1.5 pt-0.5">
            <button 
                wire:click="selectBrand('')" 
                type="button"
                class="rounded border px-2.5 py-1 text-xs font-medium transition shadow-xs {{ $brandId === '' ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
            >
                All Brands ({{ $totalActiveSkus }})
            </button>
            @foreach ($brands as $b)
                <button 
                    wire:click="selectBrand('{{ $b->id }}')" 
                    type="button"
                    class="rounded border px-2.5 py-1 text-xs font-medium transition shadow-xs {{ $brandId == (string)$b->id ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
                >
                    {{ $b->name }}
                </button>
            @endforeach
        </div>
    </div>

    <!-- Main Workspace with Left Filter Panel & Grid Table -->
    <div class="flex flex-col lg:flex-row gap-4 items-start">
        <!-- Left Filter Panel -->
        <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3">
            <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filter Inventory</span>
                <button wire:click="resetFilters" type="button" class="text-[11px] text-slate-500 hover:text-slate-800 underline">
                    Reset
                </button>
            </div>

            <!-- Search -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Product / SKU</label>
                <input 
                    wire:model.live.debounce.300ms="search" 
                    type="search" 
                    placeholder="Product name, SKU..." 
                    class="w-full rounded border border-slate-300 text-xs py-1.5 px-2.5 focus:border-slate-500 focus:ring-slate-500"
                />
            </div>

            <!-- Category -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
                <select wire:model.live="categoryId" class="w-full rounded border border-slate-300 text-xs py-1.5 px-2 focus:border-slate-500 focus:ring-slate-500">
                    <option value="">All Categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Stock Status -->
            <div>
                <label class="block text-[11px] font-semibold text-slate-600 mb-1">Stock Status</label>
                <select wire:model.live="stockStatus" class="w-full rounded border border-slate-300 text-xs py-1.5 px-2 focus:border-slate-500 focus:ring-slate-500">
                    <option value="all">All Statuses</option>
                    <option value="healthy">Healthy Stock</option>
                    <option value="low">Low Stock (&le; Threshold)</option>
                    <option value="out">Out of Stock (0 Stock)</option>
                </select>
            </div>

            <!-- Period Quick Presets -->
            <div class="border-t border-slate-200 pt-2 space-y-1.5">
                <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Period Movements:</span>
                <div class="flex items-center gap-1.5">
                    <button 
                        wire:click="setPreset('week')" 
                        type="button" 
                        class="flex-1 rounded border px-2 py-1 text-xs font-medium transition shadow-xs text-center {{ $activePreset === 'week' ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
                    >
                        Week
                    </button>
                    <button 
                        wire:click="setPreset('year')" 
                        type="button" 
                        class="flex-1 rounded border px-2 py-1 text-xs font-medium transition shadow-xs text-center {{ $activePreset === 'year' ? 'border-[#00a3cc] bg-[#00a3cc] text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' }}"
                    >
                        Year
                    </button>
                </div>
            </div>

            @if ($from || $to)
                <div class="pt-2 text-[11px] text-slate-500 border-t border-slate-200">
                    <span class="tabular-nums font-bold text-slate-900">{{ $periodMovements }}</span> moves recorded.
                </div>
            @endif
        </aside>

        <!-- Table Area -->
        <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full border-collapse border border-slate-300 text-xs">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Product Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Category</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20">Unit</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Retail Price</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Current Stock</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Threshold</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Status</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Stock Valuation</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($products as $product)
                        @php
                            $qty = (float) ($product->inventory?->quantity ?? 0);
                            $threshold = (float) $product->low_stock_threshold;
                            $unit = $product->packageUnit?->abbreviation ?? 'pcs';
                            $valuation = $qty * (float) $product->selling_price;
                        @endphp
                        <tr class="hover:bg-slate-50 transition-colors" wire:key="inv-prod-{{ $product->id }}">
                            <!-- Separate Brand Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                {{ $product->brand?->name ?? '—' }}
                            </td>

                            <!-- Product Name -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[220px] max-w-md break-words whitespace-normal" title="{{ $product->name }}">
                                {{ $product->name }}
                            </td>

                            <!-- Separate SKU Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                {{ $product->sku }}
                            </td>

                            <!-- Separate Category Column -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $product->category?->name ?? '—' }}
                            </td>

                            <!-- Unit -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $unit }}
                            </td>

                            <!-- Retail Price -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-medium text-slate-800 whitespace-nowrap">
                                {{ Currency::format((float) $product->selling_price) }}
                            </td>

                            <!-- Current Stock with explicit unit -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $qty <= 0 ? 'text-rose-700' : ($qty <= $threshold ? 'text-amber-700' : 'text-emerald-700') }}">
                                {{ number_format($qty, 3) }} {{ $unit }}
                            </td>

                            <!-- Threshold -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                {{ number_format($threshold, 3) }}
                            </td>

                            <!-- Status Minimal Badge -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($qty <= 0)
                                    <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Out of Stock
                                    </span>
                                @elseif ($qty <= $threshold)
                                    <span class="inline-block rounded border border-amber-600 text-amber-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Low Stock
                                    </span>
                                @else
                                    <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                        Healthy
                                    </span>
                                @endif
                            </td>

                            <!-- Stock Valuation -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-semibold text-slate-900 whitespace-nowrap">
                                {{ Currency::format($valuation) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                <div class="mx-auto flex flex-col items-center justify-center">
                                    <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                    <p class="text-xs font-semibold text-slate-700">No inventory products match your filter criteria.</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                {{ $products->links() }}
            </div>
        @endif
    </div>
</div>
</div>
