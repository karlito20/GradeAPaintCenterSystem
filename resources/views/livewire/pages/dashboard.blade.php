<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
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

        // 7-day sales trend & metrics
        $sevenDays = [];
        $sevenDaysTotal = 0.0;
        $sevenDaysTransactionsCount = 0;
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $daySalesQuery = Sale::whereDate('sold_at', $date);
            $dayTotal = (float) $daySalesQuery->sum('total');
            $dayCount = (int) $daySalesQuery->count();
            $sevenDaysTotal += $dayTotal;
            $sevenDaysTransactionsCount += $dayCount;
            $sevenDays[] = [
                'day' => $date->format('D'),
                'full_day' => $date->format('l'),
                'date' => $date->format('M d'),
                'total' => $dayTotal,
                'count' => $dayCount,
            ];
        }
        $maxSales = max(1, collect($sevenDays)->max('total'));
        $sevenDaysAvg = $sevenDaysTotal / 7;

        // Separate Low Stock and Out of Stock counts
        $lowStockCount = Product::query()
            ->where('active', true)
            ->whereHas('inventory', function ($q) {
                $q->whereRaw('inventories.quantity > 0 AND inventories.quantity <= products.low_stock_threshold');
            })
            ->count();

        $outOfStockCount = Product::query()
            ->where('active', true)
            ->where(function ($q) {
                $q->whereDoesntHave('inventory')
                    ->orWhereHas('inventory', fn ($sub) => $sub->where('quantity', '<=', 0));
            })
            ->count();

        // Low / Out stock query with pagination
        $lowStockQuery = Product::query()
            ->with(['inventory', 'packageUnit', 'brand'])
            ->where('active', true)
            ->where(function ($q) {
                $q->whereDoesntHave('inventory')
                    ->orWhereHas('inventory', function ($sub) {
                        $sub->whereRaw('inventories.quantity <= products.low_stock_threshold');
                    });
            })
            ->when($this->lowStockSearch, function ($q) {
                $term = '%' . trim($this->lowStockSearch) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->lowStockBrand, fn ($q) => $q->where('brand_id', $this->lowStockBrand))
            ->when($this->lowStockCategory, fn ($q) => $q->where('category_id', $this->lowStockCategory))
            ->when($this->lowStockStatus === 'out', function ($q) {
                $q->where(function ($sub) {
                    $sub->whereDoesntHave('inventory')
                        ->orWhereHas('inventory', fn ($inv) => $inv->where('quantity', '<=', 0));
                });
            })
            ->when($this->lowStockStatus === 'low', function ($q) {
                $q->whereHas('inventory', fn ($sub) => $sub->whereRaw('quantity > 0 AND quantity <= products.low_stock_threshold'));
            })
            ->orderBy('name');

        $todaySalesTotal = (float) Sale::whereDate('sold_at', $today)->sum('total');
        $todayTransactionsCount = Sale::whereDate('sold_at', $today)->count();

        return view('livewire.pages.dashboard', [
            'totalProducts' => $totalProducts,
            'lowStockCount' => $lowStockCount,
            'outOfStockCount' => $outOfStockCount,
            'todaySalesTotal' => $todaySalesTotal,
            'todayTransactionsCount' => $todayTransactionsCount,
            'sevenDays' => $sevenDays,
            'sevenDaysTotal' => $sevenDaysTotal,
            'sevenDaysAvg' => $sevenDaysAvg,
            'sevenDaysTransactionsCount' => $sevenDaysTransactionsCount,
            'maxSales' => $maxSales,
            'lowStockProducts' => $lowStockQuery->paginate(15),
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'currency' => Currency::class,
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
    class="space-y-6 w-full min-w-0"
>
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="font-heading text-2xl font-bold tracking-tight text-slate-900">Dashboard</h1>
        </div>
    </div>

    <!-- Top Operational KPIs (4 cards in responsive grid) -->
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <!-- 1. Today's Sales -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Today's Sales</span>
                <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase text-emerald-700 tabular-nums">
                    <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ $todayTransactionsCount }} txn(s)
                </span>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ $currency::format($todaySalesTotal) }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Gross completed sales</p>
        </div>

        <!-- 2. Catalog SKUs -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Catalog SKUs</span>
                <a href="{{ route('products.index') }}" wire:navigate class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[10px] font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">View All</a>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">{{ $totalProducts }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Active stocked products</p>
        </div>

        <!-- 3. Low Stock (Separate KPI) -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Low Stock</span>
                <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase {{ $lowStockCount > 0 ? 'text-amber-700' : 'text-emerald-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $lowStockCount > 0 ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                    {{ $lowStockCount > 0 ? 'Attention Needed' : 'Normal' }}
                </span>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light {{ $lowStockCount > 0 ? 'text-amber-600' : 'text-emerald-600' }} text-right">{{ $lowStockCount }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">SKUs nearing threshold</p>
        </div>

        <!-- 4. Out of Stock (Separate KPI) -->
        <div class="rounded-lg border border-slate-200 bg-white p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="font-heading text-xs font-normal uppercase tracking-wider text-slate-500">Out of Stock</span>
                <span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase {{ $outOfStockCount > 0 ? 'text-rose-700' : 'text-emerald-700' }}">
                    <span class="h-1.5 w-1.5 rounded-full {{ $outOfStockCount > 0 ? 'bg-rose-500' : 'bg-emerald-500' }}"></span>
                    {{ $outOfStockCount > 0 ? 'Critical' : 'All In Stock' }}
                </span>
            </div>
            <p class="mt-3 tabular-nums text-3xl sm:text-4xl font-light {{ $outOfStockCount > 0 ? 'text-rose-600' : 'text-emerald-600' }} text-right">{{ $outOfStockCount }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">SKUs with zero inventory</p>
        </div>
    </div>

    <!-- 7-Day Sales Report Card (Full Row below KPI cards) -->
    <div class="rounded-lg border border-slate-200 bg-white p-4 sm:p-5 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-slate-200 pb-3">
            <div class="flex items-center gap-2">
                <h2 class="font-heading text-sm font-bold uppercase tracking-wider text-slate-800">7-Day Sales Overview</h2>
                <span class="text-xs text-slate-500">(Daily completed revenue trend)</span>
            </div>
            <div class="flex flex-wrap items-center gap-4 text-xs">
                <div class="border-l border-slate-200 pl-3">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">7-Day Total</span>
                    <span class="font-semibold text-slate-900 tabular-nums">{{ $currency::format($sevenDaysTotal) }}</span>
                </div>
                <div class="border-l border-slate-200 pl-3">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Daily Average</span>
                    <span class="font-semibold text-slate-900 tabular-nums">{{ $currency::format($sevenDaysAvg) }}</span>
                </div>
                <div class="border-l border-slate-200 pl-3">
                    <span class="text-[10px] uppercase font-bold text-slate-400 block">Transactions</span>
                    <span class="font-semibold text-slate-900 tabular-nums">{{ $sevenDaysTransactionsCount }} txns</span>
                </div>
            </div>
        </div>

        <!-- 7-Day Bar Chart Visualization -->
        <div class="pt-2">
            <div class="grid grid-cols-7 gap-2 sm:gap-4 items-end h-40">
                @foreach ($sevenDays as $day)
                    @php
                        $heightPercent = $maxSales > 0 ? round(($day['total'] / $maxSales) * 100) : 0;
                        $heightPercent = max(6, min(100, $heightPercent));
                    @endphp
                    <div class="flex flex-col items-center h-full justify-end group relative">
                        <!-- Bar Value on Top -->
                        <span class="text-[10px] font-semibold text-slate-700 tabular-nums mb-1 opacity-80 group-hover:opacity-100 transition truncate max-w-full">
                            {{ $day['total'] > 0 ? $currency::format($day['total']) : '₱0' }}
                        </span>
                        <!-- Bar Column -->
                        <div class="w-full max-w-[48px] bg-slate-100 rounded-t overflow-hidden flex flex-col justify-end" style="height: 100%;">
                            <div class="w-full bg-[#00a3cc] rounded-t transition-all duration-300 group-hover:bg-[#008fb3]" style="height: {{ $heightPercent }}%;"></div>
                        </div>
                        <!-- Day & Date Label -->
                        <span class="text-[11px] font-semibold text-slate-800 mt-1.5 tabular-nums">{{ $day['day'] }}</span>
                        <span class="text-[10px] text-slate-400 tabular-nums">{{ $day['date'] }}</span>
                        <!-- Tooltip -->
                        <div class="absolute bottom-full mb-2 hidden group-hover:block z-30 whitespace-nowrap rounded border border-slate-700 bg-slate-900 px-2.5 py-1.5 text-[11px] text-white shadow-xl tabular-nums pointer-events-none">
                            <p class="font-bold">{{ $day['full_day'] }}, {{ $day['date'] }}</p>
                            <p class="text-slate-300">Revenue: {{ $currency::format($day['total']) }}</p>
                            <p class="text-slate-400">{{ $day['count'] }} transaction(s)</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Low / Out of Stock Section -->
    <div class="space-y-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <h2 class="font-heading text-sm font-bold uppercase tracking-wider text-slate-800">Stock Alerts</h2>
                <span class="font-mono text-xs text-slate-500">({{ $lowStockCount }} low, {{ $outOfStockCount }} out of stock)</span>
            </div>
        </div>

        <!-- Table Container with Seamless Top Filters -->
        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <!-- Horizontal Filter Bar Directly Above Table -->
            <div class="p-3 bg-slate-50 border-b border-slate-200 flex flex-wrap items-center gap-2.5">
                <div class="w-56">
                    <input 
                        wire:model.live.debounce.300ms="lowStockSearch" 
                        type="search" 
                        placeholder="Search product name or SKU..." 
                        class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                    />
                </div>
                <div class="w-40">
                    <select wire:model.live="lowStockStatus" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Low &amp; Out</option>
                        <option value="low">Low Stock Only</option>
                        <option value="out">Out of Stock Only</option>
                    </select>
                </div>
                <div class="w-40">
                    <select wire:model.live="lowStockBrand" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Brands</option>
                        @foreach ($brands as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="w-40">
                    <select wire:model.live="lowStockCategory" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Categories</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($lowStockSearch !== '' || $lowStockStatus !== '' || $lowStockBrand !== '' || $lowStockCategory !== '')
                    <button 
                        wire:click="resetLowStockFilters" 
                        type="button" 
                        class="text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium ml-auto"
                    >
                        Reset
                    </button>
                @endif
            </div>

            <!-- Table -->
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">SKU</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">Product Name</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-20 whitespace-nowrap">Unit</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">Brand</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-28 whitespace-nowrap">Current Stock</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Threshold</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28 whitespace-nowrap">Status</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($lowStockProducts as $product)
                            @php
                                $qty = (float) ($product->inventory?->quantity ?? 0);
                                $threshold = (float) $product->low_stock_threshold;
                                $isOut = $qty <= 0;
                            @endphp
                            <tr 
                                class="hover:bg-slate-50 transition-colors cursor-default" 
                                wire:key="dashboard-stock-{{ $product->id }}"
                                @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $product->id }}, sku: '{{ $product->sku }}', name: '{{ addslashes($product->name) }}' })"
                            >
                                <td class="border border-slate-200 px-2.5 py-1.5 font-mono text-slate-700 whitespace-nowrap font-medium">{{ $product->sku }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 break-words whitespace-normal" title="{{ $product->name }}">{{ $product->name }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap font-medium">
                                    {{ $product->packageUnit?->abbreviation ?? $product->packageUnit?->name ?? '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-left text-slate-600 whitespace-nowrap">
                                    {{ $product->brand?->name ?? '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $isOut ? 'text-rose-700' : 'text-amber-700' }}">
                                    {{ number_format($qty, 2) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                    {{ number_format($threshold, 2) }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($isOut)
                                         <span class="inline-flex items-center text-[10px] font-bold uppercase text-rose-700">
                                            Out of Stock
                                        </span>
                                    @else
                                        <span class="inline-flex items-center text-[10px] font-bold uppercase text-amber-700">
                                            Low Stock
                                        </span>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    <button 
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $product->id }}, sku: '{{ $product->sku }}', name: '{{ addslashes($product->name) }}' })"
                                        type="button" 
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="Actions"
                                    >
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <circle cx="12" cy="5" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="12" r="1.5" fill="currentColor" stroke="none" />
                                            <circle cx="12" cy="19" r="1.5" fill="currentColor" stroke="none" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    <span class="text-emerald-700 font-medium">&check; No low-stock or out-of-stock items detected matching the selected filters.</span>
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

    <!-- Floating Context Menu -->
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
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <a 
            :href="contextMenu.item ? `{{ route('products.index') }}?search=${encodeURIComponent(contextMenu.item.sku)}` : '#'"
            wire:navigate
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View in Catalog</span>
        </a>
        <a 
            href="{{ route('inventory.stock-in') }}"
            wire:navigate
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span>Record Stock In</span>
        </a>
        <div class="my-1 border-t border-slate-100"></div>
        <button 
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.sku); $dispatch('toast', { type: 'success', message: 'SKU ' + contextMenu.item.sku + ' copied to clipboard.' }); contextMenu.close(); }"
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy SKU</span>
        </button>
    </div>
</div>
