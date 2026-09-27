<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockIn;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $lowStockSearch = '';
    public string $lowStockBrand = '';
    public string $lowStockCategory = '';
    public string $lowStockStatus = '';

    public function updatedLowStockSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLowStockBrand(): void
    {
        $this->resetPage();
    }

    public function updatedLowStockCategory(): void
    {
        $this->resetPage();
    }

    public function updatedLowStockStatus(): void
    {
        $this->resetPage();
    }

    public function resetLowStockFilters(): void
    {
        $this->reset(['lowStockSearch', 'lowStockBrand', 'lowStockCategory', 'lowStockStatus']);
        $this->resetPage();
    }

    public function render(): mixed
    {
        $today = Carbon::today();
        $totalProducts = Product::where('active', true)->count();

        // 7-day sales trend
        $sevenDays = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayTotal = (float) Sale::whereDate('sold_at', $date)->sum('total');
            $sevenDays[] = [
                'day' => $date->format('D'),
                'date' => $date->format('M d'),
                'total' => $dayTotal,
            ];
        }
        $maxSales = max(1, collect($sevenDays)->max('total'));

        // Low stock query with pagination
        $lowStockQuery = Product::query()
            ->with(['inventory', 'brand', 'category', 'packageUnit'])
            ->where('active', true)
            ->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity <= products.low_stock_threshold');
            })
            ->when($this->lowStockSearch, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'like', '%' . $this->lowStockSearch . '%')
                        ->orWhere('sku', 'like', '%' . $this->lowStockSearch . '%');
                });
            })
            ->when($this->lowStockBrand, fn ($q) => $q->where('brand_id', $this->lowStockBrand))
            ->when($this->lowStockCategory, fn ($q) => $q->where('category_id', $this->lowStockCategory))
            ->when($this->lowStockStatus === 'out', function ($q) {
                $q->whereHas('inventory', fn ($sub) => $sub->where('quantity', '<=', 0));
            })
            ->when($this->lowStockStatus === 'low', function ($q) {
                $q->whereHas('inventory', fn ($sub) => $sub->whereRaw('quantity > 0 AND quantity <= products.low_stock_threshold'));
            })
            ->orderBy('name');

        $lowStockCount = Product::query()
            ->where('active', true)
            ->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity <= products.low_stock_threshold');
            })
            ->count();

        $todaySalesTotal = (float) Sale::whereDate('sold_at', $today)->sum('total');
        $todayTransactionsCount = Sale::whereDate('sold_at', $today)->count();

        return view('livewire.pages.dashboard', [
            'totalProducts' => $totalProducts,
            'lowStockCount' => $lowStockCount,
            'todaySalesTotal' => $todaySalesTotal,
            'todayTransactionsCount' => $todayTransactionsCount,
            'sevenDays' => $sevenDays,
            'maxSales' => $maxSales,
            'lowStockProducts' => $lowStockQuery->paginate(10),
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'recentSales' => Sale::with('user')->latest('sold_at')->limit(5)->get(),
            'recentStockIns' => StockIn::with('items.product')->latest('received_at')->limit(5)->get(),
            'recentMovements' => InventoryMovement::with(['product.packageUnit', 'user'])->latest()->limit(5)->get(),
            'currency' => Currency::class,
        ]);
    }
}; ?>

<div class="space-y-6 w-full min-w-0">
    <!-- Header (No subtitle description) -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Dashboard</h1>
        </div>
        <div class="flex items-center gap-2">
            <span class="rounded border border-slate-300 bg-slate-50 px-2.5 py-1 text-xs text-slate-600 tabular-nums">{{ now()->format('l, F j, Y') }}</span>
        </div>
    </div>

    <!-- Top Operational KPIs (Right-aligned numbers, bigger light font, neutral labels) -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- Sales Today -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Today's Sales</span>
                <span class="rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-medium uppercase tabular-nums">{{ $todayTransactionsCount }} txn(s)</span>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ $currency::format($todaySalesTotal) }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Gross completed sales</p>
        </div>

        <!-- Low Stock Items -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Stock Alert</span>
                <span class="rounded border {{ $lowStockCount > 0 ? 'border-amber-600 text-amber-700' : 'border-emerald-600 text-emerald-700' }} bg-transparent px-1.5 py-0.5 text-[10px] font-medium uppercase">
                    {{ $lowStockCount > 0 ? 'Attention Needed' : 'Normal' }}
                </span>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light {{ $lowStockCount > 0 ? 'text-amber-600' : 'text-emerald-600' }} text-right">{{ $lowStockCount }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">SKUs at or below threshold</p>
        </div>

        <!-- Active SKUs -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Catalog SKUs</span>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">View All</a>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ $totalProducts }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Active stocked products</p>
        </div>

        <!-- 7-Day Mini Chart -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs flex flex-col justify-between">
            <div class="flex items-center justify-between mb-1">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">7-Day Sales</span>
                <span class="text-[10px] text-slate-400 tabular-nums">Daily Gross</span>
            </div>
            <div class="flex items-end justify-between gap-1.5 h-12 pt-2">
                @foreach ($sevenDays as $day)
                    @php
                        $heightPercent = $maxSales > 0 ? round(($day['total'] / $maxSales) * 100) : 0;
                        $heightPercent = max(10, min(100, $heightPercent));
                    @endphp
                    <div class="flex-1 flex flex-col items-center group relative">
                        <div class="w-full bg-slate-800 rounded-t transition-all hover:bg-slate-700" style="height: {{ $heightPercent }}%;"></div>
                        <span class="text-[9px] text-slate-500 mt-1 tabular-nums">{{ substr($day['day'], 0, 1) }}</span>
                        <!-- Tooltip -->
                        <div class="absolute bottom-full mb-1 hidden group-hover:block z-20 whitespace-nowrap rounded border border-slate-700 bg-slate-900 px-2 py-1 text-[10px] text-white shadow-lg tabular-nums">
                            {{ $day['date'] }}: {{ $currency::format($day['total']) }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Role-Based Quick Actions Bar -->
    <div class="rounded-lg border border-slate-200 bg-white p-3 shadow-xs flex flex-wrap items-center gap-2 text-xs">
        <span class="font-heading font-bold text-slate-500 uppercase tracking-wider text-[10px] mr-1">Quick Actions:</span>

        <a href="{{ route('sales.index') }}" wire:navigate
            class="inline-flex items-center gap-1.5 rounded border border-[#00a3cc] bg-[#00a3cc] px-3 py-1.5 font-semibold text-white hover:bg-[#008fb3] shadow-xs transition">
            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17"/></svg>
            <span>Open POS</span>
        </a>

        <a href="{{ route('inventory.stock-in') }}" wire:navigate
            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
            <span>Stock In</span>
        </a>

        <a href="{{ route('inventory.physical-count') }}" wire:navigate
            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <span>Physical Count</span>
        </a>

        <a href="{{ route('inventory.index') }}" wire:navigate
            class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
            <svg class="h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <span>Inventory Report</span>
        </a>

        @if (auth()->user()->canAccessAdministration())
            <div class="h-4 w-px bg-slate-300 mx-1"></div>
            <a href="{{ route('user-access.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded border border-purple-300 bg-purple-50/50 px-2.5 py-1.5 font-medium text-purple-700 hover:bg-purple-100 shadow-xs transition">
                <span>User Access</span>
            </a>
            <a href="{{ route('audit.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <span>Audit Log</span>
            </a>
            <a href="{{ route('backup.index') }}" wire:navigate
                class="inline-flex items-center gap-1.5 rounded border border-slate-300 bg-white px-2.5 py-1.5 font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                <span>Backup</span>
            </a>
        @endif

        @if (auth()->user()->canAccessTroubleshooting())
            <a href="{{ route('dev.troubleshooting') }}" wire:navigate
                class="inline-flex items-center gap-1 rounded border border-purple-600 bg-purple-600 px-2.5 py-1.5 font-medium text-white hover:bg-purple-700 shadow-xs transition">
                <span>Dev Reset</span>
            </a>
        @endif
    </div>

    <!-- Low Stock Items Section (With Left-Side Filters) -->
    <div class="space-y-2">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="flex h-2.5 w-2.5 rounded-full {{ $lowStockCount > 0 ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                <h2 class="font-heading text-sm font-bold uppercase tracking-wider text-slate-800">Low Stock Products Alert</h2>
                <span class="font-mono text-xs text-slate-500">({{ $lowStockCount }} items below threshold)</span>
            </div>
            <a href="{{ route('inventory.index') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                Full Inventory &rarr;
            </a>
        </div>

        <div class="flex flex-col lg:flex-row gap-4 items-start">
            <!-- Left Side Filter Panel -->
            <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-200 bg-white p-3.5 shadow-xs space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filter Alerts</span>
                    <button wire:click="resetLowStockFilters" type="button" class="text-[11px] text-slate-500 hover:text-slate-800 underline">
                        Reset
                    </button>
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Product</label>
                    <input wire:model.live.debounce.300ms="lowStockSearch" type="search" placeholder="Name or SKU..."
                        class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500 shadow-xs">
                </div>

                <!-- Brand -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Brand</label>
                    <select wire:model.live="lowStockBrand" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500 shadow-xs">
                        <option value="">All Brands</option>
                        @foreach ($brands as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Category -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
                    <select wire:model.live="lowStockCategory" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500 shadow-xs">
                        <option value="">All Categories</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Stock Status -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Stock Status</label>
                    <select wire:model.live="lowStockStatus" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500 shadow-xs">
                        <option value="">All Low & Out</option>
                        <option value="out">Out of Stock Only</option>
                        <option value="low">Low Stock Only</option>
                    </select>
                </div>
            </aside>

            <!-- Table Container -->
            <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead>
                            <tr class="border-b border-slate-300 bg-slate-100 text-left font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                <th class="border border-slate-300 px-2.5 py-1.5 w-28">Brand</th>
                                <th class="border border-slate-300 px-2.5 py-1.5">Product Name</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 w-28">SKU</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 w-28">Category</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 w-20">Unit</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Retail Price</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Current Stock</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Threshold</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Status</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($lowStockProducts as $product)
                                @php
                                    $qty = (float) ($product->inventory?->quantity ?? 0);
                                    $isOut = $qty <= 0;
                                @endphp
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">{{ $product->brand?->name ?? 'Unbranded' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $product->name }}">{{ $product->name }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 tabular-nums whitespace-nowrap">{{ $product->sku }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">{{ $product->category?->name ?? 'Uncategorized' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                        {{ $product->package_size ? rtrim(rtrim((string) $product->package_size, '0'), '.') : '' }}
                                        {{ $product->packageUnit?->abbreviation ?? $product->packageUnit?->name }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-900 whitespace-nowrap">
                                        {{ $currency::format($product->selling_price) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-medium whitespace-nowrap {{ $isOut ? 'text-rose-600' : 'text-amber-600' }}">
                                        {{ number_format($qty, 3) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                        {{ number_format((float) $product->low_stock_threshold, 3) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        @if ($isOut)
                                            <span class="inline-flex rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-medium uppercase">
                                                Out of Stock
                                            </span>
                                        @else
                                            <span class="inline-flex rounded border border-amber-600 text-amber-700 bg-transparent px-1.5 py-0.5 text-[10px] font-medium uppercase">
                                                Low Stock
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap">
                                        <a href="{{ route('inventory.stock-in') }}" wire:navigate
                                            class="rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                                            Stock In
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="10" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                        <span class="text-emerald-700 font-medium">✓ No low-stock items detected matching the selected filters.</span>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($lowStockProducts->hasPages())
                    <div class="p-3 border-t border-slate-200 bg-slate-50">
                        {{ $lowStockProducts->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Operational Activity Overview (3 Columns) -->
    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Recent Sales -->
        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                <h3 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-700">Recent Completed Sales</h3>
                <a href="{{ route('sales.history') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">History</a>
            </div>
            <div class="mt-3 divide-y divide-slate-100 text-xs">
                @forelse ($recentSales as $sale)
                    <div class="flex items-center justify-between py-2">
                        <div class="truncate mr-2">
                            <span class="font-mono font-medium text-slate-900 block truncate">{{ $sale->invoice_number }}</span>
                            <span class="font-mono text-[10px] text-slate-500">{{ $sale->sold_at->format('M d, H:i') }} · {{ $sale->user?->name ?? 'Cashier' }}</span>
                        </div>
                        <span class="tabular-nums font-medium text-slate-900 shrink-0">{{ $currency::format($sale->total) }}</span>
                    </div>
                @empty
                    <p class="py-4 text-center text-slate-400 text-xs">No sales recorded yet.</p>
                @endforelse
            </div>
        </section>

        <!-- Recent Stock-Ins -->
        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                <h3 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-700">Recent Stock-Ins</h3>
                <a href="{{ route('inventory.stock-in') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">Stock In</a>
            </div>
            <div class="mt-3 divide-y divide-slate-100 text-xs">
                @forelse ($recentStockIns as $stockIn)
                    <div class="flex items-center justify-between py-2">
                        <div class="truncate mr-2">
                            <span class="font-medium text-slate-900 block truncate">Stock-In #{{ $stockIn->id }}</span>
                            <span class="font-mono text-[10px] text-slate-500">{{ $stockIn->received_at->format('M d, Y') }} · {{ $stockIn->items->count() }} item(s)</span>
                        </div>
                        <span class="rounded border border-slate-300 bg-slate-50 px-1.5 py-0.5 tabular-nums text-[11px] font-medium text-slate-700 shrink-0">
                            +{{ number_format((float) $stockIn->items->sum('quantity'), 3) }} pkgs
                        </span>
                    </div>
                @empty
                    <p class="py-4 text-center text-slate-400 text-xs">No stock-ins recorded yet.</p>
                @endforelse
            </div>
        </section>

        <!-- Recent Movements -->
        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200">
                <h3 class="font-heading font-bold text-xs uppercase tracking-wider text-slate-700">Recent Movements</h3>
                <a href="{{ route('inventory.movements') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">Audit Trail</a>
            </div>
            <div class="mt-3 divide-y divide-slate-100 text-xs">
                @forelse ($recentMovements as $movement)
                    <div class="flex items-center justify-between py-2">
                        <div class="truncate mr-2">
                            <span class="font-medium text-slate-900 block truncate">{{ $movement->product?->name }}</span>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="rounded border border-slate-300 px-1 py-0.2 text-[9px] font-medium uppercase tracking-wider text-slate-600">
                                    {{ str_replace('_', ' ', $movement->type) }}
                                </span>
                                <span class="font-mono text-[10px] text-slate-400">{{ $movement->created_at->format('H:i') }}</span>
                            </div>
                        </div>
                        <span class="tabular-nums font-medium shrink-0 {{ $movement->quantity_change < 0 ? 'text-rose-600' : 'text-emerald-600' }}">
                            {{ $movement->quantity_change > 0 ? '+' : '' }}{{ number_format((float) $movement->quantity_change, 3) }}
                        </span>
                    </div>
                @empty
                    <p class="py-4 text-center text-slate-400 text-xs">No movement history yet.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
