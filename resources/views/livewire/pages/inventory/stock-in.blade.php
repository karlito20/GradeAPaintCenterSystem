<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockIn;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $activeTab = 'receive'; // 'receive' or 'history'

    // Form inputs
    public string $receivedAt = '';
    public string $reference = '';
    public string $notes = '';
    public string $productId = '';
    public string $quantity = '';
    public string $unitCost = '';

    // Search helper for product selector
    public string $productSearch = '';

    // Catalog modal picker
    public bool $showProductPickerModal = false;
    public string $pickerSearch = '';
    public string $pickerBrandId = '';
    public string $pickerCategoryId = '';

    // Staged items
    public array $items = [];

    // History filters & view modal
    public string $historySearch = '';
    public string $historyDateFrom = '';
    public string $historyDateTo = '';
    public int $historyPerPage = 15;
    public ?int $viewingStockInId = null;

    public function mount(): void
    {
        $this->receivedAt = now()->toDateString();
    }

    public function selectProduct(int $id): void
    {
        $this->productId = (string) $id;
        $this->productSearch = '';
    }

    public function openPickerModal(): void
    {
        $this->showProductPickerModal = true;
        $this->pickerSearch = '';
        $this->pickerBrandId = '';
        $this->pickerCategoryId = '';
    }

    public function closePickerModal(): void
    {
        $this->showProductPickerModal = false;
    }

    public function selectFromPicker(int $id): void
    {
        $this->productId = (string) $id;
        $this->productSearch = '';
        $this->showProductPickerModal = false;
    }

    public function addItem(): void
    {
        $validated = $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'unitCost' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ], [
            'productId.required' => 'Please select a product.',
            'quantity.required' => 'Quantity is required.',
            'quantity.numeric' => 'Quantity must be a valid number.',
            'quantity.gt' => 'Quantity must be greater than zero.',
            'quantity.max' => 'Quantity cannot exceed 999,999.99.',
            'unitCost.numeric' => 'Unit cost must be a valid number.',
            'unitCost.min' => 'Unit cost cannot be negative.',
            'unitCost.max' => 'Unit cost cannot exceed 999,999.99.',
        ]);

        $prodId = (int) $validated['productId'];
        $qty = (float) $validated['quantity'];
        $cost = ! empty($this->unitCost) ? (float) $this->unitCost : 0.00;

        // Check if product is already in the list; if so, aggregate quantity
        $existingIndex = null;
        foreach ($this->items as $idx => $item) {
            if ($item['product_id'] === $prodId) {
                $existingIndex = $idx;
                break;
            }
        }

        if ($existingIndex !== null) {
            $this->items[$existingIndex]['quantity'] += $qty;
            if ($cost > 0) {
                $this->items[$existingIndex]['unit_cost'] = $cost;
            }
        } else {
            $this->items[] = [
                'product_id' => $prodId,
                'quantity' => $qty,
                'unit_cost' => $cost,
            ];
        }

        $this->reset(['productId', 'quantity', 'unitCost', 'productSearch']);
        $this->resetValidation();
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
    }

    public function clearItems(): void
    {
        $this->items = [];
    }

    public function saveStockIn(): void
    {
        $this->validate([
            'receivedAt' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.99'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ], [
            'reference.required' => 'The DR number is required.',
            'items.min' => 'Add at least one product item to stock in.',
            'items.*.quantity.gt' => 'Quantity must be greater than zero.',
            'items.*.quantity.max' => 'Quantity cannot exceed 999,999.99.',
        ]);

        DB::transaction(function (): void {
            $stockIn = StockIn::create([
                'user_id' => auth()->id(),
                'received_at' => $this->receivedAt,
                'reference' => trim($this->reference) !== '' ? trim($this->reference) : null,
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ]);

            $totalQuantity = 0;
            $inventoryService = app(\App\Services\InventoryService::class);

            foreach ($this->items as $item) {
                $qty = (float) $item['quantity'];
                $totalQuantity += $qty;
                $cost = isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : 0.00;

                $product = Product::with('category')->find($item['product_id']);
                $isPaint = $product && $product->category && stripos($product->category->name, 'paint') !== false;

                // Inventory updates via Service
                $inventory = Inventory::query()
                    ->where('product_id', $item['product_id'])
                    ->where('location', 'storage')
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $item['product_id'], 'location' => 'storage'], ['quantity' => 0]);

                $before = (float) $inventory->quantity;
                $after = $before + $qty;

                $inventory->quantity = $after;
                $inventory->save();

                $stockIn->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $qty,
                    'unit_cost' => $cost > 0 ? $cost : 0.00,
                ]);

                $refText = 'Stock-in '.($stockIn->reference ? $stockIn->reference : $stockIn->id);
                if (! empty($stockIn->notes)) {
                    $refText .= ' ('.Str::limit($stockIn->notes, 30).')';
                }

                InventoryMovement::create([
                    'product_id' => $item['product_id'],
                    'location' => 'storage',
                    'user_id' => auth()->id(),
                    'type' => 'stock_in',
                    'quantity_change' => $qty,
                    'quantity_before' => $before,
                    'quantity_after' => $after,
                    'reference_type' => StockIn::class,
                    'reference_id' => $stockIn->id,
                    'reference_text' => $refText,
                ]);
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'event' => 'stock_in_created',
                'auditable_type' => StockIn::class,
                'auditable_id' => $stockIn->id,
                'context' => [
                    'received_at' => $this->receivedAt,
                    'reference' => $stockIn->reference,
                    'items_count' => count($this->items),
                    'total_quantity' => $totalQuantity,
                    'notes' => $this->notes,
                ],
            ]);
        });

        $this->reset(['productId', 'quantity', 'unitCost', 'items', 'reference', 'notes', 'productSearch']);
        $this->receivedAt = now()->toDateString();
        $this->resetValidation();

        session()->flash('status', 'Stock-in recorded successfully and inventory quantities updated.');
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Stock-in batch saved and inventory updated.',
        ]);
    }

    public function viewStockIn(int $id): void
    {
        $this->viewingStockInId = $id;
    }

    public function closeViewStockIn(): void
    {
        $this->viewingStockInId = null;
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

    public function resetHistoryFilters(): void
    {
        $this->historySearch = '';
        $this->historyDateFrom = '';
        $this->historyDateTo = '';
        $this->resetPage();
    }

    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'packageUnit', 'inventories', 'category'])
            ->where('active', true)
            ->orderBy('name')
            ->get();

        $selectedProduct = $this->productId ? $products->firstWhere('id', (int) $this->productId) : null;

        // Filtered products for quick search dropdown
        $searchResults = collect();
        if (trim($this->productSearch) !== '') {
            $term = trim($this->productSearch);
            $searchResults = $products->filter(function ($p) use ($term) {
                return str_contains(strtolower($p->name), strtolower($term)) ||
                    str_contains(strtolower($p->sku), strtolower($term)) ||
                    str_contains(strtolower($p->brand?->name ?? ''), strtolower($term));
            })->take(10);
        }

        // Browse modal products query
        $pickerProducts = collect();
        if ($this->showProductPickerModal) {
            $pickerProducts = Product::query()
                ->with(['brand', 'packageUnit', 'inventories', 'category'])
                ->where('active', true)
                ->when($this->pickerSearch !== '', function ($q) {
                    $t = '%'.trim($this->pickerSearch).'%';
                    $q->where(function ($sub) use ($t) {
                        $sub->where('name', 'like', $t)
                            ->orWhere('sku', 'like', $t);
                    });
                })
                ->when($this->pickerBrandId !== '', fn ($q) => $q->where('brand_id', $this->pickerBrandId))
                ->when($this->pickerCategoryId !== '', fn ($q) => $q->where('category_id', $this->pickerCategoryId))
                ->orderBy('name')
                ->limit(60)
                ->get();
        }

        // Stock In History query
        $historyQuery = StockIn::query()
            ->with(['user', 'items.product.packageUnit', 'items.product.brand'])
            ->when($this->historySearch !== '', function ($q) {
                $term = '%'.trim($this->historySearch).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('notes', 'like', $term)
                        ->orWhere('reference', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term));
                });
            })
            ->when($this->historyDateFrom !== '', fn ($q) => $q->whereDate('received_at', '>=', $this->historyDateFrom))
            ->when($this->historyDateTo !== '', fn ($q) => $q->whereDate('received_at', '<=', $this->historyDateTo))
            ->latest('received_at')
            ->latest('id');

        $stockInHistory = $this->activeTab === 'history' ? $historyQuery->paginate($this->historyPerPage) : null;
        $viewingStockIn = $this->viewingStockInId ? StockIn::with(['user', 'items.product.packageUnit', 'items.product.brand'])->find($this->viewingStockInId) : null;

        return view('livewire.pages.inventory.stock-in', [
            'products' => $products,
            'selectedProduct' => $selectedProduct,
            'searchResults' => $searchResults,
            'pickerProducts' => $pickerProducts,
            'brands' => Brand::where('active', true)->orderBy('name')->get(),
            'categories' => Category::where('active', true)->orderBy('name')->get(),
            'stockInHistory' => $stockInHistory,
            'viewingStockIn' => $viewingStockIn,
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
    class="space-y-4 w-full min-w-0"
>
    <!-- Header (No Quick Shortcuts) -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-2xl sm:text-3xl font-bold tracking-tight text-slate-900">Stock In</h1>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded border border-emerald-300 bg-emerald-50/50 p-2.5 text-xs font-medium text-emerald-800 shadow-xs">
            {{ session('status') }}
        </div>
    @endif

    <!-- Workflow Tabs -->
    <div class="border-b border-slate-300">
        <nav class="-mb-px flex space-x-6">
            <button 
                type="button"
                wire:click="$set('activeTab', 'receive')"
                class="whitespace-nowrap pb-2.5 text-xs font-bold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'receive' ? 'border-[#00a3cc] text-[#008fb3] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
            >
                Receive Stock
                @if (count($items) > 0)
                    <span class="ml-1.5 rounded bg-[#00a3cc] text-white px-1.5 py-0.5 text-[10px] tabular-nums font-bold">
                        {{ count($items) }}
                    </span>
                @endif
            </button>
            <button 
                type="button"
                wire:click="$set('activeTab', 'history')"
                class="whitespace-nowrap pb-2.5 text-xs font-bold uppercase tracking-wider border-b-2 transition {{ $activeTab === 'history' ? 'border-[#00a3cc] text-[#008fb3] font-heading' : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-700' }}"
            >
                History
            </button>
        </nav>
    </div>

    @if ($activeTab === 'receive')
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[380px_minmax(0,1fr)]">
            <!-- Left Column: Receiving Batch Details & Line Entry Form -->
            <div class="space-y-4">
                <!-- Batch Info Card (with Reference) -->
                <div class="rounded-lg border border-slate-300 bg-white p-4 shadow-xs space-y-3">
                    <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800 border-b border-slate-200 pb-2">
                        1. Batch Info &amp; Reference
                    </h2>

                    <div>
                        <label for="receivedAt" class="text-xs font-semibold text-slate-700 uppercase tracking-wider block">
                            Received Date <span class="text-rose-500">*</span>
                        </label>
                        <x-text-input 
                            wire:model="receivedAt" 
                            id="receivedAt" 
                            type="date" 
                            class="mt-1 block w-full rounded border-slate-300 text-xs tabular-nums focus:border-slate-500 focus:ring-0" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('receivedAt')" class="mt-1" />
                    </div>

                    <div>
                        <label for="reference" class="text-xs font-semibold text-slate-700 uppercase tracking-wider block">
                            DR Number <span class="text-rose-500">*</span>
                        </label>
                        <x-text-input 
                            wire:model="reference" 
                            id="reference" 
                            type="text" 
                            class="mt-1 block w-full rounded border-slate-300 text-xs text-slate-900 focus:border-slate-500 focus:ring-0" 
                            placeholder="e.g. DR-10492"
                            required
                        />
                        <x-input-error :messages="$errors->get('reference')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="notes" value="Notes / Remarks" class="text-xs font-semibold text-slate-700 uppercase tracking-wider" />
                        <textarea 
                            wire:model="notes" 
                            id="notes" 
                            rows="2" 
                            class="mt-1 block w-full rounded border-slate-300 text-xs text-slate-900 focus:border-slate-500 focus:ring-0"
                        ></textarea>
                        <x-input-error :messages="$errors->get('notes')" class="mt-1" />
                    </div>
                </div>

                <!-- Add Item Form Card (Reworked Product Selection) -->
                <div class="rounded-lg border border-slate-300 bg-white p-4 shadow-xs space-y-3">
                    <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800 border-b border-slate-200 pb-2">
                        2. Add Product to Batch
                    </h2>

                    <!-- Product Picker Section (Clean & Fast) -->
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider">Product</span>
                            @if (!$selectedProduct)
                                <button wire:click="openPickerModal" type="button" class="text-[11px] font-semibold text-[#00a3cc] hover:underline">
                                    Browse Catalog &rarr;
                                </button>
                            @endif
                        </div>

                        @if ($selectedProduct)
                            <!-- Selected Product Info Panel (Clean & Uncluttered) -->
                            <div class="rounded-lg border border-slate-300 bg-white p-3 text-xs space-y-2 shadow-xs">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <span class="font-bold text-slate-900 text-xs block leading-snug truncate">{{ $selectedProduct->name }}</span>
                                        <div class="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-600 mt-1">
                                            <span class="font-mono font-semibold text-slate-700 bg-slate-100 px-1.5 py-0.5 rounded">{{ $selectedProduct->sku }}</span>
                                            <span>{{ $selectedProduct->brand?->name ?? 'No Brand' }}</span>
                                            <span class="text-slate-300">·</span>
                                            <span>{{ $selectedProduct->category?->name ?? '—' }}</span>
                                        </div>
                                    </div>
                                    <button wire:click="$set('productId', '')" type="button" class="text-xs font-semibold text-slate-500 hover:text-slate-800 hover:underline shrink-0">
                                        Change
                                    </button>
                                </div>
                                <div class="flex items-center justify-between border-t border-slate-100 pt-2 text-[11px]">
                                    <span class="text-slate-500">Current Stock:</span>
                                    <span class="font-bold tabular-nums text-slate-900">
                                        {{ number_format((float) ($selectedProduct->inventories->sum('quantity') ?? 0), 2) }} {{ $selectedProduct->packageUnit?->abbreviation ?? 'pcs' }}
                                    </span>
                                </div>
                            </div>
                        @else
                            <!-- Typeahead / Search Input with Autocomplete Results -->
                            <div class="relative">
                                <div class="relative">
                                    <input 
                                        wire:model.live.debounce.200ms="productSearch"
                                        id="product_search"
                                        type="search" 
                                        placeholder="Type SKU or product name to search..." 
                                        class="w-full rounded border-slate-300 text-xs pl-8 pr-8 py-1.5 text-slate-900 focus:border-slate-500 focus:ring-0"
                                    />
                                    <svg class="absolute left-2.5 top-2 h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                                    @if ($productSearch !== '')
                                        <button wire:click="$set('productSearch', '')" type="button" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600">
                                            <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    @endif
                                </div>

                                @if ($searchResults->isNotEmpty())
                                    <div class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-slate-300 bg-white shadow-xl divide-y divide-slate-100">
                                        @foreach ($searchResults as $result)
                                            <button 
                                                type="button" 
                                                wire:click="selectProduct({{ $result->id }})"
                                                class="w-full text-left px-3 py-2 text-xs hover:bg-slate-50 flex items-center justify-between transition"
                                            >
                                                <div class="min-w-0 pr-2">
                                                    <span class="font-bold text-slate-900 truncate block">{{ $result->name }}</span>
                                                    <div class="text-[11px] text-slate-500 tabular-nums">
                                                        <span class="font-mono font-semibold">SKU: {{ $result->sku }}</span> · {{ $result->brand?->name ?? 'No Brand' }}
                                                    </div>
                                                </div>
                                                <div class="text-right shrink-0">
                                                    <span class="tabular-nums font-semibold text-slate-700">{{ number_format((float) ($result->inventories->sum('quantity') ?? 0), 2) }}</span>
                                                    <span class="text-[10px] text-slate-400 block">{{ $result->packageUnit?->abbreviation ?? 'pcs' }}</span>
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (trim($productSearch) !== '')
                                    <div class="mt-1 text-[11px] text-slate-400 italic">
                                        No matching products found. <button wire:click="openPickerModal" type="button" class="text-[#00a3cc] underline">Browse all</button>
                                    </div>
                                @endif
                            </div>
                        @endif
                        <x-input-error :messages="$errors->get('productId')" class="mt-1" />
                    </div>

                    <!-- Quantity to Receive -->
                    <div>
                        <label for="quantity" class="text-xs font-semibold text-slate-700 uppercase tracking-wider block">
                            Received Quantity<span class="text-rose-500">*</span>
                        </label>
                        <div class="relative mt-1">
                            <x-text-input 
                                wire:model="quantity" 
                                id="quantity" 
                                type="number" 
                                step="0.01" 
                                min="0.01" 
                                max="999999.99"
                                inputmode="decimal"
                                x-on:keydown="if(['e','E','+','-'].includes($event.key)) $event.preventDefault()"
                                placeholder="e.g. 10" 
                                class="w-full text-xs tabular-nums text-slate-900 rounded border-slate-300 focus:border-slate-500 focus:ring-0" 
                            />
                            @if ($selectedProduct && $selectedProduct->packageUnit)
                                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3 text-xs font-semibold text-slate-400">
                                    {{ $selectedProduct->packageUnit->abbreviation }}
                                </span>
                            @endif
                        </div>
                        <x-input-error :messages="$errors->get('quantity')" class="mt-1" />
                    </div>


                    <button 
                        wire:click="addItem" 
                        type="button" 
                        class="w-full inline-flex items-center justify-center gap-1.5 rounded bg-[#00a3cc] px-4 py-2 text-xs font-semibold uppercase tracking-wider text-white shadow-xs hover:bg-[#008fb3] transition"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add to Staged Items
                    </button>
                </div>
            </div>

            <!-- Right Column: Staged Receiving Table (Dashboard Table Design) & Commit Action -->
            <div class="space-y-4">
                <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs flex flex-col h-full justify-between">
                    <div>
                        <!-- Header & Summary Bar -->
                        <div class="border-b border-slate-300 bg-slate-100 p-3 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800">3. Staged Items for Receiving</h2>
                            </div>
                            @if (count($items) > 0)
                                <button 
                                    wire:click="clearItems" 
                                    type="button" 
                                    class="mt-2 sm:mt-0 text-xs font-medium text-rose-700 hover:underline"
                                >
                                    Clear all items
                                </button>
                            @endif
                        </div>

                        <!-- Staged Items Grid Table (Dashboard Style) -->
                        <div class="overflow-x-auto w-full">
                            <table class="w-full border-collapse border border-slate-300 text-xs">
                                <thead>
                                    <tr class="border-b border-slate-300 bg-slate-100 text-left font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                        <th class="border border-slate-300 px-2.5 py-1.5 w-28 whitespace-nowrap">SKU</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 w-28 whitespace-nowrap">Brand</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5">Product Name</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Unit</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap">Current Stock</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap ">Receiving</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24 whitespace-nowrap font-bold text-slate-900">Projected</th>
                                        <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16 whitespace-nowrap">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200">
                                    @php
                                        $batchTotalQty = 0;
                                    @endphp
                                    @forelse ($items as $index => $item)
                                        @php
                                             $prod = $products->firstWhere('id', $item['product_id']);
                                             $currentQty = (float) ($prod?->inventories->sum('quantity') ?? 0);
                                             $addQty = (float) $item['quantity'];
                                             $projectedQty = $currentQty + $addQty;
                                             $batchTotalQty += $addQty;
                                        @endphp
                                        <tr class="hover:bg-slate-50 transition-colors" wire:key="staged-item-{{ $index }}">
                                            <td class="border border-slate-200 px-2.5 py-1.5 font-mono text-slate-700 whitespace-nowrap">
                                                {{ $prod?->sku ?? '—' }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                                {{ $prod?->brand?->name ?? 'No Brand' }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 break-words whitespace-normal" title="{{ $prod?->name }}">
                                                {{ $prod?->name ?? 'Unknown Product' }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                                {{ $prod?->packageUnit?->abbreviation ?? 'pcs' }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                                {{ number_format($currentQty, 2) }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">
                                                +{{ number_format($addQty, 2) }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                                {{ number_format($projectedQty, 2) }}
                                            </td>
                                            <td class="border border-slate-200 px-3 py-1.5 text-center whitespace-nowrap">
                                                <button 
                                                    wire:click="removeItem({{ $index }})" 
                                                    type="button" 
                                                    class="rounded border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-medium text-rose-700 hover:bg-rose-50 shadow-xs"
                                                    title="Remove item"
                                                >
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="8" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                                <div class="mx-auto flex flex-col items-center justify-center">
                                                    <svg class="h-8 w-8 text-slate-300 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                                    </svg>
                                                    <p class="font-medium text-slate-600 text-xs">No items staged yet.</p>
                                                    <p class="text-[11px] text-slate-400 mt-0.5">Select a product on the left, enter received quantity, and click "Add to Staged Items".</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Batch Summary & Commit Bar -->
                    @if (count($items) > 0)
                        <div class="border-t border-slate-300 bg-slate-50 p-4 sm:flex sm:items-center sm:justify-between space-y-3 sm:space-y-0">
                            <div class="flex items-center gap-6 text-xs text-slate-700">
                                <div>
                                    <span class="text-slate-500 uppercase tracking-wider text-[10px] block">Items in Batch:</span>
                                    <span class="font-bold text-slate-900 text-sm tabular-nums">{{ count($items) }} products</span>
                                </div>
                                <div>
                                    <span class="text-slate-500 uppercase tracking-wider text-[10px] block">Total Units to Receive:</span>
                                    <span class="font-bold text-emerald-700 text-sm tabular-nums">+{{ number_format($batchTotalQty, 2) }}</span>
                                </div>
                            </div>

                            <button 
                                wire:click="saveStockIn" 
                                type="button" 
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded bg-emerald-600 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-emerald-700 transition"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Commit &amp; Update Stock
                            </button>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        <!-- History Tab (Unified Card with Standardized Space-y-2 Spacing) -->
        <div class="space-y-2">
            <!-- Filter Controls Outside the Table Div (Aligned left, h-9 controls) -->
            <div class="flex flex-wrap items-center gap-2.5">
                <div class="w-64">
                    <input 
                        wire:model.live.debounce.300ms="historySearch"
                        type="search" 
                        placeholder="Search DR number, notes, staff, SKU..." 
                        class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                    />
                </div>
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">From:</span>
                    <input 
                        wire:model.live="historyDateFrom" 
                        type="date" 
                        title="From Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" 
                    />
                </div>
                <div class="flex items-center gap-1.5 text-xs text-slate-600">
                    <span class="font-semibold text-[11px] uppercase">To:</span>
                    <input 
                        wire:model.live="historyDateTo" 
                        type="date" 
                        title="To Date"
                        class="h-9 rounded border border-slate-300 px-2.5 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" 
                    />
                </div>
                @if ($historySearch !== '' || $historyDateFrom !== '' || $historyDateTo !== '')
                    <button 
                        wire:click="resetHistoryFilters" 
                        type="button" 
                        class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center"
                    >
                        Reset
                    </button>
                @endif
            </div>

            <!-- Showing X of N Items Indicator (Left) & Customizable Amount Per Page (Right) -->
            <div class="flex items-center justify-between text-xs text-slate-500">
                <div>
                    @if ($stockInHistory && $stockInHistory->total() > 0)
                        Showing <span class="font-medium text-slate-700">{{ $stockInHistory->firstItem() }}</span> to
                        <span class="font-medium text-slate-700">{{ $stockInHistory->lastItem() }}</span> of <span
                            class="font-medium text-slate-700">{{ $stockInHistory->total() }}</span> items
                    @else
                        Showing <span class="font-medium text-slate-700">0</span> items
                    @endif
                </div>
                <div class="flex items-center gap-1.5">
                    <label for="historyPerPage" class="text-xs text-slate-500">Show:</label>
                    <select wire:model.live="historyPerPage" id="historyPerPage"
                        class="h-8 w-16 rounded border border-slate-300 bg-white pl-2.5 pr-7 py-1 text-xs text-slate-700 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                        <option value="10">10</option>
                        <option value="15">15</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                    </select>
                    <span class="text-xs text-slate-500">items</span>
                </div>
            </div>

            <!-- Table Container (div contains ONLY table and bottom pagination) -->
            <div class="overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead>
                            <tr class="border-b border-slate-300 bg-slate-100 text-left font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">ID</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Delivery Receipt</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Received Date</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Received By</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-20">Items</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Total Quantity</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left">Notes</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-center w-16">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @forelse ($stockInHistory as $stockIn)
                                @php
                                    $totalBatchQty = $stockIn->items->sum('quantity');
                                @endphp
                                <tr 
                                    @contextmenu.prevent="contextMenu.openFromEvent($event, { id: {{ $stockIn->id }}, reference: '{{ addslashes($stockIn->reference ?? '') }}' })"
                                    class="hover:bg-slate-50 transition-colors cursor-default" 
                                    wire:key="stock-in-history-{{ $stockIn->id }}"
                                >
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center text-slate-500 tabular-nums font-mono">{{ $stockIn->id }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 font-semibold text-slate-900 whitespace-nowrap">{{ $stockIn->reference ?? '—' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800 tabular-nums whitespace-nowrap">{{ $stockIn->received_at->format('M d, Y') }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">{{ $stockIn->user?->name ?? 'System' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center tabular-nums text-slate-700">{{ $stockIn->items->count() }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">+{{ number_format($totalBatchQty, 2) }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-600 truncate max-w-xs">{{ $stockIn->notes ?? '—' }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-center whitespace-nowrap">
                                        <button 
                                            @click.stop="contextMenu.openFromButton($event, { id: {{ $stockIn->id }}, reference: '{{ addslashes($stockIn->reference ?? '') }}' })" 
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
                                    <td colspan="8" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                        <p class="font-medium text-xs">No stock-in records found matching your filters.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Flush and Flat Pagination -->
                @if ($stockInHistory && $stockInHistory->hasPages())
                    <div class="px-3 py-2 border-t border-slate-200 bg-white flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 text-xs">
                        <div>
                            <span class="text-slate-500">
                                Page <span class="font-medium text-slate-800">{{ $stockInHistory->currentPage() }}</span> of
                                <span class="font-medium text-slate-800">{{ $stockInHistory->lastPage() }}</span>
                            </span>
                        </div>
                        <div class="inline-flex items-center gap-1">
                            <button wire:click="previousPage" type="button" @disabled($stockInHistory->onFirstPage())
                                class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                                Prev
                            </button>

                            @php
                                $currentPage = $stockInHistory->currentPage();
                                $lastPage = $stockInHistory->lastPage();
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

                            <button wire:click="nextPage" type="button" @disabled(!$stockInHistory->hasMorePages())
                                class="px-2.5 py-1 text-xs font-medium rounded text-slate-600 hover:bg-slate-100 disabled:opacity-30 disabled:pointer-events-none transition">
                                Next
                            </button>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Catalog Product Picker Modal (No Giant Dropdown) -->
    @if ($showProductPickerModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs p-4 sm:p-6">
            <div class="w-full max-w-4xl rounded-lg border border-slate-300 bg-white shadow-xl text-xs overflow-hidden flex flex-col max-h-[85vh]">
                <div class="border-b border-slate-200 bg-slate-50 px-5 py-3.5 flex items-center justify-between">
                    <div>
                        <h2 class="font-heading font-bold text-sm uppercase tracking-wider text-slate-900">Select Product to Stock In</h2>
                        <p class="text-[11px] text-slate-500">Filter or search products and click "Select" to populate the stock-in form.</p>
                    </div>
                    <button wire:click="closePickerModal" type="button" class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-5 flex-1 flex flex-col space-y-3.5 overflow-hidden">
                    <!-- Filters -->
                    <div class="flex flex-wrap items-center gap-2.5">
                        <div class="flex-1 min-w-[200px]">
                            <input wire:model.live.debounce.250ms="pickerSearch" type="search" placeholder="Search SKU or product name..." class="w-full h-9 rounded border border-slate-300 px-3 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                        </div>
                        <div class="w-40">
                            <select wire:model.live="pickerBrandId" class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All Brands</option>
                                @foreach ($brands as $b)
                                    <option value="{{ $b->id }}">{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-44">
                            <select wire:model.live="pickerCategoryId" class="w-full h-9 rounded border border-slate-300 pl-2.5 pr-8 text-xs bg-white text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500">
                                <option value="">All Categories</option>
                                @foreach ($categories as $c)
                                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if ($pickerSearch !== '' || $pickerBrandId !== '' || $pickerCategoryId !== '')
                            <button wire:click="$set('pickerSearch', ''); $set('pickerBrandId', ''); $set('pickerCategoryId', '');" type="button"
                                class="h-9 px-2 text-xs text-[#00a3cc] hover:text-[#008fb3] underline font-medium inline-flex items-center">
                                Reset
                            </button>
                        @endif
                    </div>

                    <!-- Products Table -->
                    <div class="flex-1 overflow-y-auto rounded-lg border border-slate-300">
                        <table class="w-full border-collapse border border-slate-300 text-xs">
                            <thead class="bg-slate-100 font-semibold uppercase text-slate-700 text-[10px] tracking-wider sticky top-0 border-b border-slate-300">
                                <tr>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">SKU</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left w-28">Brand</th>
                                    <th class="border border-slate-300 px-3 py-2 text-left">Product Name</th>
                                    <th class="border border-slate-300 px-3 py-2 text-center w-16">Unit</th>
                                    <th class="border border-slate-300 px-3 py-2 text-right w-24">Current Stock</th>
                                    <th class="border border-slate-300 px-3 py-2 text-center w-20">Select</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200">
                                @forelse ($pickerProducts as $prod)
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="border border-slate-200 px-3 py-2 font-mono text-slate-700 whitespace-nowrap">{{ $prod->sku }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-slate-600 whitespace-nowrap">{{ $prod->brand?->name ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 font-semibold text-slate-900">{{ $prod->name }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-center text-slate-600 whitespace-nowrap">{{ $prod->packageUnit?->abbreviation ?? '—' }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-right tabular-nums font-semibold text-slate-800 whitespace-nowrap">{{ number_format((float) ($prod->inventories->sum('quantity') ?? 0), 2) }}</td>
                                        <td class="border border-slate-200 px-3 py-2 text-center whitespace-nowrap">
                                            <button wire:click="selectFromPicker({{ $prod->id }})" type="button" class="rounded bg-[#00a3cc] px-2.5 py-1 text-xs font-semibold text-white hover:bg-[#008fb3] shadow-xs transition">
                                                Select
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="p-8 text-center text-slate-400">No products match your filter criteria.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Stock In Detail Inspection Modal -->
    @if ($viewingStockInId && $viewingStockIn)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-3xl rounded-lg border border-slate-300 bg-white shadow-xl overflow-hidden text-xs">
                <div class="border-b border-slate-300 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold uppercase tracking-wider text-slate-900">
                            Batch {{ $viewingStockIn->id }} Details
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            Received on {{ $viewingStockIn->received_at->format('F d, Y') }} by {{ $viewingStockIn->user?->name ?? 'System' }}
                            @if ($viewingStockIn->reference)
                                · Delivery Receipt: <strong class="text-slate-700">{{ $viewingStockIn->reference }}</strong>
                            @endif
                        </p>
                    </div>
                    <button 
                        wire:click="closeViewStockIn" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white p-1 text-slate-400 hover:text-slate-600 transition"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                @if ($viewingStockIn->notes)
                    <div class="border-b border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-700">
                        <span class="font-bold">Note:</span> {{ $viewingStockIn->notes }}
                    </div>
                @endif

                <div class="max-h-96 overflow-y-auto p-4">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead>
                            <tr class="border-b border-slate-300 bg-slate-100 text-left font-semibold uppercase text-slate-700 text-[10px] tracking-wider">
                                <th class="border border-slate-300 px-3 py-1.5 w-28 whitespace-nowrap">SKU</th>
                                <th class="border border-slate-300 px-3 py-1.5 w-28 whitespace-nowrap">Brand</th>
                                <th class="border border-slate-300 px-3 py-1.5">Product Name</th>
                                <th class="border border-slate-300 px-3 py-1.5 text-center w-16 whitespace-nowrap">Unit</th>
                                <th class="border border-slate-300 px-3 py-1.5 text-right w-28 whitespace-nowrap">Quantity Received</th>
                                <th class="border border-slate-300 px-3 py-1.5 text-right w-24 whitespace-nowrap">Unit Cost</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($viewingStockIn->items as $item)
                                <tr class="hover:bg-slate-50">
                                    <td class="border border-slate-200 px-3 py-1.5 font-mono text-slate-600 whitespace-nowrap">
                                        {{ $item->product?->sku ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-1.5 text-slate-700 whitespace-nowrap">
                                        {{ $item->product?->brand?->name ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-1.5 font-medium text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $item->product?->name }}">
                                        {{ $item->product?->name ?? 'Product' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-1.5 text-center text-slate-600 whitespace-nowrap">
                                        {{ $item->product?->packageUnit?->abbreviation ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">
                                        +{{ number_format((float) $item->quantity, 2) }}
                                    </td>
                                    <td class="border border-slate-200 px-3 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ (float) $item->unit_cost > 0 ? Currency::format((float) $item->unit_cost) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-300 bg-slate-100 px-4 py-3 flex justify-between items-center">
                    <div class="text-xs text-slate-600 tabular-nums">
                        Total Lines: <span class="font-bold text-slate-900">{{ $viewingStockIn->items->count() }}</span> | Total Packages: <span class="font-bold text-emerald-700">{{ number_format($viewingStockIn->items->sum('quantity'), 2) }}</span>
                    </div>
                    <button 
                        wire:click="closeViewStockIn" 
                        type="button" 
                        class="rounded border border-slate-300 bg-white px-3.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

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
        class="w-44 rounded-lg border border-slate-200 bg-white py-1 shadow-xl ring-1 ring-black/5"
        style="display: none;"
    >
        <button 
            @click="if (contextMenu.item) { $wire.viewStockIn(contextMenu.item.id); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            <span>View Details</span>
        </button>
        <button 
            @click="if (contextMenu.item && contextMenu.item.reference) { navigator.clipboard.writeText(contextMenu.item.reference); contextMenu.close(); }" 
            type="button" 
            class="flex items-center gap-2 w-full px-3 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100 transition-colors"
        >
            <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
            <span>Copy Reference</span>
        </button>
    </div>
</div>
