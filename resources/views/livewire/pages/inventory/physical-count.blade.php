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
use App\Support\Currency;
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

    // Conduct Filters
    public string $search = '';
    public string $brandId = '';
    public string $categoryId = '';
    public string $packageUnitId = '';
    public string $discrepancyFilter = 'all'; // 'all', 'counted', 'discrepancy', 'uncounted'
    public int $perPage = 25;

    // History Filters
    public string $historySearch = '';
    public string $historyDateFrom = '';
    public string $historyDateTo = '';
    public int $historyPerPage = 15;

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
        $products = Product::query()->with('inventories')->get();
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

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedHistorySearch(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryDateTo(): void
    {
        $this->resetPage();
    }

    public function updatedHistoryPerPage(): void
    {
        $this->resetPage();
    }

    public function resetCountFilters(): void
    {
        $this->reset(['search', 'brandId', 'categoryId', 'packageUnitId', 'discrepancyFilter']);
        $this->resetPage();
    }

    public function resetHistoryFilters(): void
    {
        $this->reset(['historySearch', 'historyDateFrom', 'historyDateTo']);
        $this->resetPage();
    }

    public function fillFromSystemStock(): void
    {
        $products = Product::query()->with('inventories')->where('active', true)->get();
        foreach ($products as $p) {
            $this->counts[$p->id] = (string) (float) $p->inventories->sum('quantity');
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
            'counts.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ], [
            'counts.*.numeric' => 'Count must be a valid number.',
            'counts.*.min' => 'Count cannot be negative.',
            'counts.*.max' => 'Count cannot exceed 999,999.99.',
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
            'counts.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ], [
            'counts.*.numeric' => 'Count must be a valid number.',
            'counts.*.min' => 'Count cannot be negative.',
            'counts.*.max' => 'Count cannot exceed 999,999.99.',
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

                $product = Product::query()->with('inventories')->lockForUpdate()->find($productId);
                if (!$product) {
                    continue;
                }

                $systemQuantity = (float) $product->inventories->sum('quantity');
                $physical = (float) $physicalQuantity;
                $variance = round($physical - $systemQuantity, 2);
                $lineReason = $this->lineReasons[$productId] ?? null;

                $count->items()->create([
                    'product_id' => $product->id,
                    'system_quantity' => $systemQuantity,
                    'physical_quantity' => $physical,
                    'variance' => $variance,
                    'reason' => $lineReason ?: ($variance != 0 ? $this->notes : null),
                ]);

                $itemCount++;

                if ($product->requires_physical_check) {
                    $product->update(['requires_physical_check' => false]);
                }

                // If physical differs from system, update inventory and log movement
                if ($variance != 0) {
                    $storefrontInv = Inventory::firstOrCreate(['product_id' => $product->id, 'location' => 'storefront'], ['quantity' => 0]);
                    $storefrontInv->quantity += $variance;
                    $storefrontInv->save();
                    $adjustedCount++;

                    $reasonText = $lineReason ?: $this->notes;
                    if (empty($reasonText)) {
                        $reasonText = $variance < 0 ? 'Physical count shortage adjustment' : 'Physical count overage adjustment';
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
                        'reference_text' => 'Physical audit ' . $count->id . ' (' . ($variance < 0 ? 'Shortage' : 'Overage') . ')',
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
            ->with(['inventories', 'category', 'brand', 'packageUnit'])
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
                $sys = (float) $p->inventories->sum('quantity');
                $phys = (float) $val;
                $diff = round($phys - $sys, 2);
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
            ->with(['inventories', 'category', 'brand', 'packageUnit'])
            ->where('active', true)
            ->when($this->search !== '', fn($q) => $q->where(fn($sub) => $sub->where('name', 'like', '%' . trim($this->search) . '%')->orWhere('sku', 'like', '%' . trim($this->search) . '%')))
            ->when($this->brandId !== '', fn($q) => $q->where('brand_id', $this->brandId))
            ->when($this->categoryId !== '', fn($q) => $q->where('category_id', $this->categoryId))
            ->when($this->packageUnitId !== '', fn($q) => $q->where('package_unit_id', $this->packageUnitId));

        $statsNeedsCheck = $allProducts->where('requires_physical_check', true)->count();

        // In-memory filter for discrepancy status if selected
        if ($this->discrepancyFilter !== 'all') {
            $matchingIds = [];
            foreach ($allProducts as $p) {
                $val = $this->counts[$p->id] ?? '';
                $hasVal = $val !== '' && $val !== null;
                $sys = (float) $p->inventories->sum('quantity');
                $phys = $hasVal ? (float) $val : 0;
                $diff = round($phys - $sys, 2);

                if ($this->discrepancyFilter === 'needs_check') {
                    if ($p->requires_physical_check) {
                        $matchingIds[] = $p->id;
                    }
                } elseif ($this->discrepancyFilter === 'counted' && $hasVal) {
                    $matchingIds[] = $p->id;
                } elseif ($this->discrepancyFilter === 'discrepancy' && $hasVal && $diff != 0) {
                    $matchingIds[] = $p->id;
                } elseif ($this->discrepancyFilter === 'uncounted' && !$hasVal) {
                    $matchingIds[] = $p->id;
                }
            }
            $productsQuery->whereIn('id', $matchingIds);
        }

        $paginatedProducts = $productsQuery->orderBy('name')->paginate($this->perPage);

        // History query
        $historyList = null;
        $viewingCount = null;
        if ($this->activeTab === 'history') {
            $historyList = PhysicalInventory::with(['user', 'items.product.packageUnit', 'items.product.brand'])
                ->when($this->historySearch !== '', function ($q) {
                    $term = '%' . trim($this->historySearch) . '%';
                    $q->where(function ($sub) use ($term) {
                        $sub->where('notes', 'like', $term)->orWhere('id', 'like', $term)->orWhereHas('user', fn($u) => $u->where('name', 'like', $term));
                    });
                })
                ->when($this->historyDateFrom !== '', fn($q) => $q->whereDate('counted_at', '>=', $this->historyDateFrom))
                ->when($this->historyDateTo !== '', fn($q) => $q->whereDate('counted_at', '<=', $this->historyDateTo))
                ->latest('counted_at')
                ->latest('id')
                ->paginate($this->historyPerPage);
        }

        if ($this->viewingHistoryId) {
            $viewingCount = PhysicalInventory::with(['user', 'items.product.packageUnit', 'items.product.brand'])->find($this->viewingHistoryId);
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
            'statsNeedsCheck' => $statsNeedsCheck,
            'discrepantItems' => $discrepantItems,
            'historyList' => $historyList,
            'viewingCount' => $viewingCount,
        ]);
    }
}; ?>

<div x-data="{
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
}" @click.window="contextMenu.close()" @keydown.escape.window="contextMenu.close()"
    @scroll.window="contextMenu.close()" @resize.window="contextMenu.close()" class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-300 pb-3">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Physical Count</h1>
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
            <button type="button" wire:click="$set('activeTab', 'conduct')"
                class="whitespace-nowrap pb-3 text-xs font-semibold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'conduct' ? 'border-[#00a3cc] text-[#00a3cc] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                Count
            </button>
            <button type="button" wire:click="$set('activeTab', 'history')"
                class="whitespace-nowrap pb-3 text-xs font-semibold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'history' ? 'border-[#00a3cc] text-[#00a3cc] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}">
                History
            </button>
        </nav>
    </div>

    @if ($activeTab === 'conduct')
        <!-- Live Audit KPIs & Variance Summary -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Products Measured</p>
                <div class="mt-2 flex items-baseline justify-end gap-1">
                    <span
                        class="tabular-nums text-3xl sm:text-4xl font-light text-slate-900">{{ $statsTotalCounted }}</span>
                    <span class="tabular-nums text-xs text-slate-400">/ {{ $statsTotalProducts }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs cursor-pointer hover:bg-slate-50 transition"
                wire:click="$set('discrepancyFilter', 'needs_check')"
                title="Filter by products marked for physical verification">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Check Queue (Mix)</p>
                <div class="mt-2 text-right">
                    <span
                        class="tabular-nums text-3xl sm:text-4xl font-light {{ $statsNeedsCheck > 0 ? 'text-amber-700 font-normal' : 'text-slate-900' }}">{{ $statsNeedsCheck }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Exact Matches</p>
                <div class="mt-2 text-right">
                    <span
                        class="tabular-nums text-3xl sm:text-4xl font-light text-emerald-700">{{ $statsMatched }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Shortages</p>
                <div class="mt-2 text-right">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-rose-700">{{ $statsShortage }}</span>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs">
                <p class="text-xs font-normal uppercase tracking-wider text-slate-500">Overages</p>
                <div class="mt-2 text-right">
                    <span class="tabular-nums text-3xl sm:text-4xl font-light text-blue-700">{{ $statsOverage }}</span>
                </div>
            </div>
        </div>

        @if ($statsNeedsCheck > 0 && $discrepancyFilter !== 'needs_check')
            <div
                class="rounded-lg border border-amber-300 bg-amber-50/70 p-3 flex items-center justify-between text-xs text-amber-900">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span><strong>{{ $statsNeedsCheck }}</strong> storefront material product(s) were used in paint
                        mixing and require physical inventory verification.</span>
                </div>
                <button wire:click="$set('discrepancyFilter', 'needs_check')" type="button"
                    class="underline font-semibold hover:text-amber-950 whitespace-nowrap">
                    View Check Queue &rarr;
                </button>
            </div>
        @endif

        <!-- Streamlined Horizontal Audit Setup & Quick Actions Bar -->
        <div class="rounded-lg border border-slate-300 bg-white p-3 shadow-xs">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3 flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <label for="countedAt" class="text-xs font-semibold text-slate-700 whitespace-nowrap">Audit
                            Date:</label>
                        <input wire:model="countedAt" id="countedAt" type="date"
                            class="rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                            required />
                    </div>
                    <div class="flex items-center gap-2 flex-1 min-w-[200px]">
                        <label for="notes"
                            class="text-xs font-semibold text-slate-700 whitespace-nowrap">Remarks:</label>
                        <input wire:model="notes" id="notes" type="text"
                            placeholder="e.g. Weekly dipstick inventory audit..."
                            class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="fillFromSystemStock"
                        wire:confirm="Pre-fill all physical count fields with current system stock balances?"
                        type="button"
                        class="inline-flex items-center rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                        title="Copy current system stock balances to physical count inputs">
                        <svg class="mr-1 h-3.5 w-3.5 text-slate-500" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2" />
                        </svg>
                        Pre-fill System Stock
                    </button>
                    <button wire:click="clearAllCounts"
                        wire:confirm="Are you sure you want to clear all entered physical count inputs?" type="button"
                        class="inline-flex items-center rounded border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50 shadow-xs transition">
                        Reset Counts
                    </button>
                </div>
            </div>
        </div>

        <!-- Main Count Table Section with Standardized Space-y-2 Spacing -->
        <div class="space-y-2">
            <!-- Filter Controls Outside the Table Div (Aligned left, h-9 controls) -->
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="w-64">
                    <input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="Search SKU or product name..."
                        class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <div class="w-40">
                    <select wire:model.live="brandId"
                        class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Brands</option>
                        @foreach ($brands as $brand)
                            <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="w-40">
                    <select wire:model.live="categoryId"
                        class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="">All Categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="w-44">
                    <select wire:model.live="discrepancyFilter"
                        class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="all">All Products</option>
                        <option value="needs_check">Due Check</option>
                        <option value="discrepancy">Discrepancies</option>
                        <option value="counted">Counted Items</option>
                        <option value="uncounted">Uncounted Items</option>
                    </select>
                </div>

                @if ($search !== '' || $brandId !== '' || $categoryId !== '' || $discrepancyFilter !== 'all')
                    <button wire:click="resetCountFilters" type="button"
                        class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                        Reset
                    </button>
                @endif
            </div>

            <!-- Showing X of N Items Indicator & Customizable Amount Per Page -->
            <div class="flex items-center justify-between text-xs text-slate-500">
                <div>
                    @if ($products->total() > 0)
                        Showing <span class="font-medium text-slate-700">{{ $products->firstItem() }}</span> to
                        <span class="font-medium text-slate-700">{{ $products->lastItem() }}</span> of <span
                            class="font-medium text-slate-700">{{ $products->total() }}</span> items
                    @else
                        Showing <span class="font-medium text-slate-700">0</span> items
                    @endif
                </div>
                <div class="flex items-center gap-1.5">
                    <label for="countPerPage" class="text-xs text-slate-500">Show:</label>
                    <select wire:model.live="perPage" id="countPerPage"
                        class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                    <span class="text-xs text-slate-500">items</span>
                </div>
            </div>

            <!-- Table Container (div contains ONLY table and bottom pagination) -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead
                        class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">SKU</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Brand</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[200px]">
                                Product Name</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Unit
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Category
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-20">Status
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">System
                                Stock</th>
                            <th scope="col"
                                class="border border-slate-300 px-2.5 py-1.5 text-center w-28 bg-slate-200/50">Physical
                                Count</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-20">Variance
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Price
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($products as $product)
                            @php
                                $systemQty = (float) $product->inventories->sum('quantity');
                                $unit = $product->packageUnit?->abbreviation ?? 'pcs';
                                $val = $counts[$product->id] ?? '';
                                $hasCount = $val !== '' && $val !== null;
                                $physicalQty = $hasCount ? (float) $val : null;
                                $variance = $hasCount ? round($physicalQty - $systemQty, 2) : null;
                            @endphp
                            <tr class="hover:bg-slate-50 transition-colors" wire:key="phys-prod-{{ $product->id }}">
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap font-mono">
                                    {{ $product->sku }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $product->brand?->name ?? '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal"
                                    title="{{ $product->name }}">
                                    {{ $product->name }}
                                </td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                    {{ $unit }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 whitespace-nowrap">
                                    {{ $product->category?->name ?? '—' }}
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($product->requires_physical_check)
                                        <span class="text-[10px] font-bold uppercase text-amber-700 tracking-wider"
                                            title="Used in mixing; requires physical count">Check Due</span>
                                    @elseif (!$hasCount)
                                        <span class="text-[10px] font-bold uppercase text-slate-400">Uncounted</span>
                                    @elseif ($variance == 0)
                                        <span class="text-[10px] font-bold uppercase text-emerald-700">Match</span>
                                    @elseif ($variance < 0)
                                        <span class="text-[10px] font-bold uppercase text-rose-700">Shortage</span>
                                    @else
                                        <span class="text-[10px] font-bold uppercase text-blue-700">Overage</span>
                                    @endif
                                </td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                    {{ number_format($systemQty, 2) }}
                                </td>
                                <td class="border border-slate-200 px-2 py-1 bg-slate-50/40 text-center">
                                    <input wire:model.live.debounce.300ms="counts.{{ $product->id }}" type="number"
                                        step="0.01" min="0" max="999999.99" placeholder="Skip"
                                        inputmode="decimal"
                                        x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                        class="w-24 text-center rounded border border-slate-300 py-1 px-2 text-xs tabular-nums font-bold focus:border-[#00a3cc] focus:ring-[#00a3cc] {{ $hasCount ? ($variance == 0 ? 'text-emerald-700 border-emerald-300' : ($variance < 0 ? 'text-rose-700 border-rose-300' : 'text-blue-700 border-blue-300')) : '' }}" />
                                    @error('counts.' . $product->id)
                                        <p class="text-rose-600 text-[10px] mt-0.5">{{ $message }}</p>
                                    @enderror
                                </td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap">
                                    @if ($hasCount)
                                        <span
                                            class="{{ $variance > 0 ? 'text-blue-700' : ($variance < 0 ? 'text-rose-700' : 'text-emerald-700') }}">
                                            {{ $variance > 0 ? '+' : '' }}{{ number_format($variance, 2) }}
                                        </span>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-right font-semibold tabular-nums text-slate-900 whitespace-nowrap">
                                    {{ Currency::format($product->selling_price) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10"
                                    class="border border-slate-200 px-4 py-8 text-center text-slate-500">
                                    <div class="mx-auto flex flex-col items-center justify-center">
                                        <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p class="text-xs font-semibold text-slate-700">No products match your filter
                                            criteria.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($products->hasPages())
                <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                    <div>
                        <span class="text-slate-500">
                            Page <span class="font-medium text-slate-800">{{ $products->currentPage() }}</span> of
                            <span class="font-medium text-slate-800">{{ $products->lastPage() }}</span>
                        </span>
                    </div>
                    <div class="inline-flex items-center gap-1">
                        <button wire:click="previousPage" type="button" @disabled($products->onFirstPage())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Prev
                        </button>

                        @php
                            $currentPage = $products->currentPage();
                            $lastPage = $products->lastPage();
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($lastPage, $currentPage + 2);
                        @endphp

                        @if ($startPage > 1)
                            <button wire:click="gotoPage(1)" type="button"
                                class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                1
                            </button>
                            @if ($startPage > 2)
                                <span class="px-1 text-slate-400">...</span>
                            @endif
                        @endif

                        @for ($page = $startPage; $page <= $endPage; $page++)
                            @if ($page === $currentPage)
                                <span
                                    class="min-w-[28px] h-7 px-2 text-xs font-semibold rounded bg-slate-900 text-white flex items-center justify-center">
                                    {{ $page }}
                                </span>
                            @else
                                <button wire:click="gotoPage({{ $page }})" type="button"
                                    class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                    {{ $page }}
                                </button>
                            @endif
                        @endfor

                        @if ($endPage < $lastPage)
                            @if ($endPage < $lastPage - 1)
                                <span class="px-1 text-slate-400">...</span>
                            @endif
                            <button wire:click="gotoPage({{ $lastPage }})" type="button"
                                class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                {{ $lastPage }}
                            </button>
                        @endif

                        <button wire:click="nextPage" type="button" @disabled(!$products->hasMorePages())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Next
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>

        <!-- Sticky Floating / Bottom Confirm Bar -->
        <div
            class="rounded-lg shadow-xs flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="text-xs text-slate-600">
               
            </div>

            <div class="flex items-center gap-2">
                <button wire:click="promptConfirmation" type="button"
                    class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    Review & Confirm Audit
                </button>
            </div>
        </div>

        <!-- Discrepancy Review & Confirmation Modal -->
        @if ($showConfirmationModal)
            <div
                class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
                <div
                    class="w-full max-w-3xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden text-xs">
                    <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <div>
                                <h3 class="font-heading text-sm font-bold text-slate-900">
                                    Review & Confirm Physical Inventory Audit
                                </h3>
                            </div>
                        </div>
                        <button wire:click="cancelConfirmation" type="button"
                            class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>

                    <div class="p-4 space-y-3.5 max-h-[60vh] overflow-y-auto">
                        <div class="rounded p-2.5 text-xs ">
                            <span class="font-bold">Important Notice:</span> Confirming this audit will update the
                            system inventory balances of all entered items with physical counts. Any discrepancies will
                            automatically generate physical adjustment movement records.
                        </div>

                        <!-- Discrepancy Breakdown Table -->
                        @if (count($discrepantItems) > 0)
                            <div>
                                <h4 class="text-[11px] font-bold uppercase tracking-wider text-slate-700 mb-1.5">
                                    Discrepant Products ({{ count($discrepantItems) }})
                                </h4>
                                <div class="overflow-x-auto border border-slate-300">
                                    <table class="w-full border-collapse border border-slate-300 text-xs">
                                        <thead
                                            class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                                            <tr>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">SKU
                                                </th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand
                                                </th>
                                                <th
                                                    class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[180px]">
                                                    Product Name</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Unit
                                                </th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">
                                                    System</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">
                                                    Physical</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">
                                                    Variance</th>
                                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-24">Type
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-200">
                                            @foreach ($discrepantItems as $disc)
                                                @php
                                                    $u = $disc['product']->packageUnit?->abbreviation ?? 'pcs';
                                                @endphp
                                                <tr class="hover:bg-slate-50">
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap font-mono">
                                                        {{ $disc['product']->sku }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                                        {{ $disc['product']->brand?->name ?? '—' }}
                                                    </td>
                                                    <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[180px] max-w-sm break-words whitespace-normal"
                                                        title="{{ $disc['product']->name }}">
                                                        {{ $disc['product']->name }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                                        {{ $u }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                                        {{ number_format($disc['system'], 2) }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                                        {{ number_format($disc['physical'], 2) }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $disc['variance'] < 0 ? 'text-rose-700' : 'text-blue-700' }}">
                                                        {{ $disc['variance'] > 0 ? '+' : '' }}{{ number_format($disc['variance'], 2) }}
                                                    </td>
                                                    <td
                                                        class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                                        @if ($disc['type'] === 'shortage')
                                                            <span
                                                                class="text-[10px] font-bold uppercase text-rose-700">Shortage</span>
                                                        @else
                                                            <span
                                                                class="text-[10px] font-bold uppercase text-blue-700">Overage</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        @else
                            <div
                                class="rounded  p-3 text-center text-xs font-medium text-emerald-800">
                                All entered physical counts match current system balances.
                            </div>
                        @endif
                    </div>

                    <div class="border-t border-slate-200 bg-slate-50 px-4 py-2.5 flex justify-end gap-2">
                        <button wire:click="cancelConfirmation" type="button"
                            class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                            Back to Editing
                        </button>
                        <button wire:click="confirmCount" type="button"
                            class="rounded bg-[#00a3cc] px-4 py-1.5 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition">
                            Confirm & Apply Adjustments
                        </button>
                    </div>
                </div>
            </div>
        @endif
    @endif
    @if ($activeTab === 'history')
        <!-- Past Audit History Tab with Standardized Space-y-2 Spacing -->
        <div class="space-y-2">
            <!-- Filter Controls Outside the Table Div (Aligned left, h-9 controls) -->
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="w-64">
                    <input wire:model.live.debounce.300ms="historySearch" type="search"
                        placeholder="Search ID, staff, remarks..."
                        class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">From:</span>
                    <input wire:model.live="historyDateFrom" type="date" title="From Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">To:</span>
                    <input wire:model.live="historyDateTo" type="date" title="To Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                @if ($historySearch !== '' || $historyDateFrom !== '' || $historyDateTo !== '')
                    <button wire:click="resetHistoryFilters" type="button"
                        class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                        Reset
                    </button>
                @endif
            </div>

            <!-- Showing X of N Items Indicator & Customizable Amount Per Page -->
            <div class="flex items-center justify-between text-xs text-slate-500">
                <div>
                    @if ($historyList && $historyList->total() > 0)
                        Showing <span class="font-medium text-slate-700">{{ $historyList->firstItem() }}</span> to
                        <span class="font-medium text-slate-700">{{ $historyList->lastItem() }}</span> of <span
                            class="font-medium text-slate-700">{{ $historyList->total() }}</span> items
                    @else
                        Showing <span class="font-medium text-slate-700">0</span> items
                    @endif
                </div>
                <div class="flex items-center gap-1.5">
                    <label for="countHistoryPerPage" class="text-xs text-slate-500">Show:</label>
                    <select wire:model.live="historyPerPage" id="countHistoryPerPage"
                        class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span class="text-xs text-slate-500">items</span>
                </div>
            </div>

            <!-- History Table -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                    <thead
                        class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                        <tr>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-20">Audit ID
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Count Date
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Staff
                                Responsible</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">Items
                                Counted</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-28">
                                Discrepancies</th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Session Remarks
                            </th>
                            <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200">
                        @forelse ($historyList as $audit)
                            @php
                                $discrepancyCount = $audit->items->where('variance', '!=', 0)->count();
                            @endphp
                            <tr @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $audit->id }} })"
                                class="hover:bg-slate-50 transition-colors cursor-default"
                                wire:key="audit-row-{{ $audit->id }}">
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-700 font-mono text-center">
                                    {{ $audit->id }}</td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 tabular-nums font-semibold text-slate-900 whitespace-nowrap">
                                    {{ $audit->counted_at->format('M d, Y') }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                    {{ $audit->user?->name ?? 'System' }}</td>
                                <td
                                    class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">
                                    {{ $audit->items->count() }} items
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    @if ($discrepancyCount > 0)
                                        <span class="text-[10px] font-bold uppercase text-rose-700">
                                            {{ $discrepancyCount }} adjusted
                                        </span>
                                    @else
                                        <span class="text-[10px] font-bold uppercase text-emerald-700">
                                            All Matched
                                        </span>
                                    @endif
                                </td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600">
                                    {{ $audit->notes ?? '—' }}</td>
                                <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                    <button
                                        @click.stop="contextMenu.openFromButton($event, { id: {{ $audit->id }} })"
                                        type="button"
                                        class="inline-flex items-center justify-center h-6 w-6 rounded hover:bg-slate-100 text-slate-500 hover:text-slate-800 transition"
                                        title="Options">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                                        </svg>
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7"
                                    class="border border-slate-200 px-6 py-12 text-center text-slate-500">
                                    <div class="mx-auto flex flex-col items-center justify-center">
                                        <svg class="h-8 w-8 text-slate-300 mb-2" fill="none" viewBox="0 0 24 24"
                                            stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                        </svg>
                                        <p class="text-xs font-semibold text-slate-700">No physical count audits
                                            recorded yet.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($historyList && $historyList->hasPages())
                <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                    <div>
                        <span class="text-slate-500">
                            Page <span class="font-medium text-slate-800">{{ $historyList->currentPage() }}</span> of
                            <span class="font-medium text-slate-800">{{ $historyList->lastPage() }}</span>
                        </span>
                    </div>
                    <div class="inline-flex items-center gap-1">
                        <button wire:click="previousPage" type="button" @disabled($historyList->onFirstPage())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Prev
                        </button>

                        @php
                            $currentPage = $historyList->currentPage();
                            $lastPage = $historyList->lastPage();
                            $startPage = max(1, $currentPage - 2);
                            $endPage = min($lastPage, $currentPage + 2);
                        @endphp

                        @if ($startPage > 1)
                            <button wire:click="gotoPage(1)" type="button"
                                class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                1
                            </button>
                            @if ($startPage > 2)
                                <span class="px-1 text-slate-400">...</span>
                            @endif
                        @endif

                        @for ($page = $startPage; $page <= $endPage; $page++)
                            @if ($page === $currentPage)
                                <span
                                    class="min-w-[28px] h-7 px-2 text-xs font-semibold rounded bg-slate-900 text-white flex items-center justify-center">
                                    {{ $page }}
                                </span>
                            @else
                                <button wire:click="gotoPage({{ $page }})" type="button"
                                    class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                    {{ $page }}
                                </button>
                            @endif
                        @endfor

                        @if ($endPage < $lastPage)
                            @if ($endPage < $lastPage - 1)
                                <span class="px-1 text-slate-400">...</span>
                            @endif
                            <button wire:click="gotoPage({{ $lastPage }})" type="button"
                                class="min-w-[28px] h-7 px-2 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 transition">
                                {{ $lastPage }}
                            </button>
                        @endif

                        <button wire:click="nextPage" type="button" @disabled(!$historyList->hasMorePages())
                            class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                            Next
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    <!-- History Audit Breakdown Modal -->
    @if ($viewingHistoryId && $viewingCount)
        <div
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
            <div
                class="w-full max-w-3xl rounded-lg bg-white shadow-2xl border border-slate-300 overflow-hidden text-xs">
                <div class="border-b border-slate-200 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold text-slate-900">
                            Physical Inventory Audit {{ $viewingCount->id }}
                        </h3>
                      
                    </div>
                    <button wire:click="closeHistoryModal" type="button"
                        class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M6 18L18 6M6 6l12 12" />
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
                            <thead
                                class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider border-b border-slate-300">
                                <tr>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">SKU</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-left min-w-[180px]">Product
                                        Name</th>
                                    <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Unit</th>
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
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 tabular-nums text-slate-600 whitespace-nowrap font-mono">
                                            {{ $item->product?->sku ?? '—' }}</td>
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                            {{ $item->product?->brand?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 min-w-[180px] max-w-sm break-words whitespace-normal"
                                            title="{{ $item->product?->name }}">
                                            {{ $item->product?->name ?? 'Product' }}</td>
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                            {{ $unit }}</td>
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-600 whitespace-nowrap">
                                            {{ number_format((float) $item->system_quantity, 2) }}</td>
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                            {{ number_format((float) $item->physical_quantity, 2) }}</td>
                                        <td
                                            class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold whitespace-nowrap {{ $var < 0 ? 'text-rose-700' : ($var > 0 ? 'text-blue-700' : 'text-emerald-700') }}">
                                            {{ $var > 0 ? '+' : '' }}{{ number_format($var, 2) }}
                                        </td>
                                        <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700">
                                            {{ $item->reason ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="border-t border-slate-200 bg-slate-50 px-3.5 py-2.5 flex justify-between items-center">
                    <div class="text-xs text-slate-600">
                        Total Items: <span
                            class="tabular-nums font-bold text-slate-900">{{ $viewingCount->items->count() }}</span>
                    </div>
                    <button wire:click="closeHistoryModal" type="button"
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition">
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Global Floating Context Menu -->
    <div x-ref="floatingMenu" x-show="contextMenu.open" x-cloak x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        :style="`position: fixed; left: ${contextMenu.x}px; top: ${contextMenu.y}px; z-index: 9999;`"
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;">
        <button @click="if (contextMenu.item) { $wire.viewHistory(contextMenu.item.id); contextMenu.close(); }"
            type="button"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            <span>View Breakdown</span>
        </button>
        <button
            @click="if (contextMenu.item) { navigator.clipboard.writeText(contextMenu.item.id); contextMenu.close(); }"
            type="button"
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors">
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3" />
            </svg>
            <span>Copy Audit ID</span>
        </button>
    </div>
</div>
