<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\PackageUnit;
use App\Models\PhysicalInventory;
use App\Models\PhysicalInventoryItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $activeTab = 'conduct'; // 'conduct' or 'history'

    public array $counts = [];
    public array $lineReasons = [];
    public string $countedAt = '';
    public string $notes = '';

    // Filters
    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitId = '';
    public string $discrepancyFilter = 'all'; // 'all', 'counted', 'discrepancy', 'uncounted'

    // Confirmation Modal
    public bool $showConfirmationModal = false;

    // History inspection
    public ?int $viewingHistoryId = null;

    public function mount(): void
    {
        $this->countedAt = now()->toDateString();
        $this->initializeCounts();
    }

    public function initializeCounts(): void
    {
        $products = Product::query()->with('inventory')->get();
        foreach ($products as $p) {
            if (!isset($this->counts[$p->id])) {
                $this->counts[$p->id] = '';
            }
        }
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

    public function updatedPackageUnitId(): void
    {
        $this->resetPage();
    }

    public function updatedDiscrepancyFilter(): void
    {
        $this->resetPage();
    }

    public function fillFromSystemStock(): void
    {
        $products = Product::query()->with('inventory')->where('active', true)->get();
        foreach ($products as $p) {
            $this->counts[$p->id] = (string) (float) ($p->inventory?->quantity ?? 0);
        }
        $this->dispatch('toast', [
            'type' => 'info',
            'message' => 'All physical count fields pre-filled with current system stock balances.',
        ]);
    }

    public function clearAllCounts(): void
    {
        foreach ($this->counts as $id => $val) {
            $this->counts[$id] = '';
        }
        $this->lineReasons = [];
        $this->dispatch('toast', [
            'type' => 'info',
            'message' => 'Physical count inputs cleared.',
        ]);
    }

    public function promptConfirmation(): void
    {
        // Check if at least one product has a count entered
        $hasCount = false;
        foreach ($this->counts as $val) {
            if ($val !== '' && $val !== null) {
                $hasCount = true;
                break;
            }
        }

        if (!$hasCount) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => 'Please enter a physical count for at least one product before confirming.',
            ]);
            return;
        }

        $this->validate([
            'countedAt' => ['required', 'date'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->showConfirmationModal = true;
    }

    public function cancelConfirmation(): void
    {
        $this->showConfirmationModal = false;
    }

    public function confirmCount(): void
    {
        $this->validate([
            'countedAt' => ['required', 'date'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $itemCount = 0;
        $adjustedCount = 0;

        DB::transaction(function () use (&$itemCount, &$adjustedCount): void {
            $count = PhysicalInventory::create([
                'user_id' => auth()->id(),
                'counted_at' => $this->countedAt,
                'status' => 'completed',
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ]);

            foreach ($this->counts as $productId => $physicalQuantity) {
                if ($physicalQuantity === '' || $physicalQuantity === null) {
                    continue;
                }

                $product = Product::query()->with('inventory')->lockForUpdate()->find($productId);
                if (!$product) {
                    continue;
                }

                $inventory = $product->inventory ?: Inventory::create(['product_id' => $product->id, 'quantity' => 0]);
                $systemQuantity = (float) ($inventory->quantity ?? 0);
                $physical = (float) $physicalQuantity;
                $variance = round($physical - $systemQuantity, 3);
                $lineReason = $this->lineReasons[$productId] ?? null;

                $count->items()->create([
                    'product_id' => $product->id,
                    'system_quantity' => $systemQuantity,
                    'physical_quantity' => $physical,
                    'variance' => $variance,
                    'reason' => $lineReason ?: ($variance != 0 ? $this->notes : null),
                ]);

                $itemCount++;

                // If physical differs from system, update inventory and log movement
                if ($variance != 0) {
                    $inventory->update(['quantity' => $physical]);
                    $adjustedCount++;

                    $reasonText = $lineReason ?: $this->notes;
                    if (empty($reasonText)) {
                        $reasonText = $variance < 0 ? 'Weekly physical count dipstick shortage' : 'Weekly physical count overage';
                    }

                    InventoryMovement::create([
                        'product_id' => $product->id,
                        'user_id' => auth()->id(),
                        'type' => 'physical_adjustment',
                        'quantity_change' => $variance,
                        'quantity_before' => $systemQuantity,
                        'quantity_after' => $physical,
                        'reference_type' => PhysicalInventory::class,
                        'reference_id' => $count->id,
                        'reference_text' => 'Physical audit #' . $count->id . ' (' . ($variance < 0 ? 'Shortage' : 'Overage') . ')',
                        'reason' => $reasonText,
                    ]);
                }
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'physical_inventory_confirmed',
                'auditable_type' => PhysicalInventory::class,
                'auditable_id' => $count->id,
                'context' => [
                    'counted_at' => $this->countedAt,
                    'items_counted' => $itemCount,
                    'items_adjusted' => $adjustedCount,
                    'notes' => $this->notes,
                ],
            ]);
        });

        $this->showConfirmationModal = false;
        $this->reset(['notes', 'lineReasons']);
        $this->initializeCounts();
        $this->countedAt = now()->toDateString();

        $message = "Physical inventory confirmed: {$itemCount} products verified, {$adjustedCount} stock discrepancies adjusted.";
        session()->flash('status', $message);
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    public function viewHistory(int $id): void
    {
        $this->viewingHistoryId = $id;
    }

    public function closeHistoryModal(): void
    {
        $this->viewingHistoryId = null;
    }

    public function render(): mixed
    {
        // Query for active products to count
        $allProducts = Product::query()
            ->with(['inventory', 'category', 'brand', 'packageUnit'])
            ->where('active', true)
            ->get();

        // Calculate discrepancy stats over all active products
        $statsTotalCounted = 0;
        $statsMatched = 0;
        $statsShortage = 0;
        $statsOverage = 0;
        $discrepantItems = [];

        foreach ($allProducts as $p) {
            $val = $this->counts[$p->id] ?? '';
            if ($val !== '' && $val !== null) {
                $statsTotalCounted++;
                $sys = (float) ($p->inventory?->quantity ?? 0);
                $phys = (float) $val;
                $diff = round($phys - $sys, 3);
                if ($diff == 0) {
                    $statsMatched++;
                } elseif ($diff < 0) {
                    $statsShortage++;
                    $discrepantItems[] = [
                        'product' => $p,
                        'system' => $sys,
                        'physical' => $phys,
                        'variance' => $diff,
                        'type' => 'shortage',
                    ];
                } else {
                    $statsOverage++;
                    $discrepantItems[] = [
                        'product' => $p,
                        'system' => $sys,
                        'physical' => $phys,
                        'variance' => $diff,
                        'type' => 'overage',
                    ];
                }
            }
        }

        // Apply filters to product query for display table
        $productsQuery = Product::query()
            ->with(['inventory', 'category', 'brand', 'packageUnit'])
            ->where('active', true)
            ->when($this->search !== '', fn ($q) => $q->where(fn ($sub) => 
                $sub->where('name', 'like', '%' . trim($this->search) . '%')
                    ->orWhere('sku', 'like', '%' . trim($this->search) . '%')
            ))
            ->when($this->brandId !== '', fn ($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId !== '', fn ($q) => $q->where('category_id', $this->categoryId))
            ->when($this->packageUnitId !== '', fn ($q) => $q->where('package_unit_id', $this->packageUnitId));

        // In-memory filter for discrepancy status if selected
        if ($this->discrepancyFilter !== 'all') {
            $matchingIds = [];
            foreach ($allProducts as $p) {
                $val = $this->counts[$p->id] ?? '';
                $hasVal = ($val !== '' && $val !== null);
                $sys = (float) ($p->inventory?->quantity ?? 0);
                $phys = $hasVal ? (float) $val : 0;
                $diff = round($phys - $sys, 3);

                if ($this->discrepancyFilter === 'counted' && $hasVal) {
                    $matchingIds[] = $p->id;
                } elseif ($this->discrepancyFilter === 'discrepancy' && $hasVal && $diff != 0) {
                    $matchingIds[] = $p->id;
                } elseif ($this->discrepancyFilter === 'uncounted' && !$hasVal) {
                    $matchingIds[] = $p->id;
                }
            }
            $productsQuery->whereIn('id', $matchingIds);
        }

        $paginatedProducts = $productsQuery->orderBy('name')->paginate(25);

        // History query
        $historyList = null;
        $viewingCount = null;
        if ($this->activeTab === 'history') {
            $historyList = PhysicalInventory::with(['user', 'items.product.packageUnit', 'items.product.brand'])
                ->latest('counted_at')
                ->latest('id')
                ->paginate(15);
        }

        if ($this->viewingHistoryId) {
            $viewingCount = PhysicalInventory::with(['user', 'items.product.packageUnit', 'items.product.brand'])
                ->find($this->viewingHistoryId);
        }

        return view('livewire.pages.inventory.physical-count', [
            'products' => $paginatedProducts,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'packageUnits' => PackageUnit::where('active', true)->orderBy('name')->get(),
            'statsTotalProducts' => $allProducts->count(),
            'statsTotalCounted' => $statsTotalCounted,
            'statsMatched' => $statsMatched,
            'statsShortage' => $statsShortage,
            'statsOverage' => $statsOverage,
            'discrepantItems' => $discrepantItems,
            'historyList' => $historyList,
            'viewingCount' => $viewingCount,
        ]);
    }
}; ?>

<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('reports.inventory') }}" class="text-xs font-semibold uppercase tracking-wider text-slate-400 hover:text-slate-600">Inventory</a>
                <span class="text-xs text-slate-300">/</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-700">Physical Count</span>
            </div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Physical Count</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('inventory.stock-in') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Stock In
            </a>
            <a href="{{ route('inventory.movements') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Movements
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-xs font-medium text-emerald-800 shadow-xs">
            {{ session('status') }}
        </div>
    @endif

    <!-- Workflow Tabs -->
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex space-x-6">
            <button 
                type="button"
                wire:click="$set('activeTab', 'conduct')"
                class="whitespace-nowrap pb-3 text-xs font-semibold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'conduct' ? 'border-[#00a3cc] text-[#00a3cc] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
            >
                Count
            </button>
            <button 
                type="button"
                wire:click="$set('activeTab', 'history')"
                class="whitespace-nowrap pb-3 text-xs font-semibold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'history' ? 'border-[#00a3cc] text-[#00a3cc] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
            >
                History
            </button>
        </nav>
    </div>

    @if ($activeTab === 'conduct')
        <!-- Live Audit KPIs & Variance Summary (Right-aligned numbers, bigger light font, neutral labels) -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Products Measured</p>
                <div class="mt-2 flex items-baseline justify-end gap-1">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-slate-900">{{ $statsTotalCounted }}</span>
                    <span class="tabular-nums text-xs text-slate-400">/ {{ $statsTotalProducts }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Exact Matches</p>
                <div class="mt-2 text-right">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-emerald-700">{{ $statsMatched }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Shortages (Dipstick Loss)</p>
                <div class="mt-2 text-right">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-rose-700">{{ $statsShortage }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Overages (Found Stock)</p>
                <div class="mt-2 text-right">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-blue-700">{{ $statsOverage }}</span>
                </div>
            </div>

            <div class="col-span-2 sm:col-span-1 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs flex flex-col justify-center">
                <button 
                    wire:click="promptConfirmation" 
                    type="button" 
                    class="w-full rounded bg-[#00a3cc] px-3 py-2.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition flex items-center justify-center gap-1.5"
                >
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Confirm Count
                </button>
            </div>
        </div>

        <!-- Main Workspace with Left Side Filter Panel & Live Count Table -->
        <div class="flex flex-col lg:flex-row gap-4 items-start">
            <!-- Left Filter & Configuration Panel -->
            <aside class="w-full lg:w-60 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3.5">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Audit Setup & Filters</span>
                </div>

                <!-- Date -->
                <div>
                    <label for="countedAt" class="block text-[11px] font-semibold text-slate-600 mb-1">Audit / Count Date</label>
                    <input wire:model="countedAt" id="countedAt" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" required />
                    <x-input-error :messages="$errors->get('countedAt')" class="mt-1" />
                </div>

                <!-- Notes -->
                <div>
                    <label for="notes" class="block text-[11px] font-semibold text-slate-600 mb-1">Audit Notes / Remarks</label>
                    <textarea 
                        wire:model="notes" 
                        id="notes" 
                        rows="2" 
                        placeholder="e.g. Weekly dipstick inventory..." 
                        class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                    ></textarea>
                </div>

                <!-- Batch Actions -->
                <div class="border-t border-slate-200 pt-2 space-y-1.5">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Quick Actions:</span>
                    <button 
                        wire:click="fillFromSystemStock" 
                        wire:confirm="Pre-fill all physical count fields with current system stock balances?"
                        type="button" 
                        class="w-full inline-flex items-center justify-center rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-xs hover:bg-slate-50 transition"
                    >
                        Pre-fill System Stock
                    </button>
                    <button 
                        wire:click="clearAllCounts" 
                        wire:confirm="Are you sure you want to clear all entered physical count inputs?"
                        type="button" 
                        class="w-full inline-flex items-center justify-center rounded border border-rose-300 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 shadow-xs hover:bg-rose-50 transition"
                    >
                        Reset All Counts
                    </button>
                </div>

                <!-- Filter Inputs -->
                <div class="border-t border-slate-200 pt-2 space-y-2.5">
                    <span class="block text-[10px] font-bold uppercase tracking-wider text-slate-500">Filters:</span>

                    <!-- Search -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Product / SKU</label>
                        <input 
                            wire:model.live.debounce.300ms="search" 
                            type="search" 
                            placeholder="Name or SKU..." 
                            class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                        />
                    </div>

                    <!-- Brand -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Brand</label>
                        <select wire:model.live="brandId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">All Brands</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Category -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Category</label>
                        <select wire:model.live="categoryId" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="">All Categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Discrepancy Status -->
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Status</label>
                        <select wire:model.live="discrepancyFilter" class="w-full rounded border border-slate-300 px-2 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                            <option value="all">All Products</option>
                            <option value="discrepancy">Discrepancies Only (±)</option>
                            <option value="counted">Counted Items Only</option>
                            <option value="uncounted">Uncounted Items (Empty)</option>
                        </select>
                    </div>
                </div>
            </aside>

            <!-- Table & Action Area -->
            <div class="flex-1 min-w-0 w-full space-y-4">
                <!-- Count Entry Grid Table -->
                <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                        <tr>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">Product Name</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Category</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-16">Unit</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">System Stock</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-32 bg-slate-200/60">Physical Stock</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Variance</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Status</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Dipstick / Adjustment Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($products as $product)
                            @php
                                $systemQty = (float) ($product->inventory?->quantity ?? 0);
                                $unit = $product->packageUnit?->abbreviation ?? 'pcs';
                                $val = $counts[$product->id] ?? '';
                                $hasCount = ($val !== '' && $val !== null);
                                $physicalQty = $hasCount ? (float) $val : null;
                                $variance = $hasCount ? round($physicalQty - $systemQty, 3) : null;
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors {{ $hasCount && $variance != 0 ? ($variance < 0 ? 'bg-rose-50/20' : 'bg-blue-50/20') : '' }}" wire:key="phys-prod-{{ $product->id }}">
                                <!-- Brand Column -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $product->brand?->name ?? '—' }}
                                </td>

                                <!-- Product Name Column (Fits long product names) -->
                                <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $product->name }}">
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

                                <!-- Package Unit -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                    {{ $unit }}
                                </td>

                                <!-- System Stock -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                    {{ number_format($systemQty, 3) }}
                                </td>

                                <!-- Physical Stock Input -->
                                <td class="border border-slate-200 px-2 py-1 bg-slate-50/50">
                                    <input 
                                        wire:model.live.debounce.300ms="counts.{{ $product->id }}" 
                                        type="number" 
                                        step="0.001" 
                                        min="0" 
                                        placeholder="Skip" 
                                        class="w-full rounded border-slate-300 py-1 px-2 text-xs tabular-nums font-bold focus:border-[#00a3cc] focus:ring-[#00a3cc] {{ $hasCount ? ($variance == 0 ? 'text-emerald-700 border-emerald-300' : ($variance < 0 ? 'text-rose-700 border-rose-300' : 'text-blue-700 border-blue-300')) : '' }}"
                                    />
                                </td>

                                <!-- Variance Display -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap">
                                    @if ($hasCount)
                                        <span class="{{ $variance > 0 ? 'text-blue-700' : ($variance < 0 ? 'text-rose-700' : 'text-emerald-700') }}">
                                             {{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 3) }}
                                        </span>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>

                                <!-- Status Badge -->
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if (!$hasCount)
                                        <span class="inline-block rounded border border-slate-300 text-slate-500 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Uncounted
                                        </span>
                                    @elseif ($variance == 0)
                                        <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Match
                                        </span>
                                    @elseif ($variance < 0)
                                        <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Shortage
                                        </span>
                                    @else
                                        <span class="inline-block rounded border border-blue-600 text-blue-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                                            Overage
                                        </span>
                                    @endif
                                </td>

                                <!-- Line Reason / Dipstick Note -->
                                <td class="border border-slate-200 px-2 py-1">
                                    <input 
                                        wire:model="lineReasons.{{ $product->id }}" 
                                        type="text" 
                                        placeholder="Optional line note (e.g. 0.5 gal dipstick remaining)" 
                                        class="w-full rounded border-slate-200 py-1 px-2 text-xs focus:border-[#00a3cc] focus:ring-[#00a3cc]"
                                    />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                    <div class="mx-auto flex flex-col items-center justify-center">
                                        <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p class="text-xs font-semibold text-slate-700">No products match your filter criteria.</p>
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

        <!-- Sticky Floating / Bottom Confirm Bar -->
        <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-xs text-slate-600">
                Ready to reconcile? <span class="tabular-nums font-bold text-slate-900">{{ $statsTotalCounted }}</span> items entered, with <span class="tabular-nums font-bold text-rose-700">{{ $statsShortage }} shortages</span> and <span class="tabular-nums font-bold text-blue-700">{{ $statsOverage }} overages</span>.
            </div>

            <div class="flex items-center gap-2">
                <button 
                    wire:click="promptConfirmation" 
                    type="button" 
                    class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition flex items-center gap-1.5"
                >
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Review & Confirm Audit
                </button>
            </div>
        </div>
            </div>
        </div>

        <!-- Discrepancy Review & Confirmation Modal -->
        @if ($showConfirmationModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
                <div class="w-full max-w-3xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                    <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div class="rounded border border-amber-300 bg-amber-50 p-1.5 text-amber-700">
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <h3 class="font-heading text-sm font-bold text-slate-900">
                                    Review & Confirm Physical Inventory Audit
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Date: <span class="tabular-nums font-semibold text-slate-800">{{ $countedAt }}</span> · Staff: <span class="font-semibold text-slate-800">{{ auth()->user()->name }}</span>
                                </p>
                            </div>
                        </div>
                        <button 
                            wire:click="cancelConfirmation" 
                            type="button" 
                            class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="p-4 space-y-3.5 max-h-[60vh] overflow-y-auto">
                        <div class="rounded border border-amber-300 bg-amber-50 p-2.5 text-xs text-amber-900">
                            <span class="font-bold">Important Notice:</span> Confirming this audit will permanently replace the system inventory balances of all entered items with physical counts. Any discrepancies will automatically generate <span class="font-semibold">`physical_adjustment`</span> audit movements.
                        </div>

                        <!-- Summary Cards -->
                        <div class="grid grid-cols-3 gap-2.5">
                            <div class="rounded border border-slate-300 bg-white p-2.5 text-center shadow-xs">
                                <span class="text-[10px] uppercase tracking-wider text-slate-500 font-semibold block">Total Measured</span>
                                <span class="tabular-nums text-sm font-bold text-slate-900">{{ $statsTotalCounted }} items</span>
                            </div>
                            <div class="rounded border border-rose-300 bg-rose-50/50 p-2.5 text-center shadow-xs">
                                <span class="text-[10px] uppercase tracking-wider text-rose-700 font-semibold block">Shortages (Dipstick Loss)</span>
                                <span class="tabular-nums text-sm font-bold text-rose-700">{{ $statsShortage }} items</span>
                            </div>
                            <div class="rounded border border-blue-300 bg-blue-50/50 p-2.5 text-center shadow-xs">
                                <span class="text-[10px] uppercase tracking-wider text-blue-700 font-semibold block">Overages (Additions)</span>
                                <span class="tabular-nums text-sm font-bold text-blue-700">{{ $statsOverage }} items</span>
                            </div>
                        </div>

                        <!-- Discrepancy Breakdown Table -->
                        @if (count($discrepantItems) > 0)
                            <div>
                                <h4 class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                    Discrepant Products ({{ count($discrepantItems) }})
                                </h4>
                                <div class="overflow-x-auto border border-slate-300">
                                    <table class="w-full border-collapse border border-slate-300 text-xs">
                                        <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                            <tr>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[180px]">Product Name</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">SKU</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-16">Unit</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">System</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Physical</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Variance</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Type</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-200 font-medium">
                                            @foreach ($discrepantItems as $disc)
                                                @php
                                                    $u = $disc['product']->packageUnit?->abbreviation ?? 'pcs';
                                                @endphp
                                                <tr class="hover:bg-slate-50">
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                                        {{ $disc['product']->brand?->name ?? '—' }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[180px] max-w-sm break-words whitespace-normal" title="{{ $disc['product']->name }}">
                                                        {{ $disc['product']->name }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                                        {{ $disc['product']->sku }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                                        {{ $u }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                                        {{ number_format($disc['system'], 3) }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                                        {{ number_format($disc['physical'], 3) }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $disc['variance'] < 0 ? 'text-rose-700' : 'text-blue-700' }}">
                                                        {{ $disc['variance'] > 0 ? '+' : '' }}{{ number_format($disc['variance'], 3) }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                                        @if ($disc['type'] === 'shortage')
                                                            <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                                Shortage
                                                            </span>
                                                        @else
                                                            <span class="inline-block rounded border border-blue-600 text-blue-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                                Overage
                                                            </span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-center text-xs font-medium text-emerald-800">
                                All entered physical counts match current system balances! No variance adjustments required.
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 flex justify-end gap-2">
                        <button 
                            wire:click="cancelConfirmation" 
                            type="button" 
                            class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                        >
                            Back to Editing
                        </button>
                        <button 
                            wire:click="confirmCount" 
                            type="button" 
                            class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
                        >
                            Confirm & Apply Adjustments
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @else
        <!-- Past Audit History Tab -->
        <div class="space-y-4">
            <div class="overflow-hidden border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                            <tr>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20">Audit ID</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Count Date</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Staff Responsible</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Items Counted</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Discrepancies</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Session Remarks</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse ($historyList as $audit)
                                @php
                                    $discrepancyCount = $audit->items->where('variance', '!=', 0)->count();
                                @endphp
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-500">#{{ $audit->id }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums font-semibold text-slate-900 whitespace-nowrap">{{ $audit->counted_at->format('M d, Y') }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">{{ $audit->user?->name ?? 'System' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                        {{ $audit->items->count() }} items
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        @if ($discrepancyCount > 0)
                                            <span class="inline-block rounded border border-rose-600 text-rose-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                {{ $discrepancyCount }} adjusted
                                            </span>
                                        @else
                                            <span class="inline-block rounded border border-emerald-600 text-emerald-700 bg-transparent px-1.5 py-0.5 text-[10px] font-bold uppercase">
                                                All Matched
                                            </span>
                                        @endif
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">{{ $audit->notes ?? '—' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right whitespace-nowrap">
                                        <button 
                                            wire:click="viewHistory({{ $audit->id }})" 
                                            type="button" 
                                            class="inline-flex items-center rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                                        >
                                            View Breakdown
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                        <div class="mx-auto flex flex-col items-center justify-center">
                                            <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                            </svg>
                                            <p class="text-xs font-semibold text-slate-700">No physical count audits recorded yet.</p>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($historyList && $historyList->hasPages())
                    <div class="border-t border-slate-300 px-3 py-2 bg-slate-50">
                        {{ $historyList->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- History Audit Breakdown Modal -->
    @if ($viewingHistoryId && $viewingCount)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-3xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            Physical Inventory Audit #{{ $viewingCount->id }}
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            Counted on <span class="tabular-nums font-semibold text-slate-800">{{ $viewingCount->counted_at->format('F d, Y') }}</span> by <span class="font-semibold text-slate-800">{{ $viewingCount->user?->name ?? 'System' }}</span>
                        </p>
                    </div>
                    <button 
                        wire:click="closeHistoryModal" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if ($viewingCount->notes)
                    <div class="border-b border-slate-200 bg-slate-50 px-3.5 py-2 text-xs text-slate-700">
                        <span class="font-bold">Remarks:</span> {{ $viewingCount->notes }}
                    </div>
                @endif

                <div class="max-h-96 overflow-y-auto p-3.5">
                    <div class="overflow-x-auto border border-slate-300">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                            <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                <tr>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[180px]">Product Name</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left w-16">Unit</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">System Qty</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Physical</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Variance</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left">Reason / Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @foreach ($viewingCount->items as $item)
                                    @php
                                        $var = (float) $item->variance;
                                        $unit = $item->product?->packageUnit?->abbreviation ?? '';
                                    @endphp
                                    <tr class="hover:bg-slate-50">
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">{{ $item->product?->brand?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[180px] max-w-sm break-words whitespace-normal" title="{{ $item->product?->name }}">{{ $item->product?->name ?? 'Product' }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">{{ $item->product?->sku ?? '—' }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">{{ $unit }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">{{ number_format((float) $item->system_quantity, 3) }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">{{ number_format((float) $item->physical_quantity, 3) }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $var < 0 ? 'text-rose-700' : ($var > 0 ? 'text-blue-700' : 'text-emerald-700') }}">
                                            {{ $var > 0 ? '+' : '' }}{{ number_format($var, 3) }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700">{{ $item->reason ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-3.5 py-2.5 flex justify-between items-center">
                    <div class="text-xs text-slate-600">
                        Total Items: <span class="tabular-nums font-bold text-slate-900">{{ $viewingCount->items->count() }}</span>
                    </div>
                    <button 
                        wire:click="closeHistoryModal" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
