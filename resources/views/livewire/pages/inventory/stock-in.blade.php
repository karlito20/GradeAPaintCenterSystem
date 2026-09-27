<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockIn;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\WithPagination;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] class extends Component {
    use WithPagination;

    public string $activeTab = 'receive'; // 'receive' or 'history'

    // Form inputs
    public string $receivedAt = '';
    public string $notes = '';
    public string $productId = '';
    public string $quantity = '';
    public string $unitCost = '';

    // Search helper for product selector
    public string $productSearch = '';

    // Staged items
    public array $items = [];

    // History filters & view modal
    public string $historySearch = '';
    public string $historyDateFrom = '';
    public string $historyDateTo = '';
    public ?int $viewingStockInId = null;

    public function mount(): void
    {
        $this->receivedAt = now()->toDateString();
    }

    public function updatedProductSearch(): void
    {
        // If user selects via click or typing
    }

    public function selectProduct(int $id): void
    {
        $this->productId = (string) $id;
        $this->productSearch = '';
    }

    public function addItem(): void
    {
        $validated = $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unitCost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $prodId = (int) $validated['productId'];
        $qty = (float) $validated['quantity'];
        $cost = !empty($this->unitCost) ? (float) $this->unitCost : 0.00;

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
            'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function (): void {
            $stockIn = StockIn::create([
                'user_id' => auth()->id(),
                'received_at' => $this->receivedAt,
                'notes' => trim($this->notes) !== '' ? trim($this->notes) : null,
            ]);

            $totalQuantity = 0;
            foreach ($this->items as $item) {
                $qty = (float) $item['quantity'];
                $totalQuantity += $qty;
                $cost = isset($item['unit_cost']) && $item['unit_cost'] !== '' ? (float) $item['unit_cost'] : 0.00;

                $inventory = Inventory::query()
                    ->where('product_id', $item['product_id'])
                    ->lockForUpdate()
                    ->firstOrCreate(['product_id' => $item['product_id']], ['quantity' => 0]);

                $before = (float) $inventory->quantity;
                $after = $before + $qty;

                $inventory->quantity = $after;
                $inventory->save();

                $stockIn->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $qty,
                    'unit_cost' => $cost > 0 ? $cost : 0.00,
                ]);

                $refText = 'Stock-in #' . $stockIn->id;
                if (!empty($stockIn->notes)) {
                    $refText .= ' (' . Str::limit($stockIn->notes, 30) . ')';
                }

                InventoryMovement::create([
                    'product_id' => $item['product_id'],
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
                    'items_count' => count($this->items),
                    'total_quantity' => $totalQuantity,
                    'notes' => $this->notes,
                ],
            ]);
        });

        $this->reset(['productId', 'quantity', 'unitCost', 'items', 'notes', 'productSearch']);
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

    public function render(): mixed
    {
        $products = Product::query()
            ->with(['brand', 'packageUnit', 'inventory'])
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
            })->take(8);
        }

        // Stock In History query
        $historyQuery = StockIn::query()
            ->with(['user', 'items.product.packageUnit', 'items.product.brand'])
            ->when($this->historySearch !== '', function ($q) {
                $term = '%' . trim($this->historySearch) . '%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('notes', 'like', $term)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $term))
                        ->orWhereHas('items.product', fn ($p) => $p->where('name', 'like', $term)->orWhere('sku', 'like', $term));
                });
            })
            ->when($this->historyDateFrom !== '', fn ($q) => $q->whereDate('received_at', '>=', $this->historyDateDateFrom ?? $this->historyDateFrom))
            ->when($this->historyDateTo !== '', fn ($q) => $q->whereDate('received_at', '<=', $this->historyDateTo))
            ->latest('received_at')
            ->latest('id');

        $stockInHistory = $this->activeTab === 'history' ? $historyQuery->paginate(15) : null;
        $viewingStockIn = $this->viewingStockInId ? StockIn::with(['user', 'items.product.packageUnit', 'items.product.brand'])->find($this->viewingStockInId) : null;

        return view('livewire.pages.inventory.stock-in', [
            'products' => $products,
            'selectedProduct' => $selectedProduct,
            'searchResults' => $searchResults,
            'stockInHistory' => $stockInHistory,
            'viewingStockIn' => $viewingStockIn,
        ]);
    }
}; ?>
<div class="space-y-4 w-full min-w-0">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pb-2.5 border-b border-slate-300">
        <div>
            <h1 class="font-heading text-xl font-bold tracking-tight text-slate-900">Stock In</h1>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('inventory.movements') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                </svg>
                Movements
            </a>
            <a href="{{ route('reports.inventory') }}" class="inline-flex items-center rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50 transition">
                <svg class="mr-1.5 h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                Inventory Report
            </a>
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
                    <span class="ml-1.5 rounded border border-[#00a3cc] bg-[#00a3cc] text-white px-1.5 py-0.2 text-[10px] tabular-nums">
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
                <!-- Batch Info Card -->
                <div class="rounded border border-slate-300 bg-white p-4 shadow-xs space-y-3">
                    <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        1. Batch Date
                    </h2>

                    <div>
                        <x-input-label for="receivedAt" value="Received Date" class="text-xs" />
                        <x-text-input 
                            wire:model="receivedAt" 
                            id="receivedAt" 
                            type="date" 
                            class="mt-1 block w-full rounded border-slate-300 text-xs tabular-nums focus:border-[#00a3cc] focus:ring-[#00a3cc]" 
                            required 
                        />
                        <x-input-error :messages="$errors->get('receivedAt')" class="mt-1" />
                    </div>
                </div>

                <!-- Add Item Form Card -->
                <div class="rounded border border-slate-300 bg-white p-4 shadow-xs space-y-3">
                    <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800 flex items-center gap-1.5">
                        <svg class="h-3.5 w-3.5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        2. Add Product
                    </h2>

                    <!-- Product search / select combo -->
                    <div class="relative">
                        <x-input-label for="product_search" value="Quick Product Search / SKU" class="text-xs" />
                        <div class="relative mt-1">
                            <input 
                                wire:model.live.debounce.250ms="productSearch"
                                id="product_search"
                                type="text" 
                                placeholder="Type SKU or product name..." 
                                class="w-full rounded border-slate-300 text-xs pr-8 text-slate-900 focus:border-slate-500 focus:ring-0"
                            />
                            @if ($productSearch !== '')
                                <button wire:click="$set('productSearch', '')" type="button" class="absolute inset-y-0 right-0 flex items-center pr-2.5 text-slate-400 hover:text-slate-600">
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            @endif
                        </div>

                        <!-- Dropdown Search Results -->
                        @if ($searchResults->isNotEmpty())
                            <div class="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded border border-slate-300 bg-white shadow-lg">
                                @foreach ($searchResults as $result)
                                    <button 
                                        type="button" 
                                        wire:click="selectProduct({{ $result->id }})"
                                        class="w-full text-left px-3 py-2 text-xs hover:bg-slate-50 border-b border-slate-100 last:border-0 flex items-center justify-between"
                                    >
                                        <div>
                                            <span class="font-bold text-slate-900">{{ $result->name }}</span>
                                            <div class="text-[11px] text-slate-500 tabular-nums">
                                                SKU: {{ $result->sku }} · {{ $result->brand?->name ?? 'No Brand' }}
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <span class="tabular-nums text-slate-700">{{ (float) ($result->inventory?->quantity ?? 0) }} {{ $result->packageUnit?->abbreviation }}</span>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <!-- Direct Dropdown Selector -->
                    <div>
                        <x-input-label for="productId" value="Selected Product" class="text-xs" />
                        <select 
                            wire:model.live="productId" 
                            id="productId"
                            class="mt-1 block w-full rounded border-slate-300 text-xs text-slate-900 focus:border-slate-500 focus:ring-0"
                        >
                            <option value="">-- Choose a Product --</option>
                            @foreach ($products as $p)
                                <option value="{{ $p->id }}">{{ $p->sku }} · {{ $p->name }} ({{ (float) ($p->inventory?->quantity ?? 0) }} {{ $p->packageUnit?->abbreviation }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('productId')" class="mt-1" />
                    </div>

                    @if ($selectedProduct)
                        <div class="rounded bg-slate-50 p-2.5 text-xs border border-slate-200 space-y-1">
                            <div class="flex justify-between">
                                <span class="text-slate-500">Brand / Category:</span>
                                <span class="font-medium text-slate-800">{{ $selectedProduct->brand?->name ?? '—' }} · {{ $selectedProduct->category?->name ?? '—' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Package Unit:</span>
                                <span class="font-medium text-slate-800">{{ $selectedProduct->packageUnit?->name ?? 'Unit' }} ({{ $selectedProduct->packageUnit?->abbreviation ?? '—' }})</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-slate-500">Current Stock:</span>
                                <span class="font-bold tabular-nums text-slate-900">{{ (float) ($selectedProduct->inventory?->quantity ?? 0) }} {{ $selectedProduct->packageUnit?->abbreviation }}</span>
                            </div>
                        </div>
                    @endif

                    <!-- Quantity to Receive -->
                    <div>
                        <x-input-label for="quantity" value="Received Quantity (Packages / Cans)" class="text-xs" />
                        <div class="relative mt-1">
                            <x-text-input 
                                wire:model="quantity" 
                                id="quantity" 
                                type="number" 
                                step="0.001" 
                                min="0.001" 
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

                    <!-- Unit Cost (Optional) -->
                    <div>
                        <x-input-label for="unitCost" value="Unit Cost (Optional, ₱)" class="text-xs" />
                        <div class="relative mt-1">
                            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                <span class="text-xs text-slate-400">₱</span>
                            </div>
                            <x-text-input 
                                wire:model="unitCost" 
                                id="unitCost" 
                                type="number" 
                                step="0.01" 
                                min="0" 
                                placeholder="0.00" 
                                class="w-full pl-7 text-xs tabular-nums text-slate-900 rounded border-slate-300 focus:border-[#00a3cc] focus:ring-0" 
                            />
                        </div>
                        <x-input-error :messages="$errors->get('unitCost')" class="mt-1" />
                    </div>

                    <button 
                        wire:click.prevent="addItem" 
                        type="button"
                        class="w-full rounded bg-[#00a3cc] px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#008fb3] transition flex items-center justify-center gap-1.5"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                        </svg>
                        Add Product
                    </button>
                </div>
            </div>

            <!-- Right Column: Staged Receiving Table & Commit Action -->
            <div class="space-y-4">
                <div class="rounded border border-slate-300 bg-white shadow-xs overflow-hidden flex flex-col h-full justify-between">
                    <div>
                        <!-- Header & Summary Bar -->
                        <div class="border-b border-slate-300 bg-slate-100 p-3 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <h2 class="font-heading text-xs font-bold uppercase tracking-wider text-slate-800">3. Staged Items</h2>
                                <p class="text-[11px] text-slate-500">Review line items below before committing to inventory.</p>
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

                        <!-- Staged Items Grid Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse border border-slate-300 text-xs">
                                <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                                    <tr>
                                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand</th>
                                        <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left">Product Name</th>
                                        <th scope="col" class="border border-slate-300 px-2 py-1.5 text-left w-28">SKU</th>
                                        <th scope="col" class="border border-slate-300 px-1.5 py-1.5 text-left w-16">Unit</th>
                                        <th scope="col" class="border border-slate-300 px-2 py-1.5 text-right w-24">Current Stock</th>
                                        <th scope="col" class="border border-slate-300 px-2 py-1.5 text-right font-bold text-emerald-800 w-24">+ Receiving</th>
                                        <th scope="col" class="border border-slate-300 px-2 py-1.5 text-right font-bold text-slate-900 w-24">Projected</th>
                                        <th scope="col" class="border border-slate-300 px-2 py-1.5 text-right w-24">Unit Cost</th>
                                        <th scope="col" class="border border-slate-300 px-1.5 py-1.5 text-center w-16">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @php
                                        $batchTotalQty = 0;
                                        $batchTotalCost = 0;
                                    @endphp
                                    @forelse ($items as $index => $item)
                                        @php
                                             $prod = $products->firstWhere('id', $item['product_id']);
                                             $currentQty = (float) ($prod?->inventory?->quantity ?? 0);
                                             $addQty = (float) $item['quantity'];
                                             $projectedQty = $currentQty + $addQty;
                                             $unitCost = (float) ($item['unit_cost'] ?? 0);
                                             $batchTotalQty += $addQty;
                                             $batchTotalCost += ($addQty * $unitCost);
                                        @endphp
                                        <tr class="hover:bg-slate-50 transition-colors" wire:key="staged-item-{{ $index }}">
                                            <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                                {{ $prod?->brand?->name ?? 'No Brand' }}
                                            </td>
                                            <td class="border border-slate-200 px-2.5 py-1.5 font-bold text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $prod?->name }}">
                                                {{ $prod?->name ?? 'Unknown Product' }}
                                            </td>
                                            <td class="border border-slate-200 px-2 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                                {{ $prod?->sku ?? '—' }}
                                            </td>
                                            <td class="border border-slate-200 px-1.5 py-1.5 text-slate-600 whitespace-nowrap">
                                                {{ $prod?->packageUnit?->abbreviation ?? 'pcs' }}
                                            </td>
                                            <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums text-slate-500 whitespace-nowrap">
                                                {{ number_format($currentQty, 3) }}
                                            </td>
                                            <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">
                                                +{{ number_format($addQty, 3) }}
                                            </td>
                                            <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums font-bold text-slate-900 whitespace-nowrap">
                                                {{ number_format($projectedQty, 3) }}
                                            </td>
                                            <td class="border border-slate-200 px-2 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                                {{ $unitCost > 0 ? Currency::format($unitCost) : '—' }}
                                            </td>
                                            <td class="border border-slate-200 px-1.5 py-1.5 text-center whitespace-nowrap">
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
                                            <td colspan="9" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                                <div class="mx-auto flex flex-col items-center justify-center">
                                                    <svg class="h-8 w-8 text-slate-300 mb-1" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                                    </svg>
                                                    <p class="font-medium text-slate-600 text-xs">No items staged yet.</p>
                                                    <p class="text-[11px] text-slate-400 mt-0.5">Select a product on the left, enter received quantity, and click "Add Product".</p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Batch Summary & Commit Action -->
                    <div class="border-t border-slate-300 bg-slate-100 p-3.5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-5 text-xs">
                                <div>
                                    <span class="block text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Total Lines</span>
                                    <span class="font-bold tabular-nums text-slate-900">{{ count($items) }} {{ Str::plural('item', count($items)) }}</span>
                                </div>
                                <div>
                                    <span class="block text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Total Quantity</span>
                                    <span class="font-bold tabular-nums text-emerald-700">{{ number_format($batchTotalQty, 3) }}</span>
                                </div>
                                @if ($batchTotalCost > 0)
                                    <div>
                                        <span class="block text-[10px] uppercase tracking-wider text-slate-500 font-semibold">Total Cost</span>
                                        <span class="font-bold tabular-nums text-slate-900">{{ Currency::format($batchTotalCost) }}</span>
                                    </div>
                                @endif
                            </div>

                            <button 
                                wire:click="saveStockIn" 
                                wire:confirm="Are you sure you want to commit this stock-in batch? This will immediately update inventory quantities and log an immutable audit movement."
                                type="button"
                                @disabled(count($items) === 0)
                                class="inline-flex items-center justify-center rounded bg-[#00a3cc] px-5 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-sm hover:bg-[#008fb3] transition disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg class="mr-1.5 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Receive Stock
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @else
        <!-- Stock In History Tab with Left Filter Panel -->
        <div class="flex flex-col lg:flex-row gap-4 items-start">
            <!-- Left Filter Panel -->
            <aside class="w-full lg:w-56 shrink-0 rounded-lg border border-slate-300 bg-white p-3.5 shadow-xs space-y-3">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Filter History</span>
                    <button wire:click="$set('historySearch', ''); $set('historyDateFrom', ''); $set('historyDateTo', '');" type="button" class="text-[11px] text-slate-500 hover:text-slate-800 underline">
                        Reset
                    </button>
                </div>

                <!-- Search -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">Search Keyword</label>
                    <input 
                        wire:model.live.debounce.300ms="historySearch"
                        type="search" 
                        placeholder="Notes, staff, SKU..." 
                        class="w-full rounded border border-slate-300 px-2.5 py-1 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500"
                    />
                </div>

                <!-- Date From -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">From Date</label>
                    <input wire:model.live="historyDateFrom" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>

                <!-- Date To -->
                <div>
                    <label class="block text-[11px] font-semibold text-slate-600 mb-1">To Date</label>
                    <input wire:model.live="historyDateTo" type="date" class="w-full rounded border border-slate-300 px-2 py-1 text-xs text-slate-900 focus:border-slate-500 focus:ring-1 focus:ring-slate-500" />
                </div>
            </aside>

            <!-- History Grid Table Area -->
            <div class="flex-1 min-w-0 w-full overflow-hidden rounded-lg border border-slate-300 bg-white shadow-xs">
                <div class="overflow-x-auto w-full">
                    <table class="w-full border-collapse border border-slate-300 text-xs">
                        <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                            <tr>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-20">Batch ID</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-28">Received Date</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-left w-36">Received By</th>
                                <th scope="col" class="border border-slate-300 px-2 py-1.5 text-center w-24">Items</th>
                                <th scope="col" class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Total Quantity</th>
                                <th scope="col" class="border border-slate-300 px-2 py-1.5 text-center w-28">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($stockInHistory as $stockIn)
                                @php
                                    $totalBatchQty = $stockIn->items->sum('quantity');
                                @endphp
                                <tr class="hover:bg-slate-50 transition-colors">
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-500 tabular-nums">#{{ $stockIn->id }}</td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-900 tabular-nums whitespace-nowrap">
                                        {{ $stockIn->received_at->format('Y-m-d') }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-800 whitespace-nowrap">
                                        {{ $stockIn->user?->name ?? 'System' }}
                                    </td>
                                    <td class="border border-slate-200 px-2 py-1.5 text-center tabular-nums text-slate-700">
                                        {{ $stockIn->items->count() }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">
                                        +{{ number_format($totalBatchQty, 3) }}
                                    </td>
                                    <td class="border border-slate-200 px-2 py-1.5 text-center whitespace-nowrap">
                                        <button 
                                            wire:click="viewStockIn({{ $stockIn->id }})" 
                                            type="button"
                                            class="rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-xs transition"
                                        >
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="border border-slate-200 px-6 py-12 text-center text-slate-400">
                                        <p class="font-medium text-xs">No stock-in records found.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($stockInHistory && $stockInHistory->hasPages())
                    <div class="border-t border-slate-300 bg-slate-50 px-3 py-2">
                        {{ $stockInHistory->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Stock In Detail Inspection Modal -->
    @if ($viewingStockInId && $viewingStockIn)
        <div class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-slate-900/60 p-4 backdrop-blur-xs">
            <div class="w-full max-w-3xl rounded border border-slate-300 bg-white shadow-xl overflow-hidden text-xs">
                <div class="border-b border-slate-300 bg-slate-100 p-3.5 flex items-center justify-between">
                    <div>
                        <h3 class="font-heading text-sm font-bold uppercase tracking-wider text-slate-900">
                            Batch #{{ $viewingStockIn->id }} Details
                        </h3>
                        <p class="text-[11px] text-slate-500">
                            Received on {{ $viewingStockIn->received_at->format('F d, Y') }} by {{ $viewingStockIn->user?->name ?? 'System' }}
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
                        <thead class="bg-slate-100 uppercase text-[10px] font-semibold text-slate-700">
                            <tr>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left w-24">Brand</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-left">Product Name</th>
                                <th class="border border-slate-300 px-2 py-1.5 text-left w-28">SKU</th>
                                <th class="border border-slate-300 px-1.5 py-1.5 text-left w-16">Unit</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-28">Quantity Received</th>
                                <th class="border border-slate-300 px-2.5 py-1.5 text-right w-24">Unit Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($viewingStockIn->items as $item)
                                <tr class="hover:bg-slate-50">
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-slate-700 whitespace-nowrap">
                                        {{ $item->product?->brand?->name ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 font-medium text-slate-900 min-w-[200px] max-w-md break-words whitespace-normal" title="{{ $item->product?->name }}">
                                        {{ $item->product?->name ?? 'Product' }}
                                    </td>
                                    <td class="border border-slate-200 px-2 py-1.5 tabular-nums text-slate-600 whitespace-nowrap">
                                        {{ $item->product?->sku ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-1.5 py-1.5 text-slate-600 whitespace-nowrap">
                                        {{ $item->product?->packageUnit?->abbreviation ?? '—' }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums font-bold text-emerald-700 whitespace-nowrap">
                                        +{{ number_format((float) $item->quantity, 3) }}
                                    </td>
                                    <td class="border border-slate-200 px-2.5 py-1.5 text-right tabular-nums text-slate-700 whitespace-nowrap">
                                        {{ (float) $item->unit_cost > 0 ? Currency::format((float) $item->unit_cost) : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-300 bg-slate-100 px-4 py-3 flex justify-between items-center">
                    <div class="text-xs text-slate-600 tabular-nums">
                        Total Lines: <span class="font-bold text-slate-900">{{ $viewingStockIn->items->count() }}</span> | Total Packages: <span class="font-bold text-emerald-700">{{ number_format($viewingStockIn->items->sum('quantity'), 3) }}</span>
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
</div>

