<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\PhysicalInventoryItem;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $status = 'all'; // 'all', 'checked', 'pending'
    public string $datePreset = 'all';
    public string $dateFrom = '';
    public string $dateTo = '';
    public int $perPage = 25;

    // View Details Modal
    public bool $showDetailsModal = false;
    public ?int $selectedItemId = null;
    public ?array $selectedDetails = null;

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

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

    public function updatedStatus(): void
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
        $this->reset(['search', 'brandId', 'categoryId', 'status', 'dateFrom', 'dateTo']);
        $this->datePreset = 'all';
        $this->resetPage();
    }

    public function openDetails(int $itemId): void
    {
        $item = PhysicalInventoryItem::with(['product.brand', 'product.category', 'product.packageUnit', 'physicalInventory.user'])->find($itemId);
        if (!$item) {
            return;
        }

        $this->selectedItemId = $item->id;
        $this->selectedDetails = [
            'id' => $item->id,
            'sku' => $item->product?->sku ?? '—',
            'product_name' => $item->product?->name ?? '—',
            'brand' => $item->product?->brand?->name ?? '—',
            'category' => $item->product?->category?->name ?? '—',
            'unit' => $item->product?->packageUnit?->abbreviation ?? '—',
            'system_quantity' => (float) $item->system_quantity,
            'physical_quantity' => (float) $item->physical_quantity,
            'variance' => (float) $item->variance,
            'estimated_used' => (float) ($item->estimated_used_quantity ?? abs($item->variance)),
            'counted_at' => $item->physicalInventory?->counted_at?->format('M d, Y') ?? $item->created_at->format('M d, Y'),
            'counted_time' => $item->created_at->format('h:i A'),
            'auditor' => $item->physicalInventory?->user?->name ?? 'System',
            'reason' => $item->reason ?? 'Mixing paint usage physical check',
            'inventory_id' => $item->physical_inventory_id,
        ];
        $this->showDetailsModal = true;
    }

    public function closeDetails(): void
    {
        $this->showDetailsModal = false;
        $this->selectedItemId = null;
        $this->selectedDetails = null;
    }

    public function render(): mixed
    {
        // 1. Completed mixing checks query
        $checkedQuery = PhysicalInventoryItem::query()
            ->with(['product.brand', 'product.category', 'product.packageUnit', 'physicalInventory.user'])
            ->where('was_mixing_check', true)
            ->when($this->search !== '', function ($q) {
                file: ///home/karlito/IT12-Final-Document.pdf
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->whereHas('product', fn($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term))->orWhere('reason', 'like', $term);
                });
            })
            ->when($this->brandId !== '', fn($q) => $q->whereHas('product', fn($p) => $p->where('brand_id', $this->brandId)))
            ->when($this->categoryId !== '', fn($q) => $q->whereHas('product', fn($p) => $p->where('category_id', $this->categoryId)))
            ->when($this->dateFrom !== '', fn($q) => $q->whereDate('created_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn($q) => $q->whereDate('created_at', '<=', $this->dateTo))
            ->latest('id');

        // 2. Pending checks query (products flagged for physical check)
        $pendingQuery = Product::query()
            ->with(['brand', 'category', 'packageUnit', 'inventories'])
            ->where('requires_physical_check', true)
            ->when($this->search !== '', function ($q) {
                $term = '%' . trim($this->search) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)->orWhere('sku', 'like', $term);
                });
            })
            ->when($this->brandId !== '', fn($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId !== '', fn($q) => $q->where('category_id', $this->categoryId))
            ->when($this->dateFrom !== '', fn($q) => $q->whereDate('updated_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn($q) => $q->whereDate('updated_at', '<=', $this->dateTo))
            ->latest('updated_at');

        // Global KPI metrics
        $totalPaintsUsedCount = DB::table('mixing_components')->distinct('product_id')->count('product_id');
        $totalEstimatedVolumeUsed = (float) PhysicalInventoryItem::where('was_mixing_check', true)->sum('estimated_used_quantity');
        $totalVerifiedChecksCount = PhysicalInventoryItem::where('was_mixing_check', true)->count();
        $totalPendingChecksCount = Product::where('requires_physical_check', true)->count();

        // Build paginated dataset based on status
        if ($this->status === 'pending') {
            $paginatedPending = $pendingQuery->paginate($this->perPage);
            $items = $paginatedPending->through(function ($product) {
                $systemQty = (float) $product->inventories->sum('quantity');
                return (object) [
                    'id' => 'pending-' . $product->id,
                    'is_pending' => true,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'brand_name' => $product->brand?->name ?? '—',
                    'category_name' => $product->category?->name ?? '—',
                    'unit' => $product->packageUnit?->abbreviation ?? '—',
                    'system_quantity' => $systemQty,
                    'physical_quantity' => null,
                    'estimated_used' => null,
                    'status' => 'pending',
                    'counted_at' => $product->updated_at,
                    'auditor' => null,
                    'reason' => 'Awaiting physical count after mixing transaction',
                    'raw_item' => null,
                ];
            });
            $paginator = $paginatedPending;
        } elseif ($this->status === 'checked') {
            $paginatedChecked = $checkedQuery->paginate($this->perPage);
            $items = $paginatedChecked->through(function ($item) {
                return (object) [
                    'id' => $item->id,
                    'is_pending' => false,
                    'product_id' => $item->product_id,
                    'sku' => $item->product?->sku ?? '—',
                    'product_name' => $item->product?->name ?? '—',
                    'brand_name' => $item->product?->brand?->name ?? '—',
                    'category_name' => $item->product?->category?->name ?? '—',
                    'unit' => $item->product?->packageUnit?->abbreviation ?? '—',
                    'system_quantity' => (float) $item->system_quantity,
                    'physical_quantity' => (float) $item->physical_quantity,
                    'estimated_used' => (float) ($item->estimated_used_quantity ?? abs($item->variance)),
                    'status' => 'completed',
                    'counted_at' => $item->physicalInventory?->counted_at ? Carbon::parse($item->physicalInventory->counted_at) : $item->created_at,
                    'auditor' => $item->physicalInventory?->user?->name ?? 'System',
                    'reason' => $item->reason,
                    'raw_item' => $item,
                ];
            });
            $paginator = $paginatedChecked;
        } else {
            // 'all': Combine pending items at top with completed items
            $allPending = $pendingQuery->get()->map(function ($product) {
                $systemQty = (float) $product->inventories->sum('quantity');
                return (object) [
                    'id' => 'pending-' . $product->id,
                    'is_pending' => true,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'brand_name' => $product->brand?->name ?? '—',
                    'category_name' => $product->category?->name ?? '—',
                    'unit' => $product->packageUnit?->abbreviation ?? '—',
                    'system_quantity' => $systemQty,
                    'physical_quantity' => null,
                    'estimated_used' => null,
                    'status' => 'pending',
                    'counted_at' => $product->updated_at,
                    'auditor' => null,
                    'reason' => 'Awaiting physical count after mixing transaction',
                    'raw_item' => null,
                ];
            });

            $allChecked = $checkedQuery->get()->map(function ($item) {
                return (object) [
                    'id' => $item->id,
                    'is_pending' => false,
                    'product_id' => $item->product_id,
                    'sku' => $item->product?->sku ?? '—',
                    'product_name' => $item->product?->name ?? '—',
                    'brand_name' => $item->product?->brand?->name ?? '—',
                    'category_name' => $item->product?->category?->name ?? '—',
                    'unit' => $item->product?->packageUnit?->abbreviation ?? '—',
                    'system_quantity' => (float) $item->system_quantity,
                    'physical_quantity' => (float) $item->physical_quantity,
                    'estimated_used' => (float) ($item->estimated_used_quantity ?? abs($item->variance)),
                    'status' => 'completed',
                    'counted_at' => $item->physicalInventory?->counted_at ? Carbon::parse($item->physicalInventory->counted_at) : $item->created_at,
                    'auditor' => $item->physicalInventory?->user?->name ?? 'System',
                    'reason' => $item->reason,
                    'raw_item' => $item,
                ];
            });

            $combined = $allPending->concat($allChecked);
            $page = $this->getPage();
            $sliced = $combined->slice(($page - 1) * $this->perPage, $this->perPage)->values();
            $paginator = new LengthAwarePaginator($sliced, $combined->count(), $this->perPage, $page, [
                'path' => request()->url(),
            ]);
            $items = $sliced;
        }

        return view('livewire.pages.reports.mixing-usage', [
            'items' => $items,
            'paginator' => $paginator,
            'totalPaintsUsedCount' => $totalPaintsUsedCount,
            'totalEstimatedVolumeUsed' => $totalEstimatedVolumeUsed,
            'totalVerifiedChecksCount' => $totalVerifiedChecksCount,
            'totalPendingChecksCount' => $totalPendingChecksCount,
            'brands' => Brand::query()->where('active', true)->orderBy('name')->get(),
            'categories' => Category::query()->where('active', true)->orderBy('name')->get(),
        ]);
    }
}; ?>

<div class="space-y-4">
    <!-- Page Header (matching standard sales/inventory pages) -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-lg font-bold text-slate-900 tracking-tight">Mixing Paint Usage Report</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Paints used in mixing transactions and estimated quantities derived from physical counts.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('inventory.index') }}" wire:navigate
                class="inline-flex items-center justify-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
                General Inventory
            </a>

            <a href="{{ route('inventory.physical-count') }}" wire:navigate
                class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-3.5 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-[#008fb3] transition">
                <svg class="mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                Physical Count
            </a>
        </div>
    </div>

    <!-- Date Presets Ribbon -->
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
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Paints Used in Mixing</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">
                {{ $totalPaintsUsedCount }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">Unique formula components</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Total Est. Quantity Used</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-slate-900 text-right">
                {{ number_format($totalEstimatedVolumeUsed, 2) }}</p>
            <p class="mt-1 text-[11px] text-slate-400 text-right">From physical count variance</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Verified Audits</p>
            <p class="mt-2 tabular-nums text-3xl sm:text-4xl font-light text-emerald-700 text-right">
                {{ $totalVerifiedChecksCount }}</p>
            <p class="mt-1 text-[11px] text-emerald-600 text-right">Inventory adjusted</p>
        </div>

        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs cursor-pointer hover:border-slate-400 transition"
            wire:click="$set('status', 'pending')">
            <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Awaiting Physical Check</p>
            <p
                class="mt-2 tabular-nums text-3xl sm:text-4xl font-light {{ $totalPendingChecksCount > 0 ? 'text-amber-600' : 'text-slate-900' }} text-right">
                {{ $totalPendingChecksCount }}
            </p>
            <p
                class="mt-1 text-[11px] {{ $totalPendingChecksCount > 0 ? 'text-amber-600 font-medium' : 'text-slate-400' }} text-right">
                {{ $totalPendingChecksCount > 0 ? 'Click to filter pending' : 'All up-to-date' }}
            </p>
        </div>
    </div>

    <!-- Alert Banner when Paints Require Physical Check -->
    @if ($totalPendingChecksCount > 0 && $status !== 'pending')
        <div
            class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 shadow-xs">
            <div class="flex items-center gap-2">
                <svg class="h-4 w-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>
                    <strong class="font-semibold">{{ $totalPendingChecksCount }} paint(s)</strong> have been used in
                    mixing transactions and are awaiting physical count to verify estimated usage.
                </span>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" wire:click="$set('status', 'pending')"
                    class="font-semibold text-amber-800 underline hover:text-amber-950">
                    Filter Pending
                </button>
                <a href="{{ route('inventory.physical-count') }}" wire:navigate
                    class="rounded bg-amber-600 px-2.5 py-1 text-white font-medium hover:bg-amber-700 transition">
                    Count Now &rarr;
                </a>
            </div>
        </div>
    @endif

    <!-- Main Content & Filters -->
    <div class="space-y-2">
        <!-- Filter Controls Outside the Table Div (h-9 standardized controls) -->
        <div class="flex flex-wrap items-center gap-2.5">
            <!-- Search SKU / Product -->
            <div class="w-64">
                <input wire:model.live.debounce.250ms="search" type="search" placeholder="Search SKU, paint name..."
                    class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <!-- Status Filter -->
            <div class="w-44">
                <select wire:model.live="status"
                    class="w-full h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="all">All Statuses</option>
                    <option value="checked">Checked &amp; Deducted</option>
                    <option value="pending">Awaiting Physical Check</option>
                </select>
            </div>

            <!-- Brand Filter -->
            <div class="w-36">
                <select wire:model.live="brandId"
                    class="w-full h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Brands</option>
                    @foreach ($brands as $brand)
                        <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Category Filter -->
            <div class="w-36">
                <select wire:model.live="categoryId"
                    class="w-full h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="">All Categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Custom Date Range -->
            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">From:</span>
                <input wire:model.live="dateFrom" type="date" title="From Date"
                    class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            <div class="flex items-center gap-1.5 text-xs text-slate-600">
                <span class="font-semibold text-[11px] uppercase">To:</span>
                <input wire:model.live="dateTo" type="date" title="To Date"
                    class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
            </div>

            @if ($search !== '' || $status !== 'all' || $brandId !== '' || $categoryId !== '' || $dateFrom !== '' || $dateTo !== '')
                <button wire:click="resetFilters" type="button"
                    class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                    Reset
                </button>
            @endif
        </div>

        <!-- Showing X of N Items Indicator & Amount Per Page -->
        <div class="flex items-center justify-between text-xs text-slate-500">
            <div>
                @if ($paginator->total() > 0)
                    Showing <span class="font-medium text-slate-700">{{ $paginator->firstItem() }}</span> to
                    <span class="font-medium text-slate-700">{{ $paginator->lastItem() }}</span> of
                    <span class="font-medium text-slate-700">{{ $paginator->total() }}</span> records
                @else
                    Showing <span class="font-medium text-slate-700">0</span> records
                @endif
            </div>
            <div class="flex items-center gap-1.5">
                <label for="mixingUsagePerPage" class="text-xs text-slate-500">Show:</label>
                <select wire:model.live="perPage" id="mixingUsagePerPage"
                    class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <span class="text-xs text-slate-500">items</span>
            </div>
        </div>

        <!-- Main Table Container -->
        <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
            <div class="overflow-x-auto w-full">
                <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead
                        class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-left w-28 whitespace-nowrap">SKU</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-left w-24 whitespace-nowrap">Date
                            </th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-left w-20 whitespace-nowrap">Time
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">
                                Product Name</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-left w-24 whitespace-nowrap">Brand
                            </th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Unit
                            </th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-right w-28 whitespace-nowrap">Prev
                                Stored</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Checked
                                Qty</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-right w-28 whitespace-nowrap font-bold text-slate-900">
                                Est. Qty Used</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-center w-28 whitespace-nowrap">Status
                            </th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-left w-24 whitespace-nowrap">Auditor
                            </th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Action
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @forelse ($items as $item)
                            <tr class="hover:bg-slate-50 transition-colors cursor-default {{ $item->is_pending ? 'bg-amber-50/40' : '' }}"
                                wire:key="row-{{ $item->id }}">
                                <!-- SKU -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap font-mono">
                                    {{ $item->sku }}
                                </td>
                                <!-- Date -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-800 whitespace-nowrap">
                                    {{ $item->counted_at ? $item->counted_at->format('M d, Y') : '—' }}
                                </td>

                                <!-- Time -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500 whitespace-nowrap">
                                    {{ $item->counted_at ? $item->counted_at->format('h:i A') : '—' }}
                                </td>


                                <!-- Product Name -->
                                <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal"
                                    title="{{ $item->product_name }}">
                                    <span>{{ $item->product_name }}</span>
                                </td>

                                <!-- Brand -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $item->brand_name }}
                                </td>

                                <!-- Unit -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-700 whitespace-nowrap font-medium">
                                    {{ $item->unit }}
                                </td>

                                <!-- Prev Stored Qty -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ number_format($item->system_quantity, 2) }}
                                </td>

                                <!-- Checked Qty -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap">
                                    @if ($item->is_pending)
                                        <span class="text-slate-400 italic text-[11px]">Pending</span>
                                    @else
                                        <span
                                            class="text-slate-800 font-medium">{{ number_format($item->physical_quantity, 2) }}</span>
                                    @endif
                                </td>

                                <!-- Estimated Quantity Used -->
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums whitespace-nowrap">
                                    @if ($item->is_pending)
                                        <span class="text-amber-600 font-semibold text-[11px] italic">Awaiting
                                            check</span>
                                    @else
                                        <span class="font-bold text-slate-900 text-xs">
                                            {{ number_format($item->estimated_used, 2) }}
                                            <span
                                                class="text-[10px] font-normal text-slate-500">{{ $item->unit }}</span>
                                        </span>
                                    @endif
                                </td>

                                <!-- Status -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($item->is_pending)
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800">
                                            Needs Count
                                        </span>
                                    @else
                                        <span
                                            class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-emerald-100 text-emerald-800">
                                            Checked &amp; Deducted
                                        </span>
                                    @endif
                                </td>

                                <!-- Auditor -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $item->auditor ?? '—' }}
                                </td>

                                <!-- Action -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($item->is_pending)
                                        <a href="{{ route('inventory.physical-count') }}" wire:navigate
                                            class="inline-flex items-center justify-center rounded border border-amber-300 bg-white px-2 py-1 text-[11px] font-bold text-amber-800 hover:bg-amber-50 transition shadow-xs">
                                            Count
                                        </a>
                                    @else
                                        <button wire:click="openDetails({{ $item->id }})" type="button"
                                            title="View audit details"
                                            class="inline-flex items-center justify-center rounded border border-slate-300 bg-white p-1 text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition shadow-xs">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor"
                                                viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12"
                                    class="border border-slate-200 px-4 py-12 text-center text-slate-500">
                                    <p class="font-medium text-xs">No mixing paint usage records found.</p>
                                    <p class="text-[11px] mt-1 text-slate-400">
                                        Paints used in mixing transactions will be flagged for physical check and their
                                        estimated usage recorded upon count confirmation.
                                    </p>
                                    @if ($search !== '' || $status !== 'all' || $brandId !== '' || $categoryId !== '' || $dateFrom !== '' || $dateTo !== '')
                                        <button wire:click="resetFilters" type="button"
                                            class="mt-2 text-xs text-[#00a3cc] hover:underline font-medium">
                                            Clear all filters
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Table Footer Pagination -->
            @if ($paginator->hasPages())
                <div class="border-t border-slate-200 px-3 py-2 bg-slate-50">
                    {{ $paginator->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- View Details Modal -->
    @if ($showDetailsModal && $selectedDetails)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-xs"
            wire:keydown.escape="closeDetails">
            <div class="w-full max-w-md rounded-lg border border-slate-300 bg-white shadow-xl overflow-hidden"
                @click.outside="$wire.closeDetails()">
                <!-- Modal Header -->
                <div class="flex items-center justify-between border-b border-slate-200 bg-slate-50 px-4 py-3">
                    <div>
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-800">Mixing Usage Audit
                            Details</h3>
                        <p class="text-[11px] text-slate-500">Physical count #{{ $selectedDetails['inventory_id'] }}
                        </p>
                    </div>
                    <button wire:click="closeDetails" type="button" class="text-slate-400 hover:text-slate-600 p-1">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <!-- Modal Body -->
                <div class="p-4 space-y-3 text-xs">
                    <div class="rounded border border-slate-200 bg-slate-50/50 p-2.5 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="font-mono text-[11px] text-slate-500">{{ $selectedDetails['sku'] }}</span>
                            <span class="font-medium text-slate-600">{{ $selectedDetails['brand'] }}</span>
                        </div>
                        <h4 class="font-bold text-slate-900 text-sm">{{ $selectedDetails['product_name'] }}</h4>
                        <p class="text-[11px] text-slate-500">Category: {{ $selectedDetails['category'] }}</p>
                    </div>

                    <div class="grid grid-cols-3 gap-2 border-y border-slate-200 py-3 text-center">
                        <div class="rounded bg-slate-50 p-2">
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Prev Stored</span>
                            <span class="text-sm font-semibold text-slate-800 tabular-nums">
                                {{ number_format($selectedDetails['system_quantity'], 2) }}
                            </span>
                            <span class="text-[10px] text-slate-500 block">{{ $selectedDetails['unit'] }}</span>
                        </div>
                        <div class="rounded bg-slate-50 p-2">
                            <span class="text-[10px] uppercase font-bold text-slate-400 block">Physical Count</span>
                            <span class="text-sm font-semibold text-slate-800 tabular-nums">
                                {{ number_format($selectedDetails['physical_quantity'], 2) }}
                            </span>
                            <span class="text-[10px] text-slate-500 block">{{ $selectedDetails['unit'] }}</span>
                        </div>
                        <div class="rounded bg-emerald-50 border border-emerald-200 p-2">
                            <span class="text-[10px] uppercase font-bold text-emerald-700 block">Est. Used</span>
                            <span class="text-sm font-bold text-emerald-800 tabular-nums">
                                {{ number_format($selectedDetails['estimated_used'], 2) }}
                            </span>
                            <span class="text-[10px] text-emerald-600 block">{{ $selectedDetails['unit'] }}</span>
                        </div>
                    </div>

                    <div class="space-y-1.5 text-[11px]">
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">Count Date &amp; Time:</span>
                            <span class="font-medium text-slate-800">{{ $selectedDetails['counted_at'] }} at
                                {{ $selectedDetails['counted_time'] }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">Auditor:</span>
                            <span class="font-medium text-slate-800">{{ $selectedDetails['auditor'] }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-slate-100">
                            <span class="text-slate-500">Net Variance:</span>
                            <span
                                class="font-mono font-medium {{ $selectedDetails['variance'] < 0 ? 'text-rose-600' : 'text-slate-700' }}">
                                {{ $selectedDetails['variance'] > 0 ? '+' : '' }}{{ number_format($selectedDetails['variance'], 2) }}
                                {{ $selectedDetails['unit'] }}
                            </span>
                        </div>
                        <div class="pt-1">
                            <span class="text-slate-500 block mb-0.5">Audit Note / Reason:</span>
                            <p class="rounded bg-slate-100 p-2 text-slate-700 font-mono text-[11px] break-words">
                                {{ $selectedDetails['reason'] }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Modal Footer -->
                <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 flex justify-end">
                    <button wire:click="closeDetails" type="button"
                        class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition shadow-xs">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
