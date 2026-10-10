<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\Currency;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $stockStatus = 'all'; // 'all', 'healthy', 'low', 'out'
    public string $dateFrom = '';
    public string $dateTo = '';
    public string $datePreset = 'all'; // 'today', 'week', 'month', 'all', 'custom'

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedBrandId(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryId(): void
    {
        $this->resetPage();
    }

    public function updatedStockStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->datePreset = 'custom';
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->datePreset = 'custom';
        $this->resetPage();
    }

    public function setPreset(string $preset): void
    {
        $this->datePreset = $preset;

        if ($preset === 'today') {
            $this->dateFrom = now()->toDateString();
            $this->dateTo = now()->toDateString();
        } elseif ($preset === 'yesterday') {
            $this->dateFrom = now()->subDay()->toDateString();
            $this->dateTo = now()->subDay()->toDateString();
        } elseif ($preset === 'this_week' || $preset === 'week') {
            $this->datePreset = 'this_week';
            $this->dateFrom = now()->startOfWeek()->toDateString();
            $this->dateTo = now()->endOfWeek()->toDateString();
        } elseif ($preset === 'this_month' || $preset === 'month') {
            $this->datePreset = 'this_month';
            $this->dateFrom = now()->startOfMonth()->toDateString();
            $this->dateTo = now()->endOfMonth()->toDateString();
        } elseif ($preset === 'last_month') {
            $this->dateFrom = now()->subMonth()->startOfMonth()->toDateString();
            $this->dateTo = now()->subMonth()->endOfMonth()->toDateString();
        } elseif ($preset === 'this_year') {
            $this->dateFrom = now()->startOfYear()->toDateString();
            $this->dateTo = now()->endOfYear()->toDateString();
        } else {
            $this->datePreset = 'all';
            $this->dateFrom = '';
            $this->dateTo = '';
        }

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'brandId', 'categoryId', 'stockStatus', 'dateFrom', 'dateTo']);
        $this->datePreset = 'all';
        $this->resetPage();
    }

    public function render(): mixed
    {
        $productsQuery = Product::query()
            ->with(['inventory', 'category', 'brand', 'packageUnit'])
            ->where('active', true)
            ->when($this->search !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->brandId !== '', fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId !== '', fn ($q) => $q->where('category_id', $this->categoryId))
            ->orderBy('sku');

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

        // Global summary numbers
        $allActive = Product::query()->with('inventory')->where('active', true)->get();
        $totalActiveSkus = $allActive->count();
        $totalLowStock = $allActive->filter(fn ($p) => (float) ($p->inventory?->quantity ?? 0) > 0 && (float) ($p->inventory?->quantity ?? 0) <= (float) $p->low_stock_threshold)->count();
        $totalOutOfStock = $allActive->filter(fn ($p) => (float) ($p->inventory?->quantity ?? 0) <= 0)->count();
        $totalHealthy = max(0, $totalActiveSkus - $totalLowStock - $totalOutOfStock);
        $totalStockValuation = $allActive->sum(fn ($p) => ((float) ($p->inventory?->quantity ?? 0)) * ((float) $p->selling_price));

        // Date ranges for movements
        $fromStart = $this->dateFrom ? Carbon::parse($this->dateFrom)->startOfDay() : Carbon::createFromTimestamp(0);
        $toEnd = $this->dateTo ? Carbon::parse($this->dateTo)->endOfDay() : now()->endOfDay();

        // Single aggregated movements query
        $movementsData = InventoryMovement::query()
            ->selectRaw('
                product_id,
                SUM(CASE WHEN created_at > ? THEN quantity_change ELSE 0 END) as after_to,
                SUM(CASE WHEN created_at >= ? AND created_at <= ? AND quantity_change > 0 THEN quantity_change ELSE 0 END) as period_in,
                SUM(CASE WHEN created_at >= ? AND created_at <= ? AND quantity_change < 0 THEN ABS(quantity_change) ELSE 0 END) as period_out
            ', [$toEnd, $fromStart, $toEnd, $fromStart, $toEnd])
            ->groupBy('product_id')
            ->get()
            ->keyBy('product_id');

        $paginatedProducts = $productsQuery->paginate(25);

        // Transform paginated items with calculated balances
        $paginatedProducts->getCollection()->transform(function (Product $product) use ($movementsData) {
            $mv = $movementsData->get($product->id);
            $current = (float) ($product->inventory?->quantity ?? 0);
            $afterTo = $mv ? (float) $mv->after_to : 0.0;
            $stockIn = $mv ? (float) $mv->period_in : 0.0;
            $stockOut = $mv ? (float) $mv->period_out : 0.0;

            $remainingBalance = max(0.0, $current - $afterTo);
            $startingBalance = max(0.0, $remainingBalance - $stockIn + $stockOut);

            $product->calculated_starting_balance = $startingBalance;
            $product->calculated_stock_in = $stockIn;
            $product->calculated_stock_out = $stockOut;
            $product->calculated_remaining_balance = $remainingBalance;
            $product->calculated_valuation = $remainingBalance * (float) $product->selling_price;

            return $product;
        });

        // PDF download query string
        $pdfParams = http_build_query(array_filter([
            'search' => $this->search ?: null,
            'brand_id' => $this->brandId ?: null,
            'category_id' => $this->categoryId ?: null,
            'stock_status' => $this->stockStatus !== 'all' ? $this->stockStatus : null,
            'from' => $this->dateFrom ?: null,
            'to' => $this->dateTo ?: null,
        ]));
        $pdfUrl = route('inventory.pdf').($pdfParams ? '?'.$pdfParams : '');

        return view('livewire.pages.reports.inventory', [
            'products' => $paginatedProducts,
            'brands' => Brand::query()->where('active', true)->orderBy('name')->get(),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(),
            'totalActiveSkus' => $totalActiveSkus,
            'totalLowStock' => $totalLowStock,
            'totalOutOfStock' => $totalOutOfStock,
            'totalHealthy' => $totalHealthy,
            'totalStockValuation' => $totalStockValuation,
            'pdfUrl' => $pdfUrl,
        ]);
    }
}; ?>

<div 
    x-data="{
        contextMenu: {
            open: false,
            x: 0,
            y: 0,
            item: null,
            openAt(x, y, item) {
                this.item = item;
                this.x = x;
                this.y = y;
                this.open = true;
                this.$nextTick(() => {
                    const el = this.$refs.floatingMenu;
                    if (!el) return;
                    const r = el.getBoundingClientRect();
                    if (this.x + r.width > window.innerWidth - 8) {
                        this.x = Math.max(8, window.innerWidth - r.width - 8);
                    }
                    if (this.y + r.height > window.innerHeight - 8) {
                        this.y = Math.max(8, window.innerHeight - r.height - 8);
                    }
                });
            },
            openFromButton(event, item) {
                const btn = event.currentTarget.getBoundingClientRect();
                this.openAt(btn.right - 176, btn.bottom + 4, item);
            },
            openFromEvent(event, item) {
                this.openAt(event.clientX, event.clientY, item);
            },
            close() {
                this.open = false;
                this.item = null;
            }
        }
    }"
    @click.window="contextMenu.close()"
    @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()"
    @resize.window="contextMenu.close()"
    class="space-y-4"
>
    <!-- Header & PDF Export -->
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Inventory Stock Report</h1>
            <p class="text-[11px] text-slate-500 mt-0.5">Stock movements, balances, valuation, and threshold analysis</p>
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

    <!-- Date Presets Ribbon (above the KPI cards, matching Sales Report) -->
    <div class="flex flex-wrap items-center gap-1.5 rounded-lg border border-slate-300 bg-white p-2 text-xs shadow-xs">
        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 px-2">Presets:</span>
        @php
            $presets = [
                'today' => 'Today',
                'yesterday' => 'Yesterday',
                'this_week' => 'This Week',
                'this_month' => 'This Month',
                'last_month' => 'Last Month',
                'this_year' => 'This Year',
                'all' => 'All Time',
            ];
        @endphp
        @foreach ($presets as $key => $label)
            <button wire:click="setPreset('{{ $key }}')" type="button"
                class="rounded px-2.5 py-1 text-xs font-medium transition {{ $datePreset === $key ? 'bg-[#00a3cc] text-white font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    <!-- KPI Metric Cards (Large numbers, clean borders) -->
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Active SKUs</p>
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
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Stock Valuation</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ Currency::format($totalStockValuation) }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Retail</p>
        </div>
    </div>

    <!-- Inventory Health Distribution Bar -->
    @if ($totalActiveSkus > 0)
        @php
            $healthyPct = round(($totalHealthy / $totalActiveSkus) * 100);
            $lowPct = round(($totalLowStock / $totalActiveSkus) * 100);
            $outPct = round(($totalOutOfStock / $totalActiveSkus) * 100);
        @endphp
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs space-y-2">
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

    <!-- Main Table Container with Horizontal Filter Bar (matching Quotations & Sales History) -->
    <div class="rounded-lg border border-slate-300 bg-white shadow-xs overflow-hidden">
        <!-- Horizontal Filter Bar Directly Above Table -->
        <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
            <!-- Search SKU / Product -->
            <div class="flex-1 min-w-[200px]">
                <input 
                    wire:model.live.debounce.250ms="search" 
                    type="search" 
                    placeholder="Search SKU, product name..." 
                    class="w-full rounded border-slate-300 px-2.5 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                />
            </div>

            <!-- Custom Date Range -->
            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">From:</span>
                <input 
                    wire:model.live="dateFrom" 
                    type="date" 
                    title="From Date"
                    class="rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                />
            </div>

            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">To:</span>
                <input 
                    wire:model.live="dateTo" 
                    type="date" 
                    title="To Date"
                    class="rounded border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                />
            </div>

            <!-- Brand Filter -->
            <div class="w-40">
                <select 
                    wire:model.live="brandId" 
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                >
                    <option value="">All Brands</option>
                    @foreach ($brands as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Category Filter -->
            <div class="w-44">
                <select 
                    wire:model.live="categoryId" 
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                >
                    <option value="">All Categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Stock Status Filter -->
            <div class="w-36">
                <select 
                    wire:model.live="stockStatus" 
                    class="w-full rounded border-slate-300 px-2 py-1.5 text-xs text-slate-900 focus:border-[#00a3cc] focus:ring-1 focus:ring-[#00a3cc]"
                >
                    <option value="all">All Statuses</option>
                    <option value="healthy">Healthy Stock</option>
                    <option value="low">Low Stock</option>
                    <option value="out">Out of Stock</option>
                </select>
            </div>

            <!-- Reset Button -->
            @if ($search !== '' || $brandId !== '' || $categoryId !== '' || $stockStatus !== 'all' || $dateFrom !== '' || $dateTo !== '' || $datePreset !== 'all')
                <button 
                    wire:click="resetFilters" 
                    type="button" 
                    class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-100 transition"
                >
                    Reset
                </button>
            @endif
        </div>

        <!-- Table Area: SKU first, no unit on stock values, starting/in/out/remaining -->
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse border border-slate-300">
                <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                    <tr>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 whitespace-nowrap">SKU</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 min-w-[200px]">Product Name</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Unit</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 whitespace-nowrap">Brand</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 whitespace-nowrap">Category</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Start Bal</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Stock In</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Stock Out</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap font-bold text-slate-900">End Bal</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right whitespace-nowrap">Valuation</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap">Status</th>
                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center whitespace-nowrap w-16">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @forelse ($products as $product)
                        @php
                            $startBal = (float) $product->calculated_starting_balance;
                            $stockIn = (float) $product->calculated_stock_in;
                            $stockOut = (float) $product->calculated_stock_out;
                            $endBal = (float) $product->calculated_remaining_balance;
                            $threshold = (float) $product->low_stock_threshold;
                            $unit = $product->packageUnit?->abbreviation ?? 'pcs';
                            $valuation = (float) $product->calculated_valuation;
                        @endphp
                        <tr 
                            @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $product->id }}, sku: '{{ $product->sku }}', name: '{{ addslashes($product->name) }}' })"
                            class="hover:bg-slate-50 transition cursor-default" 
                            wire:key="inv-prod-{{ $product->id }}"
                        >
                            <!-- SKU First -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono font-medium text-slate-900 tabular-nums whitespace-nowrap">
                                {{ $product->sku }}
                            </td>

                            <!-- Product Name -->
                            <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 min-w-[200px]" title="{{ $product->name }}">
                                {{ $product->name }}
                            </td>

                            <!-- Unit -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                {{ $unit }}
                            </td>

                            <!-- Brand -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $product->brand?->name ?? '—' }}
                            </td>

                            <!-- Category -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                {{ $product->category?->name ?? '—' }}
                            </td>

                            <!-- Starting Balance (Numeric Only, 2 Decimals) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                {{ number_format($startBal, 2) }}
                            </td>

                            <!-- Stock In (Numeric Only, 2 Decimals) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-emerald-700 whitespace-nowrap">
                                {{ $stockIn > 0 ? '+'.number_format($stockIn, 2) : '0.00' }}
                            </td>

                            <!-- Stock Out (Numeric Only, 2 Decimals) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-rose-700 whitespace-nowrap">
                                {{ $stockOut > 0 ? '-'.number_format($stockOut, 2) : '0.00' }}
                            </td>

                            <!-- Remaining / End Balance (Numeric Only, 2 Decimals) -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $endBal <= 0 ? 'text-rose-700' : ($endBal <= $threshold ? 'text-amber-700' : 'text-slate-900') }}">
                                {{ number_format($endBal, 2) }}
                            </td>

                            <!-- Stock Valuation -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-medium text-slate-900 whitespace-nowrap">
                                {{ Currency::format($valuation) }}
                            </td>

                            <!-- Status Badge -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                @if ($endBal <= 0)
                                    <span class="text-rose-700 font-semibold text-xs">Out of Stock</span>
                                @elseif ($endBal <= $threshold)
                                    <span class="text-amber-700 font-semibold text-xs">Low Stock</span>
                                @else
                                    <span class="text-emerald-700 font-semibold text-xs">Healthy</span>
                                @endif
                            </td>

                            <!-- Actions / 3-dots -->
                            <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                <button 
                                    @click.stop="contextMenu.openFromButton($event, { id: {{ $product->id }}, sku: '{{ $product->sku }}', name: '{{ addslashes($product->name) }}' })"
                                    type="button" 
                                    class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                    title="Options"
                                >
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z"/>
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                <p class="font-medium text-xs">No inventory products match your filter criteria.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($products->hasPages())
            <div class="p-3 border-t border-slate-300 bg-slate-50">
                {{ $products->links() }}
            </div>
        @endif
    </div>

    <!-- Formal Report Certification & Signature Block -->
    <div class="pt-4 border-t border-slate-300 flex flex-col sm:flex-row sm:items-end justify-between gap-4">
        <div class="text-[11px] text-slate-500">
            <p>Official system generated report reflecting verified physical inventory audit records.</p>
            <p class="text-[10px] text-slate-400 mt-0.5">Report Period: {{ $dateFrom ? \Carbon\Carbon::parse($dateFrom)->format('M d, Y') : 'Start' }} to {{ $dateTo ? \Carbon\Carbon::parse($dateTo)->format('M d, Y') : 'Present' }}</p>
        </div>
        <div class="w-full sm:w-72 rounded-lg border border-slate-300 bg-white p-4 shadow-xs">
            <span class="block text-[11px] font-bold uppercase tracking-wider text-slate-600 mb-8">
                Report Prepared &amp; Certified By:
            </span>
            <div class="border-b border-slate-900 mb-1.5"></div>
            <p class="font-bold text-slate-900 text-xs">{{ auth()->user()->name }}</p>
            <p class="text-[11px] text-slate-500">{{ ucfirst(auth()->user()->role) }}</p>
            <p class="text-[10px] text-slate-400 mt-2">Date Signed: <span class="font-mono">____________________</span></p>
        </div>
    </div>

    <!-- Global Floating Context Menu -->
    <div 
        x-ref="floatingMenu"
        x-show="contextMenu.open" 
        x-cloak
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :style="`position: fixed; left: ${contextMenu.x}px; top: ${contextMenu.y}px; z-index: 9999;`"
        class="w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.sku); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy SKU</span>
        </button>
        <a 
            :href="contextMenu.item ? `{{ route('inventory.movements') }}?search=${encodeURIComponent(contextMenu.item.sku)}` : '#'"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
            <span>View Movements</span>
        </a>
        <a 
            :href="contextMenu.item ? `{{ route('products.index') }}?search=${encodeURIComponent(contextMenu.item.sku)}` : '#'"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View in Catalog</span>
        </a>
    </div>
</div>
